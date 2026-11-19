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
			$name = trim((string)($r['series'] ?? ''));
			if ($name === '') {
				continue;
			}
			$id = Serializer::seriesId($name);
			$out[$id] ??= ['id' => $id, 'name' => $name, 'rows' => [], 'addedAt' => PHP_INT_MAX, 'updatedAt' => 0];
			$out[$id]['rows'][] = $r;
			$out[$id]['addedAt'] = min($out[$id]['addedAt'], (int)$r['added_at']);
			$out[$id]['updatedAt'] = max($out[$id]['updatedAt'], (int)$r['updated_at']);
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
			$b['sequence'] = $s['rows'][$i]['series_seq'] ?? null;
		}
		unset($b);
		return [
			'id' => $s['id'],
			'name' => $s['name'],
			'nameIgnorePrefix' => Serializer::ignorePrefix($s['name']),
			'nameIgnorePrefixSort' => Serializer::ignorePrefix($s['name']),
			'type' => 'series',
			'description' => null,
			'libraryId' => $libraryId,
			'addedAt' => $s['addedAt'],
			'updatedAt' => $s['updatedAt'],
			'totalDuration' => array_sum(array_map(static fn ($r) => (float)$r['duration'], $s['rows'])),
			'books' => $books,
		];
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
			'media.metadata.title', 'title' => static fn ($r) => mb_strtolower(Serializer::ignorePrefix((string)$r['title'])),
			'media.metadata.authorName', 'media.metadata.authorNameLF' => static fn ($r) => mb_strtolower((string)$r['author']),
			'media.duration' => static fn ($r) => (float)$r['duration'],
			'size' => static fn ($r) => (int)$r['size'],
			'media.metadata.publishedYear' => static fn ($r) => (string)$r['published_year'],
			'mtimeMs', 'birthtimeMs' => static fn ($r) => (int)$r['mtime'],
			'progress' => static fn ($r) => (int)($progressByKey[(string)$r['item_key']]['last_update'] ?? 0),
			'sequence' => static fn ($r) => is_numeric($r['series_seq'] ?? null) ? (float)$r['series_seq'] : PHP_FLOAT_MAX,
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
