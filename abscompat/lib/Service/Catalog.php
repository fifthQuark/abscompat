<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

/**
 * Turns item rows into ABS JSON, with library paths and tracks loaded in bulk.
 */
class Catalog {
	/** @var array<string, array<string,string>> */
	private array $libPaths = [];

	public function __construct(
		private LibraryService $library,
		private ProgressService $progress,
		private ListService $lists,
	) {
	}

	private function libPath(string $userId, string $libraryId): string {
		if (!isset($this->libPaths[$userId])) {
			$this->libPaths[$userId] = [];
			foreach ($this->library->getLibraries($userId) as $lib) {
				$this->libPaths[$userId][$lib['id']] = $lib['path'];
			}
		}
		return $this->libPaths[$userId][$libraryId] ?? '';
	}

	/** Only items whose library is still configured. */
	public function visibleRows(string $userId, ?string $libraryId = null, ?array $keys = null): array {
		$rows = $this->library->getItemRows($userId, $libraryId, $keys);
		return array_values(array_filter($rows, fn ($r) => $this->libPath($userId, (string)$r['library_id']) !== ''));
	}

	public function item(string $userId, string $key): ?array {
		$row = $this->library->getItemRow($userId, $key);
		if ($row === null || $this->libPath($userId, (string)$row['library_id']) === '') {
			return null;
		}
		return $row;
	}

	/** What a book is filed under: names, for filters, lists and search. */
	public static function terms(array $r, string $group): array {
		$extra = Serializer::extra($r);
		return match ($group) {
			'authors' => Serializer::splitNames($r['author']),
			'narrators' => Serializer::splitNames($r['narrator']),
			'genres' => json_decode((string)$r['genres'], true) ?: [],
			'tags' => $extra['tags'] ?? [],
			'series' => array_column(Serializer::seriesList($r), 'name'),
			'publishers' => isset($extra['publisher']) ? [$extra['publisher']] : [],
			'languages' => isset($extra['language']) ? [$extra['language']] : [],
			default => [],
		};
	}

	/** @return array<string, int> name => number of books, sorted by name; spelling of the first book wins */
	public static function count(array $rows, string $group): array {
		$names = [];
		$n = [];
		foreach ($rows as $r) {
			foreach (self::terms($r, $group) as $t) {
				$k = mb_strtolower((string)$t);
				$names[$k] ??= (string)$t;
				$n[$k] = ($n[$k] ?? 0) + 1;
			}
		}
		$out = [];
		foreach ($names as $k => $name) {
			$out[$name] = $n[$k];
		}
		uksort($out, static fn ($a, $b) => strnatcasecmp((string)$a, (string)$b));
		return $out;
	}

	/** @return list<array> */
	public function minified(string $userId, array $rows): array {
		$tracks = $this->library->getTrackRowsFor(array_column($rows, 'id'));
		return array_map(fn ($r) => Serializer::itemMinified($r, $tracks[(int)$r['id']] ?? [], $this->libPath($userId, (string)$r['library_id'])), $rows);
	}

	public function expanded(string $userId, array $row, bool $withProgress = false): array {
		$out = Serializer::itemExpanded($row, $this->library->getTrackRows((int)$row['id']), $this->libPath($userId, (string)$row['library_id']));
		if ($withProgress) {
			$p = $this->progress->get($userId, (string)$row['item_key']);
			$out['userMediaProgress'] = $p !== null ? ProgressService::toJson($p, $userId) : null;
		}
		return $out;
	}

	/**
	 * Series in a library, derived from item rows.
	 * @return array<string, array{id: string, name: string, rows: list<array>, addedAt: int, updatedAt: int}>
	 */
	public function seriesMap(string $userId, string $libraryId): array {
		$out = [];
		foreach ($this->visibleRows($userId, $libraryId) as $r) {
			foreach (Serializer::seriesList($r) as $in) {
				$id = Serializer::seriesId($in['name']);
				$out[$id] ??= ['id' => $id, 'name' => $in['name'], 'rows' => [], 'addedAt' => PHP_INT_MAX, 'updatedAt' => 0];
				$out[$id]['rows'][] = $r + ['in_series' => $in];
				$out[$id]['addedAt'] = min($out[$id]['addedAt'], (int)$r['added_at']);
				$out[$id]['updatedAt'] = max($out[$id]['updatedAt'], (int)$r['updated_at']);
			}
		}
		foreach ($out as &$s) {
			$s['rows'] = self::sortRows($s['rows'], 'sequence', false);
		}
		return $out;
	}

