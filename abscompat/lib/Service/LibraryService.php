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
	/** What Audiobookshelf's readers open, best first. */
	public const EBOOK_EXT = ['epub', 'azw3', 'mobi', 'pdf', 'cbz', 'cbr'];
	public const COVER_NAMES = ['cover.jpg', 'cover.jpeg', 'cover.png', 'cover.webp', 'folder.jpg', 'folder.png'];
	/** File name prefix of covers the user chose or that were looked up (others are embedded art). */
	public const CUSTOM_COVER = 'own-';
	/** Details the user can change => column and length, or only length when they are kept in "extra" */
	private const EDITABLE = [
		'title' => ['title', 512], 'subtitle' => ['subtitle', 512], 'author' => ['author', 512], 'narrator' => ['narrator', 512],
		'series' => ['series', 512], 'seriesSeq' => ['series_seq', 32], 'year' => ['published_year', 16], 'description' => ['description', 20000],
		'publisher' => [null, 255], 'language' => [null, 64], 'isbn' => [null, 32], 'asin' => [null, 32],
	];
	private const EDITABLE_LISTS = ['genres', 'tags'];
	private const EDITABLE_FLAGS = ['explicit', 'abridged'];
	/** Columns that decide whether a scanned book has changed */
	private const COMPARED = ['library_id', 'is_file', 'rel_path', 'title', 'subtitle', 'author', 'narrator', 'series', 'series_seq', 'published_year', 'description', 'genres', 'size', 'chapters', 'cover_file_id', 'ebook_file_id', 'extra'];
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
		private EbookReader $ebooks,
		private OpdsLink $opds,
	) {
	}

	/** @var array<string, array>|null overrides of the user being scanned */
	private ?array $scanOverrides = null;

	// ----- settings -----

	/** @return list<string> */
	public function getFolderPaths(string $userId): array {
		$raw = UserValue::string($this->userConfig, $userId, 'folders');
		$list = $raw === '' ? null : json_decode($raw, true);
		if (!is_array($list)) {
			return $this->getDefaultFolderPaths();
		}
		return array_values(array_filter(array_map('strval', $list), static fn ($p) => $p !== ''));
	}

	/** Whether the user picked folders, or uses the instance default. */
	public function usesDefaultFolders(string $userId): bool {
		return UserValue::string($this->userConfig, $userId, 'folders') === '';
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
		return UserValue::lazyInt($this->userConfig, $userId, 'last_scan');
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
		if ($this->opds->hasLibrary($userId)) {
			$out[] = ['id' => OpdsLink::LIBRARY, 'name' => 'Ebooks', 'path' => '/', 'folder' => $userFolder];
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
		$this->scanOverrides = $this->allOverrides($userId);
		foreach ($this->getLibraries($userId) as $lib) {
			$libIds[] = $lib['id'];
			if ($lib['id'] === OpdsLink::LIBRARY) {
				$stats['items'] += $this->scanOpds($userId, $seen);
				continue;
			}
			$books = [];
			$this->findBooks($lib['folder'], 0, $books);
			foreach ($books as $book) {
				if ($timeLimit !== null && microtime(true) - $started > $timeLimit) {
					// Keep what we have; the next run continues. Don't remove anything.
					return $stats;
				}
				// Present on disk, so never "missing", even when reading it fails below.
				$seen[(string)$book['node']->getId()] = true;
				try {
					$key = $this->scanBook($userId, $lib, $book);
					if ($key !== null) {
						$stats['items']++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Scan failed for ' . $book['node']->getPath() . ': ' . $e->getMessage(), ['app' => 'abscompat', 'exception' => $e]);
				}
			}
		}
		$stats['removed'] = $this->removeMissing($userId, $seen, $libIds);
		return $stats;
	}

	/**
	 * Scan one book folder right away (after an upload), without walking the whole library.
	 * Returns the item key, or null when the folder holds no audio.
	 */
	public function scanBookFolder(string $userId, string $libraryPath, string $folder): ?string {
		foreach ($this->getLibraries($userId) as $lib) {
			if ($lib['path'] !== $libraryPath) {
				continue;
			}
			try {
				$node = $this->rootFolder->getUserFolder($userId)->get($folder);
			} catch (NotFoundException) {
				return null;
			}
			$books = [];
			if ($node instanceof Folder) {
				$this->findBooks($node, 1, $books);
			}
			foreach ($books as $book) {
				if ($book['node']->getId() === $node->getId()) {
					return $this->scanBook($userId, $lib, $book);
				}
			}
		}
		return null;
	}

	/**
	 * Book = folder that directly holds audio files (its audio files in sub-folders, e.g. CD1/CD2,
	 * belong to it), or an audio file in the library root. Ebooks belong to the audio in their folder;
	 * where there is none they are books of their own: the folder if it holds one title, else each title.
	 *
	 * @param list<array{node: Node, audio: list<File>, ebooks: list<File>, dir: Folder}> $books
	 */
	private function findBooks(Folder $dir, int $depth, array &$books): void {
		$audio = [];
		$subdirs = [];
		$titles = [];
		foreach ($dir->getDirectoryListing() as $child) {
			if ($child instanceof Folder) {
				$subdirs[] = $child;
			} elseif ($child instanceof File && self::isAudio($child->getName())) {
				$audio[] = $child;
			} elseif ($child instanceof File && self::isEbook($child->getName())) {
				$titles[mb_strtolower(pathinfo($child->getName(), PATHINFO_FILENAME))][] = $child;
			}
		}
		if ($depth === 0) {
			foreach ($audio as $f) {
				$stem = mb_strtolower(pathinfo($f->getName(), PATHINFO_FILENAME));
				$books[] = ['node' => $f, 'audio' => [$f], 'ebooks' => $titles[$stem] ?? [], 'dir' => $dir];
				unset($titles[$stem]);
			}
		} elseif ($audio !== []) {
			foreach ($subdirs as $sd) {
				$this->collectAudio($sd, $audio);
			}
			$books[] = ['node' => $dir, 'audio' => $audio, 'ebooks' => array_merge([], ...array_values($titles)), 'dir' => $dir];
			return;
		}
		$before = count($books);
		if ($depth < 6) {
			foreach ($subdirs as $sd) {
				$this->findBooks($sd, $depth + 1, $books);
			}
		}
		$inner = count($books) > $before;
		if ($depth > 0 && !$inner && count($titles) === 1) {
			$books[] = ['node' => $dir, 'audio' => [], 'ebooks' => reset($titles), 'dir' => $dir];
			return;
		}
		foreach ($titles as $files) {
			usort($files, static fn ($a, $b) => self::ebookRank($a->getName()) <=> self::ebookRank($b->getName()));
			// Next to other books, a lone PDF or comic is rather a map or booklet than a book.
			if ($depth > 0 && $inner && self::ebookRank($files[0]->getName()) > 2) {
				continue;
			}
			$books[] = ['node' => $files[0], 'audio' => [], 'ebooks' => $files, 'dir' => $dir];
		}
	}

	public static function isEbook(string $name): bool {
		return self::ebookRank($name) < count(self::EBOOK_EXT);
	}

	private static function ebookRank(string $name): int {
		$i = array_search(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EBOOK_EXT, true);
		return $i === false ? count(self::EBOOK_EXT) : $i;
	}

	/** @param list<array> $files with "name" @return list<array> the best format first */
	public static function sortEbooks(array $files): array {
		usort($files, static fn ($a, $b) => self::ebookRank($a['name']) <=> self::ebookRank($b['name']) ?: strnatcasecmp($a['name'], $b['name']));
		return $files;
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

	/** @param array{node: Node, audio: list<File>, ebooks: list<File>, dir: Folder} $book from findBooks() */
	private function scanBook(string $userId, array $lib, array $book): ?string {
		['node' => $node, 'audio' => $audio, 'dir' => $dir] = $book;
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
		// Repeated numbers (several discs without disc tags) don't give an order either.
		$numbers = [];
		foreach ($tracks as $t) {
			$tags = $t['meta']['tags'] ?? [];
			if (isset($tags['track'])) {
				$numbers[(int)($tags['disc'] ?? 0) . '-' . (int)$tags['track']] = true;
			}
		}
		$allNumbered = count($numbers) === count($tracks);
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

		// Read again by a newer version of this app: the first file is enough, it has the book's tags.
		$known = $tracks[0]['meta'] ?? ['v' => MetadataExtractor::VERSION];
		if (!$changed && (int)($known['v'] ?? 1) < MetadataExtractor::VERSION) {
			$fresh = $this->extractor->extract($tracks[0]['file'], false);
			// A file that can't be read right now keeps what is known about it.
			if (isset($fresh['v'])) {
				unset($fresh['picture']);
				if ($fresh['duration'] <= 0 && $known['duration'] > 0) {
					$fresh = ['v' => $fresh['v']] + $known;
				}
				$tracks[0]['meta'] = $fresh;
				$changed = true;
			}
		}

		// Folder-side files: ebooks and cover.
		$own = self::sortEbooks(array_map(static fn (File $f) => [
			'id' => (int)$f->getId(), 'name' => $f->getName(), 'path' => ltrim(substr($f->getPath(), strlen($libRoot)), '/'),
			'size' => (int)$f->getSize(), 'mtime' => (int)$f->getMTime(),
		], $book['ebooks']));
		$ebookOnly = $tracks === [];
		$coverFileId = null;
		if (!$isFile) {
			$listing = $dir->getDirectoryListing();
			foreach ($listing as $child) {
				if ($coverFileId === null && $child instanceof File && in_array(strtolower($child->getName()), self::COVER_NAMES, true)) {
					$coverFileId = (int)$child->getId();
				}
			}
			foreach ($listing as $child) {
				if ($coverFileId === null && $child instanceof File && preg_match('/\.(jpe?g|png|webp)$/i', $child->getName())) {
					$coverFileId = (int)$child->getId();
				}
			}
		}

		// Book-level metadata.
		$before = $existing === null ? [] : Serializer::extra($existing);
		$extra = [];
		if ($ebookOnly) {
			$sig = implode('-', [$own[0]['id'], $own[0]['mtime'], $own[0]['size']]);
			if (($before['sig'] ?? '') === $sig) {
				$first = $before['fileTags'] ?? [];
			} else {
				foreach ($book['ebooks'] as $f) {
					if ((int)$f->getId() === $own[0]['id']) {
						['tags' => $first, 'picture' => $firstPicture] = $this->ebooks->read($f);
					}
				}
			}
			$extra += ['ebookOnly' => true, 'sig' => $sig, 'fileTags' => $first ?? []];
			$first = $extra['fileTags'];
		} else {
			$first = $tracks[0]['meta']['tags'] ?? [];
		}
		$parts = $relPath === '' ? [] : explode('/', $isFile ? preg_replace('/\.[^.\/]+$/', '', $relPath) : $relPath);
		$folderTitle = $parts !== [] ? end($parts) : $node->getName();
		$folderAuthor = count($parts) >= 2 ? $parts[0] : null;
		$folderSeries = count($parts) >= 3 ? $parts[count($parts) - 2] : null;
		$seriesSeq = null;
		if ($folderSeries !== null) {
			['title' => $folderTitle, 'seq' => $seriesSeq] = SeriesParser::fromFolder($folderTitle);
		}
		$multi = count($tracks) > 1;
		$allSeries = SeriesParser::allFromTags($first);
		$tagged = array_shift($allSeries);
		$values = [
			'user_id' => $userId,
			'library_id' => $lib['id'],
			'item_key' => $key,
			'is_file' => $isFile ? 1 : 0,
			'rel_path' => $relPath,
			'title' => $first['album'] ?? (!$multi ? ($first['title'] ?? null) : null) ?? $folderTitle,
			'subtitle' => $first['subtitle'] ?? null,
			'author' => $first['albumArtist'] ?? $first['artist'] ?? $folderAuthor,
			'narrator' => $first['composer'] ?? null,
			'series' => $tagged['name'] ?? $folderSeries,
			'series_seq' => $tagged['seq'] ?? $seriesSeq,
			'published_year' => isset($first['year']) ? preg_replace('/^(\d{4}).*/', '$1', $first['year']) : null,
			'description' => $first['description'] ?? null,
			'genres' => isset($first['genre']) ? array_values(array_filter(array_map('trim', preg_split('/[;\/]/', $first['genre'])))) : [],
		];
		foreach (['publisher', 'language', 'isbn', 'asin'] as $k) {
			if (isset($first[$k])) {
				$extra[$k] = $first[$k];
			}
		}
		if ($allSeries !== []) {
			$extra['series'] = $allSeries;
		}
		// Edits made from a client (PATCH /api/items/:id/media), the page or the upload form win over the files.
		$ov = $this->scanOverrides !== null ? ($this->scanOverrides[$key] ?? []) : $this->getOverrides($userId, $key);
		[$values, $extra] = self::applyEdits($values, $extra, $ov);
		$title = (string)$values['title'];

		// Without an ebook of its own, an audiobook gets the matching one from OPDS+.
		if ($own === [] && !$ebookOnly) {
			$own = $this->opdsEbooks($userId, $title, $values['author'], $ov, $before['ebooks'] ?? []);
		}
		if ($own !== []) {
			$extra['ebooks'] = $own;
		}
		$ebookFileId = $own[0]['id'] ?? null;
		if (array_key_exists('ebook', $ov) && ($ov['ebook'] === 0 || in_array($ov['ebook'], array_column($own, 'id'), true))) {
			$ebookFileId = $ov['ebook'] ?: null;
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
		if ($ebookOnly) {
			$size = array_sum(array_column($own, 'size'));
		}
		$now = (int)(microtime(true) * 1000);
		$values = self::toColumns($values, $extra) + [
			'duration' => $duration,
			'size' => $size,
			'chapters' => json_encode($chapters, JSON_INVALID_UTF8_SUBSTITUTE),
			'cover_file_id' => $coverFileId,
			'ebook_file_id' => $ebookFileId,
			'updated_at' => $now,
			'mtime' => (int)$node->getMTime(),
		];
		if (!$changed && !self::differs($existing, $values)) {
			return $key;
		}

		$this->db->beginTransaction();
		try {
			if ($existing === null) {
				$values['added_at'] = $now;
				if (isset($ov['cover'])) {
					// A book that left and came back keeps the cover chosen for it.
					$values['cover_cache'] = $ov['cover'];
				}
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

	/** The stored form: lengths cut, lists as JSON. */
	private static function toColumns(array $values, array $extra): array {
		foreach (self::EDITABLE as [$col, $max]) {
			if ($col !== null && isset($values[$col])) {
				$values[$col] = mb_substr((string)$values[$col], 0, $max);
			}
		}
		$values['genres'] = json_encode($values['genres'], JSON_INVALID_UTF8_SUBSTITUTE);
		$values['extra'] = $extra === [] ? null : json_encode($extra, JSON_INVALID_UTF8_SUBSTITUTE);
		return $values;
	}

	private static function differs(array $row, array $values): bool {
		foreach (self::COMPARED as $c) {
			if ((string)($row[$c] ?? '') !== (string)($values[$c] ?? '')) {
				return true;
			}
		}
		return abs((float)$row['duration'] - (float)$values['duration']) > 0.01;
	}

	/**
	 * @param array<string, mixed> $values columns, with genres as a list
	 * @param array<string, mixed> $ov overrides
	 * @return array{0: array, 1: array} values and extra
	 */
	private static function applyEdits(array $values, array $extra, array $ov): array {
		foreach (self::EDITABLE as $k => [$col, $max]) {
			if (!array_key_exists($k, $ov) || ($k === 'title' && (string)$ov[$k] === '')) {
				continue;
			}
			if ($col !== null) {
				$values[$col] = $ov[$k];
			} elseif ($ov[$k] === null) {
				unset($extra[$k]);
			} else {
				$extra[$k] = $ov[$k];
			}
		}
		if (array_key_exists('genres', $ov)) {
			$values['genres'] = $ov['genres'];
		}
		foreach (['tags', 'explicit', 'abridged'] as $k) {
			if (!empty($ov[$k])) {
				$extra[$k] = $ov[$k];
			} elseif (array_key_exists($k, $ov)) {
				unset($extra[$k]);
			}
		}
		// Further series. Who sets the series without them (an older version, the upload form) means only that one.
		if (array_key_exists('moreSeries', $ov) || array_key_exists('series', $ov)) {
			unset($extra['series']);
			if (!empty($ov['moreSeries'])) {
				$extra['series'] = $ov['moreSeries'];
			}
		}
		return [$values, $extra];
	}

	/**
	 * Edits as they come from a client, cleaned: unknown keys dropped, empty text becomes null.
	 * @return array<string, mixed>
	 */
	public static function cleanEdits(array $in): array {
		$text = static fn (mixed $v, int $max): string => is_scalar($v) ? mb_substr(MetadataExtractor::cleanText((string)$v), 0, $max) : '';
		$out = [];
		foreach (self::EDITABLE as $k => [, $max]) {
			if (!array_key_exists($k, $in) || !(is_scalar($in[$k]) || $in[$k] === null)) {
				continue;
			}
			$v = $text($in[$k], $max);
			if ($k !== 'title' || $v !== '') {
				$out[$k] = $v === '' ? null : $v;
			}
		}
		foreach (self::EDITABLE_LISTS as $k) {
			if (is_array($in[$k] ?? null)) {
				$list = array_map(static fn ($v) => $text($v, 100), array_slice($in[$k], 0, 100));
				$out[$k] = array_values(array_unique(array_filter($list, static fn ($v) => $v !== '')));
			}
		}
		foreach (self::EDITABLE_FLAGS as $k) {
			if (array_key_exists($k, $in)) {
				$out[$k] = filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);
			}
		}
		if (is_array($in['moreSeries'] ?? null)) {
			$out['moreSeries'] = [];
			foreach (array_slice($in['moreSeries'], 0, 20) as $s) {
				$name = is_array($s) ? $text($s['name'] ?? '', 512) : '';
				if ($name !== '') {
					$seq = $text($s['seq'] ?? '', 32);
					$out['moreSeries'][] = ['name' => $name, 'seq' => $seq === '' ? null : $seq];
				}
			}
		}
		if (array_key_exists('series', $out) && $out['series'] === null) {
			$out['seriesSeq'] = null;
		}
		return $out;
	}

	// ----- ebooks from OPDS+ -----

	/** @return list<array> ebook files for an audiobook */
	private function opdsEbooks(string $userId, string $title, ?string $author, array $ov, array $before): array {
		$chosen = $ov['opdsEbook'] ?? null;
		if ($chosen === 0 || !$this->opds->links($userId)) {
			return [];
		}
		if ($this->opds->works($userId) === null) {
			// Not readable right now: keep what was found before.
			return array_values(array_filter($before, static fn ($e) => !empty($e['opds'])));
		}
		$work = $chosen !== null ? $this->opds->byFile($userId, (int)$chosen) : null;
		return ($work ?? $this->opds->find($userId, $title, $author))['files'] ?? [];
	}

	/**
	 * The ebooks of OPDS+ as a library, without those that belong to an audiobook or lie in a library folder.
	 * @param array<string|int, true> $seen
	 */
	private function scanOpds(string $userId, array &$seen): int {
		$rows = [];
		$taken = [];
		foreach ($this->getItemRows($userId) as $r) {
			if ((string)$r['library_id'] === OpdsLink::LIBRARY) {
				$rows[(string)$r['item_key']] = $r;
			} else {
				$taken += array_fill_keys(array_column(Serializer::extra($r)['ebooks'] ?? [], 'id'), true);
			}
		}
		$works = $this->opds->works($userId);
		if ($works === null) {
			$seen += array_fill_keys(array_keys($rows), true);
			return count($rows);
		}
		$n = 0;
		foreach ($works as $w) {
			$key = (string)$w['files'][0]['id'];
			if (array_intersect_key($taken, array_fill_keys(array_column($w['files'], 'id'), true)) !== []) {
				continue;
			}
			$seen[$key] = true;
			$n++;
			$extra = ['ebookOnly' => true, 'ebooks' => $w['files']];
			if ($w['cover']) {
				$extra['opdsCover'] = (int)$key;
			}
			foreach (['publisher', 'language', 'isbn'] as $k) {
				if ($w[$k] !== '') {
					$extra[$k] = $w[$k];
				}
			}
			$null = static fn (string $v) => $v === '' ? null : $v;
			$values = [
				'user_id' => $userId, 'library_id' => OpdsLink::LIBRARY, 'item_key' => $key, 'is_file' => 1, 'rel_path' => $w['files'][0]['path'],
				'title' => $w['title'] !== '' ? $w['title'] : pathinfo($w['files'][0]['name'], PATHINFO_FILENAME),
				'subtitle' => null, 'author' => $null($w['author']), 'narrator' => null, 'series' => $null($w['series']),
				'series_seq' => $w['series'] !== '' ? $null($w['seriesSeq']) : null, 'published_year' => $null($w['year']),
				'description' => $null($w['description']), 'genres' => $w['genres'],
			];
			$ov = $this->scanOverrides[$key] ?? [];
			[$values, $extra] = self::applyEdits($values, $extra, $ov);
			$ebook = $w['files'][0]['id'];
			if (array_key_exists('ebook', $ov) && ($ov['ebook'] === 0 || in_array($ov['ebook'], array_column($w['files'], 'id'), true))) {
				$ebook = $ov['ebook'] ?: null;
			}
			$values = self::toColumns($values, $extra) + [
				'duration' => 0.0, 'size' => array_sum(array_column($w['files'], 'size')), 'chapters' => '[]', 'cover_file_id' => null,
				'ebook_file_id' => $ebook, 'updated_at' => (int)(microtime(true) * 1000), 'mtime' => $w['files'][0]['mtime'],
			];
			$row = $rows[$key] ?? $this->getItemRow($userId, $key);
			if ($row !== null && !self::differs($row, $values)) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			if ($row === null) {
				$values['added_at'] = $values['updated_at'];
				if (isset($ov['cover'])) {
					$values['cover_cache'] = $ov['cover'];
				}
				$qb->insert('abs_items');
				foreach ($values as $k => $v) {
					$qb->setValue($k, $qb->createNamedParameter($v, self::ptype($v)));
				}
			} else {
				$qb->update('abs_items');
				foreach ($values as $k => $v) {
					$qb->set($k, $qb->createNamedParameter($v, self::ptype($v)));
				}
				$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			}
			$qb->executeStatement();
		}
		return $n;
	}

	/** Choose the OPDS+ ebook of an audiobook: a file id, 0 for none, null for the automatic match. */
	public function setOpdsEbook(string $userId, string $key, ?int $fileId): void {
		$this->patchOverrides($userId, $key, ['opdsEbook' => $fileId, 'ebook' => null]);
		$row = $this->getItemRow($userId, $key);
		if ($row !== null) {
			$extra = Serializer::extra($row);
			$own = array_filter($extra['ebooks'] ?? [], static fn ($e) => empty($e['opds']));
			if ($own === []) {
				$files = $this->opdsEbooks($userId, (string)$row['title'], $row['author'], $this->getOverrides($userId, $key), []);
				$extra['ebooks'] = $files;
				if ($files === []) {
					unset($extra['ebooks']);
				}
				$this->updateRow((int)$row['id'], ['extra' => $extra === [] ? null : json_encode($extra, JSON_INVALID_UTF8_SUBSTITUTE), 'ebook_file_id' => $files[0]['id'] ?? null]);
			}
		}
	}

	/** Make one of the book's ebooks the one to read, or none. */
	public function setPrimaryEbook(string $userId, string $key, int $fileId): void {
		$row = $this->getItemRow($userId, $key);
		if ($row !== null && ($fileId === 0 || in_array($fileId, array_column(Serializer::extra($row)['ebooks'] ?? [], 'id'), true))) {
			$this->patchOverrides($userId, $key, ['ebook' => $fileId]);
			$this->updateRow((int)$row['id'], ['ebook_file_id' => $fileId ?: null]);
		}
	}

	private function updateRow(int $id, array $values): void {
		$values['updated_at'] = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->update('abs_items');
		foreach ($values as $k => $v) {
			$qb->set($k, $qb->createNamedParameter($v, self::ptype($v)));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
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
			$folder = $this->coverFolder();
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

	/**
	 * Drop items that are gone from the libraries this scan walked. Items of a library folder that
	 * was not found (renamed, unmounted) stay: they are hidden until the folder is back.
	 *
	 * @param array<string|int, true> $seen item keys found by this scan (numeric keys become ints)
	 * @param list<string> $libIds libraries that were walked completely
	 */
	private function removeMissing(string $userId, array $seen, array $libIds): int {
		if ($libIds === []) {
			return 0;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'item_key')->from('abs_items')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('library_id', $qb->createNamedParameter($libIds, IQueryBuilder::PARAM_STR_ARRAY)));
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
		// Progress, bookmarks and metadata edits are kept on purpose: a book moved out and back keeps them.
		return count($gone);
	}

	/**
	 * Store edits (see cleanEdits) as overrides and apply them now. The files are not changed.
	 */
	public function updateMetadata(string $userId, string $key, array $changes): ?array {
		$row = $this->getItemRow($userId, $key);
		if ($row === null) {
			return null;
		}
		if (array_key_exists('series', $changes)) {
			$changes += ['moreSeries' => []];
		}
		$this->saveOverrides($userId, $key, $changes + $this->getOverrides($userId, $key));
		$values = array_intersect_key($row, array_flip(['title', 'subtitle', 'author', 'narrator', 'series', 'series_seq', 'published_year', 'description']));
		$values['genres'] = json_decode((string)$row['genres'], true) ?: [];
		[$values, $extra] = self::applyEdits($values, Serializer::extra($row), $changes);
		$this->updateRow((int)$row['id'], self::toColumns($values, $extra));
		return $this->getItemRow($userId, $key);
	}

	/** @return array<string, array> item key => overrides */
	private function allOverrides(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('item_key', 'data')->from('abs_overrides')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(string)$row['item_key']] = json_decode((string)$row['data'], true) ?: [];
		}
		$res->closeCursor();
		return $out;
	}

	/** @return array<string, mixed> edits (EDITABLE), cover (file name), coverTried (time) */
	public function getOverrides(string $userId, string $key): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('data')->from('abs_overrides')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($key)));
		$res = $qb->executeQuery();
		$data = $res->fetchOne();
		$res->closeCursor();
		return is_string($data) ? (json_decode($data, true) ?: []) : [];
	}

	/** Change some override values; null removes one. */
	public function patchOverrides(string $userId, string $key, array $values): void {
		$ov = $this->getOverrides($userId, $key);
		foreach ($values as $k => $v) {
			if ($v === null) {
				unset($ov[$k]);
			} else {
				$ov[$k] = $v;
			}
		}
		$this->saveOverrides($userId, $key, $ov);
	}

	private function coverFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('covers');
		} catch (NotFoundException) {
			return $this->appData->newFolder('covers');
		}
	}

	/** Use this JPEG as the book's cover, or remove it again with null. Kept across rescans. */
	public function setCustomCover(string $userId, string $key, ?string $jpeg): void {
		$name = self::CUSTOM_COVER . md5($userId) . '-' . $key . '.jpg';
		$folder = $this->coverFolder();
		if ($jpeg !== null) {
			if ($folder->fileExists($name)) {
				$folder->getFile($name)->putContent($jpeg);
			} else {
				$folder->newFile($name, $jpeg);
			}
		} elseif ($folder->fileExists($name)) {
			$folder->getFile($name)->delete();
		}
		$this->patchOverrides($userId, $key, ['cover' => $jpeg === null ? null : $name, 'coverTried' => $jpeg === null ? time() : null]);
		$qb = $this->db->getQueryBuilder();
		$qb->update('abs_items')
			->set('cover_cache', $qb->createNamedParameter($jpeg === null ? null : $name, self::ptype($jpeg === null ? null : $name)))
			->set('updated_at', $qb->createNamedParameter((int)(microtime(true) * 1000), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	/** Users that have scanned books. @return list<string> */
	public function usersWithItems(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from('abs_items');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[] = (string)$row['user_id'];
		}
		$res->closeCursor();
		return $out;
	}

	/** Items of the library folders that exist. @return list<array> */
	public function visibleItemRows(string $userId): array {
		$ids = array_column($this->getLibraries($userId), 'id');
		return array_values(array_filter($this->getItemRows($userId), static fn ($r) => in_array((string)$r['library_id'], $ids, true)));
	}

	private function saveOverrides(string $userId, string $key, array $ov): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('abs_overrides')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($key)));
		$qb->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->insert('abs_overrides')->values([
			'user_id' => $qb->createNamedParameter($userId),
			'item_key' => $qb->createNamedParameter($key),
			'data' => $qb->createNamedParameter(json_encode($ov)),
		]);
		$qb->executeStatement();
	}

	/**
	 * Quick incremental rescan when the last one is older than $maxAge seconds, so new books show up
	 * without waiting for the hourly job. Bounded by $timeLimit seconds.
	 */
	public function rescanIfStale(string $userId, int $maxAge = 600, float $timeLimit = 8.0): void {
		$last = UserValue::lazyInt($this->userConfig, $userId, 'last_scan');
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

	/** Whether the current library folders have scanned books; rows of removed folders don't count. */
	public function hasItems(string $userId): bool {
		$libIds = array_values(array_diff(array_column($this->getLibraries($userId), 'id'), [OpdsLink::LIBRARY]));
		if ($libIds === []) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('abs_items')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('library_id', $qb->createNamedParameter($libIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
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
		$allowed = array_merge($allowed, array_column(Serializer::extra($item)['ebooks'] ?? [], 'id'));
		if (!in_array($fileId, $allowed, true)) {
			return null;
		}
		$n = $this->rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
		return $n instanceof File && $n->isReadable() ? $n : null;
	}

	/** A cover OPDS+ keeps for one of the user's ebooks. */
	public function opdsCover(string $userId, int $fileId): ?File {
		return $this->opds->cover($userId, $fileId);
	}

	public function getCachedCover(string $name): ?\OCP\Files\SimpleFS\ISimpleFile {
		try {
			return $this->appData->getFolder('covers')->getFile($name);
		} catch (NotFoundException) {
			return null;
		}
	}

	public function deleteAllForUser(string $userId): void {
		$this->deleteCoverFiles($userId);
		$keys = array_map(static fn ($r) => (int)$r['id'], $this->getItemRows($userId));
		foreach (array_chunk($keys, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('abs_tracks')->where($qb->expr()->in('item_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		foreach (['abs_items', 'abs_overrides', 'abs_progress', 'abs_sessions', 'abs_lists'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($table)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
			$qb->executeStatement();
		}
	}

	private function deleteCoverFiles(string $userId): void {
		try {
			foreach ($this->appData->getFolder('covers')->getDirectoryListing() as $file) {
				if (preg_match('/^(?:' . self::CUSTOM_COVER . ')?' . md5($userId) . '-/', $file->getName())) {
					$file->delete();
				}
			}
		} catch (\Throwable $e) {
			if (!$e instanceof NotFoundException) {
				$this->logger->warning('Could not delete covers: ' . $e->getMessage(), ['app' => 'abscompat']);
			}
		}
	}
}
