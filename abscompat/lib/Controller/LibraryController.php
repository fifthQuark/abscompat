<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Controller;

use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\LibraryService;
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
				'numUserPlaylists' => 0,
			]);
		}
		return $this->notFound('Library not found');
	}

	private function filterData(string $uid, string $libId): array {
		$this->ensureScanned($uid);
		$rows = $this->catalog->visibleRows($uid, $libId);
		$authors = [];
		$series = [];
		$narrators = [];
		$genres = [];
		foreach ($rows as $r) {
			foreach (Serializer::splitNames($r['author']) as $a) {
				$authors[Serializer::authorId($a)] = ['id' => Serializer::authorId($a), 'name' => $a];
			}
			foreach (Serializer::splitNames($r['narrator']) as $n) {
				$narrators[$n] = $n;
			}
			if ($r['series']) {
				$series[Serializer::seriesId($r['series'])] = ['id' => Serializer::seriesId($r['series']), 'name' => $r['series']];
			}
			foreach (json_decode((string)$r['genres'], true) ?: [] as $g) {
				$genres[$g] = $g;
			}
		}
		return [
			'authors' => array_values($authors),
			'genres' => array_values($genres),
			'tags' => [],
			'series' => array_values($series),
			'narrators' => array_values($narrators),
			'languages' => [],
			'publishers' => [],
			'publishedDecades' => [],
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
			return match ($group) {
				'series' => $r['series'] && ($val === Serializer::seriesId($r['series']) || $val === $r['series']),
				'authors' => in_array($val, array_merge(Serializer::splitNames($r['author']), array_map([Serializer::class, 'authorId'], Serializer::splitNames($r['author']))), true),
				'narrators' => in_array($val, Serializer::splitNames($r['narrator']), true),
				'genres' => in_array($val, json_decode((string)$r['genres'], true) ?: [], true),
				'progress' => match ($val) {
					'finished' => $p !== null && $p['is_finished'],
					'not-started' => $p === null,
					'not-finished' => $p === null || !$p['is_finished'],
					'in-progress' => $p !== null && !$p['is_finished'],
					default => true,
				},
				'ebooks' => $val === 'ebook' ? $r['ebook_file_id'] !== null : true,
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
		if (str_starts_with($filter, 'series.') && !$this->request->getParam('sort')) {
			$sort = 'sequence';
		}
		$rows = Catalog::sortRows($rows, $sort, $desc, $prog);
		$collapse = in_array((string)$this->request->getParam('collapseseries', '0'), ['1', 'true'], true) && $filter === '';
		$collapsed = [];
		if ($collapse) {
			// One entry per series: its first book, carrying collapsedSeries (as ABS does).
			$bySeries = [];
			$kept = [];
			foreach ($rows as $r) {
				$name = trim((string)($r['series'] ?? ''));
				if ($name === '') {
					$kept[] = $r;
					continue;
				}
				$sid = Serializer::seriesId($name);
				if (!isset($bySeries[$sid])) {
					$bySeries[$sid] = [];
					$kept[] = $r;
				}
				$bySeries[$sid][] = $r;
			}
			foreach ($bySeries as $sid => $members) {
				$members = Catalog::sortRows($members, 'sequence', false);
				$collapsed[$sid] = [
					'id' => $sid,
					'libraryId' => $id,
					'name' => (string)$members[0]['series'],
					'nameIgnorePrefix' => Serializer::ignorePrefix((string)$members[0]['series']),
					'numBooks' => count($members),
					'libraryItemIds' => array_map(static fn ($m) => (string)$m['item_key'], $members),
					'seriesSequenceList' => implode(', ', array_filter(array_map(static fn ($m) => (string)$m['series_seq'], $members), 'strlen')),
				];
			}
			$rows = $kept;
		}
		$total = count($rows);
		if ($limit > 0) {
			$rows = array_slice($rows, $page * $limit, $limit);
		}
		$results = $this->catalog->minified($uid, $rows);
		if ($collapse) {
			foreach ($results as $i => &$r) {
				$name = trim((string)($rows[$i]['series'] ?? ''));
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
		if ($inProgress !== []) {
			$sorted = array_slice(Catalog::sortRows($inProgress, 'progress', true, $prog), 0, $limit);
			$entities = $this->catalog->minified($uid, $sorted);
			foreach ($entities as &$e) {
				$e['progressLastUpdate'] = (int)$prog[$e['id']]['last_update'];
			}
			unset($e);
			$shelves[] = ['id' => 'continue-listening', 'label' => 'Continue Listening', 'labelStringKey' => 'LabelContinueListening', 'type' => 'book', 'entities' => $entities, 'total' => count($inProgress)];
		}

		$series = $this->catalog->seriesMap($uid, $id);
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

		$finished = array_values(array_filter($rows, static fn ($r) => isset($prog[(string)$r['item_key']]) && $prog[(string)$r['item_key']]['is_finished']));
		if ($finished !== []) {
			$sorted = array_slice(Catalog::sortRows($finished, 'progress', true, $prog), 0, $limit);
			$entities = $this->catalog->minified($uid, $sorted);
			foreach ($entities as &$e) {
				$e['finishedAt'] = (int)($prog[$e['id']]['finished_at'] ?? 0);
			}
			unset($e);
			$listenAgain = ['id' => 'listen-again', 'label' => 'Listen Again', 'labelStringKey' => 'LabelListenAgain', 'type' => 'book', 'entities' => $entities, 'total' => count($finished)];
		}
		$authors = $this->catalog->authorsMap($uid, $id);
		if ($authors !== []) {
			uasort($authors, static fn ($a, $b) => $b['addedAt'] <=> $a['addedAt']);
			$shelves[] = ['id' => 'newest-authors', 'label' => 'Newest Authors', 'labelStringKey' => 'LabelNewestAuthors', 'type' => 'authors', 'entities' => array_map(static fn ($a) => Catalog::authorJson($id, $a), array_slice(array_values($authors), 0, $limit)), 'total' => count($authors)];
		}
		if (isset($listenAgain)) {
			$shelves[] = $listenAgain;
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
		if ($q !== '') {
			$scored = [];
			foreach ($this->catalog->visibleRows($uid, $id) as $r) {
				$fields = ['title' => (string)$r['title'], 'subtitle' => (string)$r['subtitle'], 'authorName' => (string)$r['author'], 'narrators' => (string)$r['narrator'], 'series' => (string)$r['series']];
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
		return new JSONResponse(['book' => $books, 'podcast' => [], 'narrators' => [], 'tags' => [], 'genres' => [], 'series' => [], 'authors' => []]);
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
		$out = ['id' => $s['id'], 'name' => $s['name'], 'nameIgnorePrefix' => Serializer::ignorePrefix($s['name']), 'description' => null, 'addedAt' => $s['addedAt'], 'updatedAt' => $s['updatedAt'], 'libraryId' => $found['libraryId']];
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
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/collections')]
	public function collections(string $id): JSONResponse {
		return $this->emptyList();
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/libraries/{id}/playlists')]
	public function playlists(string $id): JSONResponse {
		return $this->emptyList();
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
				$name = trim((string)($r['series'] ?? ''));
				if ($name !== '') {
					$series[Serializer::seriesId($name)][] = $r;
				}
			}
			$out['series'] = [];
			foreach ($series as $sid => $rows) {
				$rows = Catalog::sortRows($rows, 'sequence', false);
				$items = $this->catalog->minified($uid, $rows);
				foreach ($items as $i => &$it) {
					$it['media']['metadata']['series'] = ['id' => $sid, 'name' => (string)$rows[0]['series'], 'sequence' => $rows[$i]['series_seq']];
				}
				unset($it);
				$out['series'][] = ['id' => $sid, 'name' => (string)$rows[0]['series'], 'items' => $items];
			}
		}
		return new JSONResponse($out);
	}

	/** No author photos (they would come from an online metadata provider in real ABS). */
	#[PublicPage]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/api/authors/{id}/image')]
	public function authorImage(string $id): JSONResponse {
		return $this->notFound('Author image not found');
	}

	/** Collections and playlists are user-curated in ABS; not supported, so always an empty page. */
	private function emptyList(): JSONResponse {
		if ($this->currentUser() === null) {
			return $this->unauthorized();
		}
		return new JSONResponse(['results' => [], 'total' => 0, 'limit' => (int)$this->request->getParam('limit', 0), 'page' => 0, 'offset' => 0, 'minified' => true, 'include' => '']);
	}
}