	/**
	 * Authors in a library, derived from item rows.
	 * @return array<string, array{id: string, name: string, rows: list<array>, addedAt: int, updatedAt: int}>
	 */
	public function authorsMap(string $userId, string $libraryId): array {
		$out = [];
		foreach ($this->visibleRows($userId, $libraryId) as $r) {
			foreach (Serializer::splitNames($r['author'] ?? null) as $name) {
				$id = Serializer::authorId($name);
				$out[$id] ??= ['id' => $id, 'name' => $name, 'rows' => [], 'addedAt' => PHP_INT_MAX, 'updatedAt' => 0];
				$out[$id]['rows'][] = $r;
				$out[$id]['addedAt'] = min($out[$id]['addedAt'], (int)$r['added_at']);
				$out[$id]['updatedAt'] = max($out[$id]['updatedAt'], (int)$r['updated_at']);
			}
		}
		return $out;
	}

	/** Series JSON as in GET /api/libraries/:id/series and the series shelf. */
	public function seriesJson(string $userId, string $libraryId, array $s): array {
		$books = $this->minified($userId, $s['rows']);
		foreach ($books as $i => &$b) {
			$b['sequence'] = $s['rows'][$i]['in_series']['seq'] ?? null;
		}
		unset($b);
		return [
			'id' => $s['id'],
			'name' => $s['name'],
			'nameIgnorePrefix' => Serializer::ignorePrefix($s['name']),
			'nameIgnorePrefixSort' => Serializer::ignorePrefix($s['name']),
			'type' => 'series',
			'description' => $this->lists->seriesDescription($userId, $s['id']),
			'libraryId' => $libraryId,
			'addedAt' => $s['addedAt'],
			'updatedAt' => $s['updatedAt'],
			'totalDuration' => array_sum(array_map(static fn ($r) => (float)$r['duration'], $s['rows'])),
			'books' => $books,
		];
	}

	/**
	 * Minified books that also say which number they have in the series being shown, as ABS does
	 * for series lists (there metadata.series is one object, not a list).
	 * @return list<array>
	 */
	public function minifiedInSeries(string $userId, array $rows): array {
		$items = $this->minified($userId, $rows);
		foreach ($items as $i => &$it) {
			$in = $rows[$i]['in_series'] ?? null;
			if ($in !== null) {
				$it['media']['metadata']['series'] = ['id' => Serializer::seriesId($in['name']), 'name' => $in['name'], 'sequence' => $in['seq']];
			}
		}
		return $items;
	}

	/**
	 * The next book of every series the user has finished a book of and isn't in the middle of,
	 * most recently listened series first.
	 *
	 * @param array<array{rows: list<array>}> $series from seriesMap()
	 * @param array<string, array> $progress by item key
	 * @return list<array> item rows
	 */
	public static function nextInSeries(array $series, array $progress): array {
		$out = [];
		foreach ($series as $s) {
			$next = null;
			$finished = 0;
			$last = 0;
			foreach ($s['rows'] as $r) {
				$p = $progress[(string)$r['item_key']] ?? null;
				$last = max($last, (int)($p['last_update'] ?? 0));
				if (!empty($p['is_finished'])) {
					$finished++;
				} elseif ($p !== null && (float)$p['cur_time'] > 0) {
					// Already under Continue Listening.
					continue 2;
				} else {
					$next ??= $r;
				}
			}
			if ($finished > 0 && $next !== null) {
				$out[] = [$last, $next];
			}
		}
		usort($out, static fn ($a, $b) => $b[0] <=> $a[0]);
		return array_column($out, 1);
	}

	/** Books not started that are in no series, or the first one of a series not started. @return list<array> */
	public static function discover(array $rows, array $series, array $progress): array {
		$started = static fn ($r) => ($p = $progress[(string)$r['item_key']] ?? null) !== null && ($p['is_finished'] || (float)$p['cur_time'] > 0 || (float)($p['ebook_progress'] ?? 0) > 0);
		$first = [];
		foreach ($series as $s) {
			if (array_filter($s['rows'], $started) === []) {
				$first[(string)$s['rows'][0]['item_key']] = true;
			}
		}
		return array_values(array_filter($rows, static fn ($r) => !$started($r) && (Serializer::seriesList($r) === [] || isset($first[(string)$r['item_key']]))));
	}

