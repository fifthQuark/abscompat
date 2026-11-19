<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Library folders, scanning and item lookup.
 *
 * Item id   = Nextcloud file id of the book folder (or of the single audio file).
 * Track ino = Nextcloud file id of each audio file.
 * Library id = file id of the configured library folder.
 */
class LibraryService {
	public const AUDIO_EXT = ['m4b', 'mp3', 'm4a', 'ogg', 'oga', 'opus', 'flac', 'aac', 'wav', 'wma', 'mp4'];
	public const EBOOK_EXT = ['epub'];
	public const COVER_NAMES = ['cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp', 'folder.jpg', 'folder.png'];
	public const DEFAULT_FOLDERS = ['/Audiobooks'];
	private const MIME = [
		'm4b' => 'audio/mp4', 'm4a' => 'audio/mp4', 'mp4' => 'audio/mp4', 'aac' => 'audio/aac',
		'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'opus' => 'audio/ogg',
		'flac' => 'audio/flac', 'wav' => 'audio/wav', 'wma' => 'audio/x-ms-wma',
	];

	public function __construct(
		private IDBConnection $db,
		private IRootFolder $rootFolder,
		private IUserConfig $userConfig,
		private IAppData $appData,
		private MetadataExtractor $extractor,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
	) {
	}

	// ----- settings -----

	/** @return list<string> */
	public function getFolderPaths(string $userId): array {
		$raw = $this->userConfig->getValueString($userId, Application::APP_ID, 'folders', '');
		$list = $raw === '' ? null : json_decode($raw, true);
		if (!is_array($list)) {
			return $this->getDefaultFolderPaths();
		}
		return array_values(array_filter(array_map('strval', $list), static fn ($p) => $p !== ''));
	}

	/** Whether the user picked folders, or uses the instance default. */
	public function usesDefaultFolders(string $userId): bool {
		return $this->userConfig->getValueString($userId, Application::APP_ID, 'folders', '') === '';
	}

	public function resetFolderPaths(string $userId): void {
		$this->userConfig->deleteUserConfig($userId, Application::APP_ID, 'folders');
	}

	/** Instance default set by an admin (falls back to /Audiobooks). @return list<string> */
	public function getDefaultFolderPaths(): array {
		$list = json_decode($this->appConfig->getValueString(Application::APP_ID, 'default_folders', ''), true);
		return is_array($list) && $list !== [] ? array_values(array_map('strval', $list)) : self::DEFAULT_FOLDERS;
	}

	/** @param list<string> $paths */
	public function setDefaultFolderPaths(array $paths): void {
		$clean = self::cleanPaths($paths);
		if ($clean === []) {
			$this->appConfig->deleteKey(Application::APP_ID, 'default_folders');
			return;
		}
		$this->appConfig->setValueString(Application::APP_ID, 'default_folders', json_encode($clean));
	}

	/** @return list<string> */
	public static function cleanPaths(array $paths): array {
		$clean = [];
		foreach ($paths as $p) {
			if (!is_string($p)) {
				continue;
			}
			$p = '/' . trim(str_replace('\\', '/', $p), "/ \t\n");
			if ($p !== '/' && !preg_match('#(^|/)\.\.(/|$)#', $p) && !in_array($p, $clean, true)) {
				$clean[] = $p;
			}
		}
		return array_slice($clean, 0, 20);
	}

	/** @param list<string> $paths */
	public function setFolderPaths(string $userId, array $paths): void {
		$this->userConfig->setValueString($userId, Application::APP_ID, 'folders', json_encode(self::cleanPaths($paths)));
	}

	public function getLastScan(string $userId): int {
		return $this->userConfig->getValueInt($userId, Application::APP_ID, 'last_scan', 0, true);
	}

	public function markScanned(string $userId): void {
		$this->userConfig->setValueInt($userId, Application::APP_ID, 'last_scan', time(), true);
	}

