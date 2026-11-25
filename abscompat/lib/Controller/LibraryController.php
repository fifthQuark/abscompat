<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\HiddenSeries;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\ListService;
use OCA\AbsCompat\Service\ProgressService;
use OCA\AbsCompat\Service\Serializer;
use OCA\AbsCompat\Service\TokenService;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class LibraryController extends AbsBaseController {
	public function __construct(
		IRequest $request,
		TokenService $tokens,
		LibraryService $library,
		private ProgressService $progress,
		private Catalog $catalog,
		private HiddenSeries $hiddenSeries,
		private ListService $lists,
	) {
		parent::__construct($request, $tokens, $library);
	}

	private function stats(string $uid, string $libId): array {
		$rows = $this->catalog->visibleRows($uid, $libId);
		$tracks = $this->library->getTrackRowsFor(array_column($rows, 'id'));
		return [
			'totalItems' => count($rows),
			'totalAuthors' => count(array_unique(array_merge([], ...array_map(static fn ($r) => Serializer::splitNames($r['author']), $rows)))),
			'totalGenres' => 0,
			'totalSize' => array_sum(array_map(static fn ($r) => (int)$r['size'], $rows)),
			'totalDuration' => array_sum(array_map(static fn ($r) => (float)$r['duration'], $rows)),
			'numAudioFiles' => array_sum(array_map('count', $tracks)),
		];
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries')]
	public function libraries(): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		// No stale rescan here: CrossPoint Sync lists libraries before every match and search, and must
		// stay fast. Apps go on to the items or the home shelves, which do refresh.
		$this->ensureScanned($uid);
		$withStats = str_contains((string)$this->request->getParam('include'), 'stats');
		$out = [];
		foreach ($this->library->getLibraries($uid) as $i => $lib) {
			$out[] = Serializer::library($lib, $i + 1, $withStats ? $this->stats($uid, $lib['id']) : null);
		}
		return new JSONResponse(['libraries' => $out]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}')]
	public function library(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$libs = $this->library->getLibraries($uid);
		foreach ($libs as $i => $lib) {
			if ($lib['id'] !== $id) {
				continue;
			}
			$json = Serializer::library($lib, $i + 1);
			if (!str_contains((string)$this->request->getParam('include'), 'filterdata')) {
				return new JSONResponse($json);
			}
			return new JSONResponse([
				'library' => $json,
				'filterdata' => $this->filterData($uid, $id),
				'issues' => 0,
				'numUserPlaylists' => count($this->lists->all($uid, ListService::PLAYLIST, $id)),
			]);
		}
		return $this->notFound('Library not found');
	}

	private function filterData(string $uid, string $libId): array {
		$this->ensureScanned($uid);
		$rows = $this->catalog->visibleRows($uid, $libId);
		$names = static fn (string $group) => array_map('strval', array_keys(Catalog::count($rows, $group)));
		$decades = [];
		foreach ($rows as $r) {
			if (preg_match('/^\d{4}$/', (string)$r['published_year'])) {
				$decades[substr((string)$r['published_year'], 0, 3) . '0'] = true;
			}
		}
		ksort($decades);
		$authors = array_map(static fn ($a) => ['id' => Serializer::authorId($a), 'name' => $a], $names('authors'));
		$series = array_map(static fn ($s) => ['id' => Serializer::seriesId($s), 'name' => $s], $names('series'));
		return [
			'authors' => $authors,
			'genres' => $names('genres'),
			'tags' => $names('tags'),
			'series' => $series,
			'narrators' => $names('narrators'),
			'languages' => $names('languages'),
			'publishers' => $names('publishers'),
			'publishedDecades' => array_map('strval', array_keys($decades)),
			'bookCount' => count($rows),
			'authorCount' => count($authors),
			'seriesCount' => count($series),
			'podcastCount' => 0,
			'numIssues' => 0,
			'loadedAt' => (int)(microtime(true) * 1000),
		];
	}

	/** ABS filters look like "group.base64value". */
	private function applyFilter(array $rows, string $filter, array $prog): array {
		if ($filter === '' || !str_contains($filter, '.')) {
			return $rows;
		}
		[$group, $enc] = explode('.', $filter, 2);
		$val = base64_decode(rawurldecode(trim($enc)), true);
		$val = $val === false ? $enc : trim($val);
		return array_values(array_filter($rows, static function ($r) use ($group, $val, $prog) {
			$p = $prog[(string)$r['item_key']] ?? null;
			$has = static fn (string $g, ?callable $id = null) => in_array(mb_strtolower($val), array_map(static fn ($t) => mb_strtolower((string)$t), Catalog::terms($r, $g)), true)
				|| ($id !== null && in_array($val, array_map($id, Catalog::terms($r, $g)), true));
			return match ($group) {
				'series' => $val === 'no-series' ? Serializer::seriesList($r) === [] : $has('series', [Serializer::class, 'seriesId']),
				'authors' => $has('authors', [Serializer::class, 'authorId']),
				'narrators', 'genres', 'tags', 'publishers', 'languages' => $has($group),
				'publishedDecades' => str_starts_with((string)$r['published_year'], substr($val, 0, 3)),
				'missing' => match ($val) {
					'series', 'authors', 'narrators', 'genres', 'tags' => Catalog::terms($r, $val) === [],
					'publisher', 'language' => Catalog::terms($r, $val . 's') === [],
					'cover' => !Serializer::hasCover($r),
					'description' => (string)$r['description'] === '',
					'subtitle' => (string)$r['subtitle'] === '',
					'publishedYear' => (string)$r['published_year'] === '',
					'isbn', 'asin' => !isset(Serializer::extra($r)[$val]),
					default => true,
				},
				'progress' => match ($val) {
					'finished' => $p !== null && $p['is_finished'],
					'not-started' => $p === null,
					'not-finished' => $p === null || !$p['is_finished'],
					'in-progress' => $p !== null && !$p['is_finished'],
					default => true,
				},
				'ebooks' => match ($val) {
					'ebook' => $r['ebook_file_id'] !== null,
					'no-ebook' => $r['ebook_file_id'] === null,
					'supplementary' => count(Serializer::ebooks($r)) > ($r['ebook_file_id'] !== null ? 1 : 0),
					'no-supplementary' => count(Serializer::ebooks($r)) <= ($r['ebook_file_id'] !== null ? 1 : 0),
					default => true,
				},
				'tracks' => match ($val) {
					'none' => Serializer::isEbookOnly($r),
					'single', 'multi' => !Serializer::isEbookOnly($r),
					default => true,
				},
				default => true,
			};
		}));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/items')]
	public function items(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $id) === null) {
			return $this->notFound('Library not found');
		}
		$this->refreshLibrary($uid);
		$limit = max(0, (int)$this->request->getParam('limit', 0));
		$page = max(0, (int)$this->request->getParam('page', 0));
		$sort = (string)$this->request->getParam('sort', 'media.metadata.title');
		$desc = in_array((string)$this->request->getParam('desc', '0'), ['1', 'true'], true);
		$filter = (string)$this->request->getParam('filter', '');
		$prog = $this->progress->allForUser($uid);

		$rows = $this->applyFilter($this->catalog->visibleRows($uid, $id), $filter, $prog);
		if (str_starts_with($filter, 'series.')) {
			$want = (string)base64_decode(rawurldecode(trim(substr($filter, 7))), true);
			foreach ($rows as &$r) {
				foreach (Serializer::seriesList($r) as $in) {
					if (Serializer::seriesId($in['name']) === $want || mb_strtolower($in['name']) === mb_strtolower($want)) {
						$r['in_series'] = $in;
					}
				}
			}
			unset($r);
		}
		if (str_starts_with($filter, 'series.') && !$this->request->getParam('sort')) {
			$sort = 'sequence';
		}
		$rows = Catalog::sortRows($rows, $sort, $desc, $prog);
		$collapse = in_array((string)$this->request->getParam('collapseseries', '0'), ['1', 'true'], true) && !str_starts_with($filter, 'series.');
		$collapsed = [];
		if ($collapse) {
			// One entry per series: its first book, carrying collapsedSeries (as ABS does).
			$bySeries = [];
			$kept = [];
			foreach ($rows as $r) {
				$in = Serializer::seriesList($r)[0] ?? null;
				if ($in === null) {
					$kept[] = $r;
					continue;
				}
				$bySeries[Serializer::seriesId($in['name'])][] = $r + ['in_series' => $in];
			}
			foreach ($bySeries as $sid => $members) {
				$members = Catalog::sortRows($members, 'sequence', false);
				$collapsed[$sid] = [
					'id' => $sid,
					'libraryId' => $id,
					'name' => $members[0]['in_series']['name'],
					'nameIgnorePrefix' => Serializer::ignorePrefix($members[0]['in_series']['name']),
					'numBooks' => count($members),
					'libraryItemIds' => array_map(static fn ($m) => (string)$m['item_key'], $members),
					'sequence' => $members[0]['in_series']['seq'],
				];
				$kept[] = count($members) > 1 ? ['sort_title' => $members[0]['in_series']['name']] + $members[0] : $members[0];
			}
			$rows = Catalog::sortRows($kept, $sort, $desc, $prog);
		}
		$total = count($rows);
		if ($limit > 0) {
			$rows = array_slice($rows, $page * $limit, $limit);
		}
		$results = str_starts_with($filter, 'series.') ? $this->catalog->minifiedInSeries($uid, $rows) : $this->catalog->minified($uid, $rows);
		if ($collapse) {
			foreach ($results as $i => &$r) {
				$name = $rows[$i]['in_series']['name'] ?? '';
				if ($name !== '' && ($cs = $collapsed[Serializer::seriesId($name)] ?? null) !== null && $cs['numBooks'] > 1) {
					$r['collapsedSeries'] = $cs;
				}
			}
			unset($r);
		}
		if (str_contains((string)$this->request->getParam('include'), 'progress')) {
			foreach ($results as &$r) {
				$p = $prog[$r['id']] ?? null;
				$r['userMediaProgress'] = $p !== null ? ProgressService::toJson($p, $uid) : null;
			}
			unset($r);
		}
		return new JSONResponse([
			'results' => $results,
			'total' => $total,
			'limit' => $limit,
			'page' => $page,
			'sortBy' => $sort,
			'sortDesc' => $desc,
			'filterBy' => $filter !== '' ? $filter : null,
			'mediaType' => 'book',
			'minified' => true,
			'collapseseries' => $collapse,
			'include' => (string)$this->request->getParam('include', ''),
			'offset' => $page * $limit,
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/personalized')]
	public function personalized(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $id) === null) {
			return $this->notFound('Library not found');
		}
		$this->refreshLibrary($uid);
		$limit = max(1, min(50, (int)($this->request->getParam('limit') ?: 10)));
		$rows = $this->catalog->visibleRows($uid, $id);
		$prog = $this->progress->allForUser($uid);
		$shelves = [];

		$inProgress = array_values(array_filter($rows, static fn ($r) => isset($prog[(string)$r['item_key']]) && !$prog[(string)$r['item_key']]['is_finished'] && !$prog[(string)$r['item_key']]['hide_continue']));
		foreach ([false, true] as $reading) {
			$these = array_values(array_filter($inProgress, static fn ($r) => Serializer::isEbookOnly($r) === $reading));
			if ($these === []) {
				continue;
			}
			$entities = $this->catalog->minified($uid, array_slice(Catalog::sortRows($these, 'progress', true, $prog), 0, $limit));
			foreach ($entities as &$e) {
				$e['progressLastUpdate'] = (int)$prog[$e['id']]['last_update'];
			}
			unset($e);
			$shelves[] = $reading
				? ['id' => 'continue-reading', 'label' => 'Continue Reading', 'labelStringKey' => 'LabelContinueReading', 'type' => 'book', 'entities' => $entities, 'total' => count($these)]
				: ['id' => 'continue-listening', 'label' => 'Continue Listening', 'labelStringKey' => 'LabelContinueListening', 'type' => 'book', 'entities' => $entities, 'total' => count($these)];
		}

		$series = $this->catalog->seriesMap($uid, $id);
		$next = Catalog::nextInSeries(array_diff_key($series, array_flip($this->hiddenSeries->get($uid))), $prog);
		if ($next !== []) {
			$shelves[] = ['id' => 'continue-series', 'label' => 'Continue Series', 'labelStringKey' => 'LabelContinueSeries', 'type' => 'book', 'entities' => $this->catalog->minifiedInSeries($uid, array_slice($next, 0, $limit)), 'total' => count($next)];
		}
		if ($series !== []) {
			uasort($series, static fn ($a, $b) => $b['addedAt'] <=> $a['addedAt']);
			$entities = array_map(fn ($s) => $this->catalog->seriesJson($uid, $id, $s), array_slice(array_values($series), 0, $limit));
			$recentSeriesShelf = ['id' => 'recent-series', 'label' => 'Recent Series', 'labelStringKey' => 'LabelRecentSeries', 'type' => 'series', 'entities' => $entities, 'total' => count($series)];
		}

		$recent = array_slice(Catalog::sortRows($rows, 'addedAt', true), 0, $limit);
		if ($recent !== []) {
			$shelves[] = ['id' => 'recently-added', 'label' => 'Recently Added', 'labelStringKey' => 'LabelRecentlyAdded', 'type' => 'book', 'entities' => $this->catalog->minified($uid, $recent), 'total' => count($rows)];
		}

		if (isset($recentSeriesShelf)) {
			$shelves[] = $recentSeriesShelf;
		}

		$discover = Catalog::discover($rows, $series, $prog);
		if ($discover !== []) {
			shuffle($discover);
			$shelves[] = ['id' => 'discover', 'label' => 'Discover', 'labelStringKey' => 'LabelDiscover', 'type' => 'book', 'entities' => $this->catalog->minified($uid, array_slice($discover, 0, $limit)), 'total' => count($discover)];
		}

		$finished = array_values(array_filter($rows, static fn ($r) => isset($prog[(string)$r['item_key']]) && $prog[(string)$r['item_key']]['is_finished']));
		foreach ([false, true] as $reading) {
			$these = array_values(array_filter($finished, static fn ($r) => Serializer::isEbookOnly($r) === $reading));
			if ($these === []) {
				continue;
			}
			$entities = $this->catalog->minified($uid, array_slice(Catalog::sortRows($these, 'progress', true, $prog), 0, $limit));
			foreach ($entities as &$e) {
				$e['finishedAt'] = (int)($prog[$e['id']]['finished_at'] ?? 0);
			}
			unset($e);
			$shelves[] = $reading ? ['id' => 'read-again', 'label' => 'Read Again', 'labelStringKey' => 'LabelReadAgain', 'type' => 'book', 'entities' => $entities, 'total' => count($these)] : ['id' => 'listen-again', 'label' => 'Listen Again', 'labelStringKey' => 'LabelListenAgain', 'type' => 'book', 'entities' => $entities, 'total' => count($these)];
		}
		$authors = $this->catalog->authorsMap($uid, $id);
		if ($authors !== []) {
			uasort($authors, static fn ($a, $b) => $b['addedAt'] <=> $a['addedAt']);
			$shelves[] = ['id' => 'newest-authors', 'label' => 'Newest Authors', 'labelStringKey' => 'LabelNewestAuthors', 'type' => 'authors', 'entities' => array_map(static fn ($a) => Catalog::authorJson($id, $a), array_slice(array_values($authors), 0, $limit)), 'total' => count($authors)];
		}
		return new JSONResponse($shelves);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/search')]
	public function search(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $id) === null) {
			return $this->notFound('Library not found');
		}
		$this->ensureScanned($uid);
		$q = mb_strtolower(trim((string)$this->request->getParam('q', '')));
		$limit = max(1, min(100, (int)($this->request->getParam('limit') ?: 12)));
		$books = [];
		$series = [];
		$rows = $this->catalog->visibleRows($uid, $id);
		$named = static fn (string $group, string $key) => $q === '' ? [] : array_map(
			static fn ($name, $n) => ['name' => (string)$name, $key => $n],
			array_keys($found = array_slice(array_filter(Catalog::count($rows, $group), static fn ($n, $name) => str_contains(mb_strtolower((string)$name), $q), ARRAY_FILTER_USE_BOTH), 0, $limit, true)),
			array_values($found),
		);
		$authors = [];
		if ($q !== '') {
			foreach ($this->catalog->authorsMap($uid, $id) as $a) {
				if (count($authors) < $limit && str_contains(mb_strtolower($a['name']), $q)) {
					$authors[] = Catalog::authorJson($id, $a);
				}
			}
			foreach ($this->catalog->seriesMap($uid, $id) as $s) {
				if (count($series) < $limit && str_contains(mb_strtolower($s['name']), $q)) {
					$json = $this->catalog->seriesJson($uid, $id, $s);
					$found = $json['books'];
					unset($json['books']);
					$series[] = ['series' => $json, 'books' => $found];
				}
			}
			$scored = [];
			foreach ($rows as $r) {
				$extra = Serializer::extra($r);
				$fields = ['title' => (string)$r['title'], 'subtitle' => (string)$r['subtitle'], 'authorName' => (string)$r['author'], 'narrators' => (string)$r['narrator'],
					'series' => implode(', ', array_column(Serializer::seriesList($r), 'name')), 'tags' => implode(', ', $extra['tags'] ?? []), 'isbn' => (string)($extra['isbn'] ?? ''), 'asin' => (string)($extra['asin'] ?? '')];
				foreach ($fields as $k => $v) {
					if ($v !== '' && str_contains(mb_strtolower($v), $q)) {
						$scored[] = ['row' => $r, 'matchKey' => $k, 'matchText' => $v, 'rank' => $k === 'title' ? 0 : 1];
						break;
					}
				}
			}
			usort($scored, static fn ($a, $b) => $a['rank'] <=> $b['rank'] ?: strnatcasecmp((string)$a['row']['title'], (string)$b['row']['title']));
			$scored = array_slice($scored, 0, $limit);
			$items = $this->catalog->minified($uid, array_column($scored, 'row'));
			foreach ($items as $i => $it) {
				$books[] = ['libraryItem' => $it, 'matchKey' => $scored[$i]['matchKey'], 'matchText' => $scored[$i]['matchText']];
			}
		}
		return new JSONResponse(['book' => $books, 'podcast' => [], 'narrators' => $named('narrators', 'numBooks'), 'tags' => $named('tags', 'numItems'), 'genres' => $named('genres', 'numItems'), 'series' => $series, 'authors' => $authors]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/series')]
	public function series(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $id) === null) {
			return $this->notFound('Library not found');
		}
		$limit = max(0, (int)$this->request->getParam('limit', 0));
		$page = max(0, (int)$this->request->getParam('page', 0));
		$sort = (string)$this->request->getParam('sort', 'name');
		$desc = in_array((string)$this->request->getParam('desc', '0'), ['1', 'true'], true);
		$list = array_values($this->catalog->seriesMap($uid, $id));
		usort($list, static function ($a, $b) use ($sort) {
			return match ($sort) {
				'addedAt' => $a['addedAt'] <=> $b['addedAt'],
				'numBooks' => count($a['rows']) <=> count($b['rows']),
				'totalDuration' => array_sum(array_column($a['rows'], 'duration')) <=> array_sum(array_column($b['rows'], 'duration')),
				default => strnatcasecmp(Serializer::ignorePrefix($a['name']), Serializer::ignorePrefix($b['name'])),
			};
		});
		if ($desc) {
			$list = array_reverse($list);
		}
		$total = count($list);
		if ($limit > 0) {
			$list = array_slice($list, $page * $limit, $limit);
		}
		return new JSONResponse([
			'results' => array_map(fn ($s) => $this->catalog->seriesJson($uid, $id, $s), $list),
			'total' => $total, 'limit' => $limit, 'page' => $page, 'sortBy' => $sort, 'sortDesc' => $desc,
			'filterBy' => null, 'minified' => true, 'include' => '', 'offset' => $page * $limit,
		]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{library}/series/{id}', postfix: 'inLibrary')]
	#[FrontpageRoute(verb: 'GET', url: '/api/series/{id}')]
	public function seriesById(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$found = $this->catalog->findInLibraries($uid, $id, true);
		if ($found === null) {
			return $this->notFound('Series not found');
		}
		$s = $found['entry'];
		$out = ['id' => $s['id'], 'name' => $s['name'], 'nameIgnorePrefix' => Serializer::ignorePrefix($s['name']), 'description' => $this->lists->seriesDescription($uid, $s['id']), 'addedAt' => $s['addedAt'], 'updatedAt' => $s['updatedAt'], 'libraryId' => $found['libraryId']];
		$include = (string)$this->request->getParam('include', '');
		if (str_contains($include, 'progress')) {
			$prog = $this->progress->allForUser($uid);
			$keys = array_map(static fn ($r) => (string)$r['item_key'], $s['rows']);
			$finished = array_values(array_filter($keys, static fn ($k) => !empty($prog[$k]['is_finished'])));
			$inProg = array_values(array_filter($keys, static fn ($k) => isset($prog[$k]) && empty($prog[$k]['is_finished'])));
			$out['progress'] = ['libraryItemIds' => $keys, 'libraryItemIdsFinished' => $finished, 'isFinished' => count($finished) === count($keys)];
			$out['progress']['libraryItemIdsInProgress'] = $inProg;
		}
		return new JSONResponse($out);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/authors')]
	public function authors(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $id) === null) {
			return $this->notFound('Library not found');
		}
		$list = array_values($this->catalog->authorsMap($uid, $id));
		usort($list, static fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));
		return new JSONResponse(['authors' => array_map(static fn ($a) => Catalog::authorJson($id, $a), $list)]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/authors/{id}')]
	public function authorById(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$found = $this->catalog->findInLibraries($uid, $id, false);
		if ($found === null) {
			return $this->notFound('Author not found');
		}
		$libId = $found['libraryId'];
		$a = $found['entry'];
		$out = Catalog::authorJson($libId, $a);
		$include = (string)$this->request->getParam('include', '');
		if (str_contains($include, 'items')) {
			$out['libraryItems'] = $this->catalog->minified($uid, Catalog::sortRows($a['rows'], 'media.metadata.title', false));
		}
		if (str_contains($include, 'series')) {
			$series = [];
			foreach ($a['rows'] as $r) {
				foreach (Serializer::seriesList($r) as $in) {
					$series[Serializer::seriesId($in['name'])][] = $r + ['in_series' => $in];
				}
			}
			$out['series'] = [];
			foreach ($series as $sid => $rows) {
				$rows = Catalog::sortRows($rows, 'sequence', false);
				$out['series'][] = ['id' => $sid, 'name' => $rows[0]['in_series']['name'], 'items' => $this->catalog->minifiedInSeries($uid, $rows)];
			}
		}
		return new JSONResponse($out);
	}

	/** Rename a series in all its books, or change its description. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/series/{id}')]
	public function updateSeries(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		$found = $this->catalog->findInLibraries($uid, $id, true);
		if ($found === null) {
			return $this->notFound('Series not found');
		}
		$body = $this->body();
		if (array_key_exists('description', $body)) {
			$this->lists->setSeriesDescription($uid, $id, ListService::cleanDescription($body['description']));
		}
		$name = ListService::cleanName($body['name'] ?? null);
		if ($name !== '' && $name !== $found['entry']['name']) {
			$id = $this->catalog->renameSeries($uid, $id, $name);
		}
		return $this->seriesById($id);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/filterdata')]
	public function filters(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		return $this->library->getLibrary($uid, $id) === null ? $this->notFound('Library not found') : new JSONResponse($this->filterData($uid, $id));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/stats')]
	public function libraryStats(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		return $this->library->getLibrary($uid, $id) === null ? $this->notFound('Library not found') : new JSONResponse($this->stats($uid, $id));
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/narrators')]
	public function narrators(string $id): JSONResponse {
		$uid = $this->currentUser();
		if ($uid === null) {
			return $this->unauthorized();
		}
		if ($this->library->getLibrary($uid, $id) === null) {
			return $this->notFound('Library not found');
		}
		$out = [];
		foreach (Catalog::count($this->catalog->visibleRows($uid, $id), 'narrators') as $name => $n) {
			$out[] = ['id' => rawurlencode(base64_encode((string)$name)), 'name' => (string)$name, 'numBooks' => $n];
		}
		return new JSONResponse(['narrators' => $out]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/tags')]
	public function tags(): JSONResponse {
		$uid = $this->currentUser();
		return $uid === null ? $this->unauthorized() : new JSONResponse(['tags' => array_map('strval', array_keys(Catalog::count($this->catalog->visibleRows($uid), 'tags')))]);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/genres')]
	public function genres(): JSONResponse {
		$uid = $this->currentUser();
		return $uid === null ? $this->unauthorized() : new JSONResponse(['genres' => array_map('strval', array_keys(Catalog::count($this->catalog->visibleRows($uid), 'genres')))]);
	}

	/** No author photos (they would come from an online metadata provider in real ABS). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/authors/{id}/image')]
	public function authorImage(string $id): JSONResponse {
		return $this->notFound('Author image not found');
	}
}