	/** @return string the new series id */
	public function renameSeries(string $userId, string $id, string $name): string {
		$new = Serializer::seriesId($name);
		foreach ($this->visibleRows($userId) as $r) {
			$list = Serializer::seriesList($r);
			$has = array_filter($list, static fn ($s) => Serializer::seriesId($s['name']) === $id);
			if ($has === []) {
				continue;
			}
			$list = array_map(static fn ($s) => Serializer::seriesId($s['name']) === $id ? ['name' => $name] + $s : $s, $list);
			$main = array_shift($list);
			$this->library->updateMetadata($userId, (string)$r['item_key'], ['series' => $main['name'], 'seriesSeq' => $main['seq'], 'moreSeries' => $list]);
		}
		if ($new !== $id && ($d = $this->lists->seriesDescription($userId, $id)) !== null) {
			$this->lists->setSeriesDescription($userId, $new, $d);
			$this->lists->setSeriesDescription($userId, $id, null);
		}
		return $new;
	}

	/** Author JSON as in GET /api/libraries/:id/authors and the authors shelf. */
	public static function authorJson(string $libraryId, array $a): array {
		return [
			'id' => $a['id'],
			'asin' => null,
			'name' => $a['name'],
			'lastFirst' => Serializer::lastFirst($a['name']),
			'description' => null,
			'imagePath' => null,
			'libraryId' => $libraryId,
			'addedAt' => $a['addedAt'],
			'updatedAt' => $a['updatedAt'],
			'numBooks' => count($a['rows']),
		];
	}

	/** @return list<array> expanded books, with their tracks loaded at once */
	public function expandedAll(string $userId, array $rows): array {
		$tracks = $this->library->getTrackRowsFor(array_column($rows, 'id'));
		return array_map(fn ($r) => Serializer::itemExpanded($r, $tracks[(int)$r['id']] ?? [], $this->libPath($userId, (string)$r['library_id'])), $rows);
	}

	/** Playlist or collection as ABS returns it. Books that are gone are left out, but stay on the list. */
	public function listJson(string $userId, array $list): array {
		$keys = json_decode((string)$list['items'], true) ?: [];
		$rows = [];
		foreach ($this->visibleRows($userId, null, $keys) as $r) {
			$rows[(string)$r['item_key']] = $r;
		}
		$books = $this->expandedAll($userId, array_values(array_filter(array_map(static fn ($k) => $rows[(string)$k] ?? null, $keys))));
		$out = [
			'id' => (string)$list['list_id'],
			'name' => (string)$list['name'],
			'libraryId' => (string)$list['library_id'],
			'userId' => $userId,
			'description' => $list['description'],
			'lastUpdate' => (int)$list['updated_at'],
			'createdAt' => (int)$list['created_at'],
		];
		if ($list['kind'] === ListService::PLAYLIST) {
			$out['items'] = array_map(static fn ($b) => ['libraryItemId' => $b['id'], 'libraryItem' => $b], $books);
		} else {
			$out['books'] = $books;
		}
		return $out;
	}

	/** Find which library of the user holds a series/author id. */
	public function findInLibraries(string $userId, string $id, bool $series): ?array {
		foreach ($this->library->getLibraries($userId) as $lib) {
			$map = $series ? $this->seriesMap($userId, $lib['id']) : $this->authorsMap($userId, $lib['id']);
			if (isset($map[$id])) {
				return ['libraryId' => $lib['id'], 'entry' => $map[$id]];
			}
		}
		return null;
	}

	/** Sort rows by an ABS sort key. */
	public static function sortRows(array $rows, string $sort, bool $desc, array $progressByKey = []): array {
		$key = match ($sort) {
			'media.metadata.title', 'title' => static fn ($r) => mb_strtolower(Serializer::ignorePrefix((string)($r['sort_title'] ?? $r['title']))),
			'media.metadata.authorName', 'media.metadata.authorNameLF' => static fn ($r) => mb_strtolower((string)$r['author']),
			'media.duration' => static fn ($r) => (float)$r['duration'],
			'size' => static fn ($r) => (int)$r['size'],
			'media.metadata.publishedYear' => static fn ($r) => (string)$r['published_year'],
			'mtimeMs', 'birthtimeMs' => static fn ($r) => (int)$r['mtime'],
			'progress' => static fn ($r) => (int)($progressByKey[(string)$r['item_key']]['last_update'] ?? 0),
			'sequence' => static fn ($r) => SeriesParser::number($r['in_series']['seq'] ?? $r['series_seq'] ?? null) ?? PHP_FLOAT_MAX,
			default => static fn ($r) => (int)$r['added_at'],
		};
		usort($rows, static function ($a, $b) use ($key, $desc) {
			$c = $key($a) <=> $key($b);
			if ($c === 0) {
				$c = strnatcasecmp((string)$a['title'], (string)$b['title']);
			}
			return $desc ? -$c : $c;
		});
		return $rows;
	}
}