	/**
	 * Configured library folders that exist.
	 * @return list<array{id: string, name: string, path: string, folder: Folder}>
	 */
	public function getLibraries(string $userId): array {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$out = [];
		foreach ($this->getFolderPaths($userId) as $path) {
			try {
				$node = $userFolder->get($path);
			} catch (NotFoundException) {
				continue;
			}
			if ($node instanceof Folder) {
				$name = trim(basename($path)) ?: 'Audiobooks';
				$out[] = ['id' => (string)$node->getId(), 'name' => $name, 'path' => $path, 'folder' => $node];
			}
		}
		return $out;
	}

	public function getLibrary(string $userId, string $libraryId): ?array {
		foreach ($this->getLibraries($userId) as $lib) {
			if ($lib['id'] === $libraryId) {
				return $lib;
			}
		}
		return null;
	}

	// ----- scanning -----

	/** @return array{items: int, scanned: int, removed: int} */
	public function scanUser(string $userId, ?float $timeLimit = null): array {
		$started = microtime(true);
		$stats = ['items' => 0, 'scanned' => 0, 'removed' => 0];
		$seen = [];
		$libIds = [];
		foreach ($this->getLibraries($userId) as $lib) {
			$libIds[] = $lib['id'];
			$books = [];
			$this->findBooks($lib['folder'], 0, $books);
			foreach ($books as $book) {
				if ($timeLimit !== null && microtime(true) - $started > $timeLimit) {
					// Keep what we have; the next run continues. Don't remove anything.
					return $stats;
				}
				try {
					$key = $this->scanBook($userId, $lib, $book['node'], $book['audio'], $book['dir']);
					if ($key !== null) {
						$seen[$key] = true;
						$stats['items']++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Scan failed for ' . $book['node']->getPath() . ': ' . $e->getMessage(), ['app' => 'abscompat', 'exception' => $e]);
				}
			}
		}
		$stats['removed'] = $this->removeMissing($userId, $seen);
		return $stats;
	}

	/**
	 * Book = folder that directly holds audio files (its audio files in sub-folders, e.g. CD1/CD2,
	 * belong to it), or an audio file in the library root.
	 *
	 * @param list<array{node: Node, audio: list<File>, dir: Folder}> $books
	 */
	private function findBooks(Folder $dir, int $depth, array &$books): void {
		$audio = [];
		$subdirs = [];
		foreach ($dir->getDirectoryListing() as $child) {
			if ($child instanceof Folder) {
				$subdirs[] = $child;
			} elseif ($child instanceof File && self::isAudio($child->getName())) {
				$audio[] = $child;
			}
		}
		if ($depth === 0) {
			foreach ($audio as $f) {
				$books[] = ['node' => $f, 'audio' => [$f], 'dir' => $dir];
			}
		} elseif ($audio !== []) {
			foreach ($subdirs as $sd) {
				$this->collectAudio($sd, $audio);
			}
			$books[] = ['node' => $dir, 'audio' => $audio, 'dir' => $dir];
			return;
		}
		if ($depth < 6) {
			foreach ($subdirs as $sd) {
				$this->findBooks($sd, $depth + 1, $books);
			}
		}
	}

	/** @param list<File> $audio */
	private function collectAudio(Folder $dir, array &$audio): void {
		foreach ($dir->getDirectoryListing() as $child) {
			if ($child instanceof Folder) {
				$this->collectAudio($child, $audio);
			} elseif ($child instanceof File && self::isAudio($child->getName())) {
				$audio[] = $child;
			}
		}
	}

	public static function isAudio(string $name): bool {
		return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::AUDIO_EXT, true);
	}

	public static function mimeFor(string $name): string {
		return self::MIME[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
	}

	/** @param list<File> $audio */
	private function scanBook(string $userId, array $lib, Node $node, array $audio, Folder $dir): ?string {
		$key = (string)$node->getId();
		$isFile = $node instanceof File;
		$libRoot = rtrim($lib['folder']->getPath(), '/');
		$relPath = ltrim(substr($node->getPath(), strlen($libRoot)), '/');

		$existing = $this->getItemRow($userId, $key);
		$cachedTracks = [];
		if ($existing !== null) {
			foreach ($this->getTrackRows((int)$existing['id']) as $t) {
				$cachedTracks[(int)$t['file_id']] = $t;
			}
		}

		// Per-file metadata, reusing the cache when file id + mtime are unchanged.
		$tracks = [];
		$changed = $existing === null;
		$firstPicture = null;
		foreach ($audio as $i => $f) {
			$fid = (int)$f->getId();
			$mtime = (int)$f->getMTime();
			$c = $cachedTracks[$fid] ?? null;
			if ($c !== null && (int)$c['mtime'] === $mtime && (int)$c['size'] === (int)$f->getSize()) {
				$meta = json_decode((string)$c['meta'], true) ?: [];
				$meta['duration'] = (float)$c['duration'];
			} else {
				$changed = true;
				$wantPic = $i === 0 || $firstPicture === null;
				$meta = $this->extractor->extract($f, $wantPic);
				if ($meta['picture'] !== null && $firstPicture === null) {
					$firstPicture = $meta['picture'];
				}
				unset($meta['picture']);
			}
			$tracks[] = [
				'file' => $f,
				'fileId' => $fid,
				'mtime' => $mtime,
				'size' => (int)$f->getSize(),
				'filename' => $f->getName(),
				'relPath' => ltrim(substr($f->getPath(), strlen($libRoot)), '/'),
				'meta' => $meta,
			];
		}
		if (count($cachedTracks) !== count($tracks)) {
			$changed = true;
		}

		// Track order: disc/track tags when every file has them, else natural filename order.
		$allNumbered = array_reduce($tracks, static fn ($ok, $t) => $ok && isset($t['meta']['tags']['track']), true);
		usort($tracks, static function ($a, $b) use ($allNumbered) {
			if ($allNumbered) {
				$da = (int)($a['meta']['tags']['disc'] ?? 0);
				$db = (int)($b['meta']['tags']['disc'] ?? 0);
				$ta = (int)$a['meta']['tags']['track'];
				$tb = (int)$b['meta']['tags']['track'];
				if ($da !== $db) {
					return $da <=> $db;
				}
				if ($ta !== $tb) {
					return $ta <=> $tb;
				}
			}
			return strnatcasecmp($a['relPath'], $b['relPath']);
		});

		// Folder-side files: cover and ebook.
		$coverFileId = null;
		$ebookFileId = null;
		if (!$isFile) {
			foreach ($dir->getDirectoryListing() as $child) {
				if (!$child instanceof File) {
					continue;
				}
				$lower = strtolower($child->getName());
				if ($coverFileId === null && in_array($lower, self::COVER_NAMES, true)) {
					$coverFileId = (int)$child->getId();
				} elseif ($ebookFileId === null && in_array(pathinfo($lower, PATHINFO_EXTENSION), self::EBOOK_EXT, true)) {
					$ebookFileId = (int)$child->getId();
				}
			}
			if ($coverFileId === null) {
				foreach ($dir->getDirectoryListing() as $child) {
					if ($child instanceof File && preg_match('/\.(jpe?g|png|webp)$/i', $child->getName())) {
						$coverFileId = (int)$child->getId();
						break;
					}
				}
			}
		}
		if ((int)($existing['cover_file_id'] ?? 0) !== (int)$coverFileId || (int)($existing['ebook_file_id'] ?? 0) !== (int)$ebookFileId) {
			$changed = true;
		}
		if (!$changed && $existing !== null) {
			return $key;
		}

		// Book-level metadata.
		$first = $tracks[0]['meta']['tags'] ?? [];
		$parts = $relPath === '' ? [] : explode('/', $isFile ? preg_replace('/\.[^.\/]+$/', '', $relPath) : $relPath);
		$folderTitle = $parts !== [] ? end($parts) : $node->getName();
		$folderAuthor = count($parts) >= 2 ? $parts[0] : null;
		$folderSeries = count($parts) >= 3 ? $parts[count($parts) - 2] : null;
		$seriesSeq = null;
		if ($folderSeries !== null && preg_match('/^(?:(?:book|vol(?:ume)?)\.?\s*)?(\d+(?:\.\d+)?)\s*[-.–:]\s*(.+)$/iu', $folderTitle, $m)) {
			$seriesSeq = $m[1];
			$folderTitle = $m[2];
		}
		$multi = count($tracks) > 1;
		$title = $first['album'] ?? (!$multi ? ($first['title'] ?? null) : null) ?? $folderTitle;
		$author = $first['albumArtist'] ?? $first['artist'] ?? $folderAuthor;
		$series = $first['series'] ?? $folderSeries;
		$seriesSeq = $first['seriesPart'] ?? $seriesSeq;
		// Edits made from a client (PATCH /api/items/:id/media) win over file metadata.
		$ov = json_decode((string)($existing['overrides'] ?? ''), true) ?: [];
		foreach (['title' => 'title', 'author' => 'author', 'series' => 'series', 'seriesSeq' => 'series_seq'] as $k => $_) {
			if (array_key_exists($k, $ov)) {
				$$k = $ov[$k];
			}
		}

		// Durations, offsets, chapters.
		$offset = 0.0;
		$chapters = [];
		foreach ($tracks as $i => &$t) {
			$t['startOffset'] = $offset;
			$d = (float)($t['meta']['duration'] ?? 0);
			$fileChapters = $t['meta']['chapters'] ?? [];
			if ($fileChapters !== []) {
				foreach ($fileChapters as $c) {
					$chapters[] = ['start' => $offset + $c['start'], 'end' => $offset + $c['end'], 'title' => $c['title']];
				}
			} elseif ($multi || $d > 0) {
				$chapters[] = ['start' => $offset, 'end' => $offset + $d, 'title' => $multi ? ($t['meta']['tags']['title'] ?? pathinfo($t['filename'], PATHINFO_FILENAME)) : $title];
			}
			$offset += $d;
		}
		unset($t);
		$duration = $offset;
		foreach ($chapters as $i => &$c) {
			$c = ['id' => $i, 'start' => round($c['start'], 3), 'end' => round($c['end'], 3), 'title' => (string)$c['title']];
		}
		unset($c);
		$size = array_sum(array_column($tracks, 'size'));
		$now = (int)(microtime(true) * 1000);

		$this->db->beginTransaction();
		try {
			$values = [
				'user_id' => $userId,
				'library_id' => $lib['id'],
				'item_key' => $key,
				'is_file' => $isFile ? 1 : 0,
				'rel_path' => $relPath,
				'title' => mb_substr((string)$title, 0, 512),
				'subtitle' => isset($first['subtitle']) ? mb_substr($first['subtitle'], 0, 512) : null,
				'author' => $author !== null ? mb_substr($author, 0, 512) : null,
				'narrator' => isset($first['composer']) ? mb_substr($first['composer'], 0, 512) : null,
				'series' => $series !== null ? mb_substr($series, 0, 512) : null,
				'series_seq' => $seriesSeq !== null ? mb_substr($seriesSeq, 0, 32) : null,
				'published_year' => isset($first['year']) ? mb_substr(preg_replace('/^(\d{4}).*/', '$1', $first['year']), 0, 16) : null,
				'description' => $first['description'] ?? null,
				'genres' => json_encode(isset($first['genre']) ? array_values(array_filter(array_map('trim', preg_split('/[;\/]/', $first['genre'])))) : []),
				'duration' => $duration,
				'size' => $size,
				'chapters' => json_encode($chapters),
				'cover_file_id' => $coverFileId,
				'ebook_file_id' => $ebookFileId,
				'updated_at' => $now,
				'mtime' => (int)$node->getMTime(),
			];
			if ($existing === null) {
				$values['added_at'] = $now;
				$qb = $this->db->getQueryBuilder();
				$qb->insert('abs_items');
				foreach ($values as $k => $v) {
					$qb->setValue($k, $qb->createNamedParameter($v, self::ptype($v)));
				}
				$qb->executeStatement();
				$itemId = $qb->getLastInsertId();
			} else {
				$itemId = (int)$existing['id'];
				$qb = $this->db->getQueryBuilder();
				$qb->update('abs_items');
				foreach ($values as $k => $v) {
					$qb->set($k, $qb->createNamedParameter($v, self::ptype($v)));
				}
				$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
				$qb = $this->db->getQueryBuilder();
				$qb->delete('abs_tracks')->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)));
				$qb->executeStatement();
			}
			foreach ($tracks as $i => $t) {
				$meta = $t['meta'];
				unset($meta['duration']);
				$qb = $this->db->getQueryBuilder();
				$qb->insert('abs_tracks')->values([
					'item_id' => $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT),
					'track_index' => $qb->createNamedParameter($i + 1, IQueryBuilder::PARAM_INT),
					'file_id' => $qb->createNamedParameter($t['fileId'], IQueryBuilder::PARAM_INT),
					'filename' => $qb->createNamedParameter(mb_substr($t['filename'], 0, 512)),
					'rel_path' => $qb->createNamedParameter($t['relPath']),
					'size' => $qb->createNamedParameter($t['size'], IQueryBuilder::PARAM_INT),
					'duration' => $qb->createNamedParameter((float)($t['meta']['duration'] ?? 0)),
					'start_offset' => $qb->createNamedParameter($t['startOffset']),
					'mime' => $qb->createNamedParameter(self::mimeFor($t['filename'])),
					'mtime' => $qb->createNamedParameter($t['mtime'], IQueryBuilder::PARAM_INT),
					'meta' => $qb->createNamedParameter(json_encode($meta, JSON_INVALID_UTF8_SUBSTITUTE)),
				]);
				$qb->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Embedded art is cached only when there is no cover image file.
		if ($coverFileId === null && $firstPicture !== null) {
			$this->storeEmbeddedCover($userId, $key, $firstPicture);
		}
		return $key;
	}

	private static function ptype(mixed $v): int {
		return match (true) {
			$v === null => IQueryBuilder::PARAM_NULL,
			is_int($v) => IQueryBuilder::PARAM_INT,
			default => IQueryBuilder::PARAM_STR,
		};
	}

	private function storeEmbeddedCover(string $userId, string $key, array $picture): void {
		try {
			try {
				$folder = $this->appData->getFolder('covers');
			} catch (NotFoundException) {
				$folder = $this->appData->newFolder('covers');
			}
			$ext = str_contains($picture['mime'], 'png') ? 'png' : 'jpg';
			$name = md5($userId) . '-' . $key . '.' . $ext;
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->putContent($picture['data']);
			} else {
				$folder->newFile($name, $picture['data']);
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('abs_items')->set('cover_cache', $qb->createNamedParameter($name))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($key)));
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$this->logger->info('Could not cache embedded cover: ' . $e->getMessage(), ['app' => 'abscompat']);
		}
	}

	/** @param array<string|int, true> $seen item keys found by this scan (numeric keys become ints) */
	private function removeMissing(string $userId, array $seen): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'item_key')->from('abs_items')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$gone = [];
		while ($row = $res->fetch()) {
			if (!isset($seen[(string)$row['item_key']])) {
				$gone[] = (int)$row['id'];
			}
		}
		$res->closeCursor();
		foreach (array_chunk($gone, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('abs_tracks')->where($qb->expr()->in('item_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->delete('abs_items')->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		// Progress rows are kept on purpose: a book moved out and back keeps its position.
		return count($gone);
	}

	/**
	 * Store metadata edits (title, author, series, seriesSeq) as overrides and apply them now.
	 * Keys with null value remove that override. The audio files are not changed.
	 */
	public function updateMetadata(string $userId, string $key, array $changes): ?array {
		$row = $this->getItemRow($userId, $key);
		if ($row === null) {
			return null;
		}
		$ov = json_decode((string)($row['overrides'] ?? ''), true) ?: [];
		foreach (['title', 'author', 'series', 'seriesSeq'] as $k) {
			if (array_key_exists($k, $changes)) {
				$ov[$k] = $changes[$k];
			}
		}
		$cols = ['title' => 'title', 'author' => 'author', 'series' => 'series', 'seriesSeq' => 'series_seq'];
		$qb = $this->db->getQueryBuilder();
		$qb->update('abs_items');
		foreach ($cols as $k => $col) {
			if (array_key_exists($k, $changes)) {
				$v = $changes[$k] === null ? null : mb_substr((string)$changes[$k], 0, $col === 'series_seq' ? 32 : 512);
				if ($k === 'title' && ($v === null || $v === '')) {
					continue;
				}
				$qb->set($col, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR));
			}
		}
		$qb->set('overrides', $qb->createNamedParameter(json_encode($ov)))
			->set('updated_at', $qb->createNamedParameter((int)(microtime(true) * 1000), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return $this->getItemRow($userId, $key);
	}

	/**
	 * Quick incremental rescan when the last one is older than $maxAge seconds, so new books show up
	 * without waiting for the hourly job. Bounded by $timeLimit seconds.
	 */
	public function rescanIfStale(string $userId, int $maxAge = 600, float $timeLimit = 8.0): void {
		$last = $this->userConfig->getValueInt($userId, Application::APP_ID, 'last_scan', 0, true);
		if (time() - $last < $maxAge) {
			return;
		}
		$this->markScanned($userId);
		try {
			$this->scanUser($userId, $timeLimit);
		} catch (\Throwable $e) {
			$this->logger->warning('Rescan failed: ' . $e->getMessage(), ['app' => 'abscompat', 'exception' => $e]);
		}
	}

	// ----- queries -----

	public function hasItems(string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('abs_items')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))->setMaxResults(1);
		return $qb->executeQuery()->fetchOne() !== false;
	}

	public function getItemRow(string $userId, string $key): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('abs_items')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($key)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row ?: null;
	}

	/** @return list<array> */
	public function getItemRows(string $userId, ?string $libraryId = null, ?array $keys = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('abs_items')->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		if ($libraryId !== null) {
			$qb->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId)));
		}
		if ($keys !== null) {
			if ($keys === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('item_key', $qb->createNamedParameter(array_values($keys), IQueryBuilder::PARAM_STR_ARRAY)));
		}
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		return $rows;
	}

	/** @return list<array> */
	public function getTrackRows(int $itemId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('abs_tracks')
			->where($qb->expr()->eq('item_id', $qb->createNamedParameter($itemId, IQueryBuilder::PARAM_INT)))
			->orderBy('track_index', 'ASC');
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		return $rows;
	}

	/** @return array<int, list<array>> item id => tracks */
	public function getTrackRowsFor(array $itemIds): array {
		$out = [];
		foreach (array_chunk(array_map('intval', $itemIds), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('abs_tracks')
				->where($qb->expr()->in('item_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('track_index', 'ASC');
			$res = $qb->executeQuery();
			while ($row = $res->fetch()) {
				$out[(int)$row['item_id']][] = $row;
			}
			$res->closeCursor();
		}
		return $out;
	}

	/** Resolve a file by id inside the user's storage, only if it belongs to the item. */
	public function getItemFile(string $userId, array $item, int $fileId): ?File {
		$allowed = array_map(static fn ($t) => (int)$t['file_id'], $this->getTrackRows((int)$item['id']));
		foreach (['cover_file_id', 'ebook_file_id'] as $col) {
			if ($item[$col] !== null) {
				$allowed[] = (int)$item[$col];
			}
		}
		if (!in_array($fileId, $allowed, true)) {
			return null;
		}
		$n = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		return $n instanceof File && $n->isReadable() ? $n : null;
	}

	public function getCachedCover(string $name): ?\OCP\Files\SimpleFS\ISimpleFile {
		try {
			return $this->appData->getFolder('covers')->getFile($name);
		} catch (NotFoundException) {
			return null;
		}
	}

	public function deleteAllForUser(string $userId): void {
		$keys = array_map(static fn ($r) => (int)$r['id'], $this->getItemRows($userId));
		foreach (array_chunk($keys, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('abs_tracks')->where($qb->expr()->in('item_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		foreach (['abs_items', 'abs_progress', 'abs_sessions'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}
}
