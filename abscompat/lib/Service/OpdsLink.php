<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Ebooks the OPDS+ app has indexed, for the same user.
 *
 * Strictly read-only, so OPDS+ never notices: only SELECTs on its book table and reading of covers it
 * has already stored. None of its classes are used (they scan and save settings), and not
 * IAppDataFactory either (asking for another app's folder creates it). Files are opened through
 * the user's own files, with the user's permissions.
 */
class OpdsLink {
	/** Library id of the optional ebook library. */
	public const LIBRARY = 'opds';

	/** @var array<string, ?list<array>> */
	private array $works = [];
	/** @var array<string, array<string, list<int>>> */
	private array $index = [];

	public function __construct(
		private IDBConnection $db,
		private IAppManager $apps,
		private IUserManager $users,
		private IUserConfig $userConfig,
		private IConfig $config,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	public function available(string $userId): bool {
		$user = $this->users->get($userId);
		try {
			return $user !== null && $this->apps->isEnabledForUser('opds', $user) && $this->db->tableExists('opds_books');
		} catch (\Throwable) {
			return false;
		}
	}

	/** Give audiobooks the matching ebook. On unless the user switches it off. */
	public function links(string $userId): bool {
		return !UserValue::bool($this->userConfig, $userId, 'opds_no_links') && $this->available($userId);
	}

	/** Also offer the other ebooks, as a library of their own. Off unless the user switches it on. */
	public function hasLibrary(string $userId): bool {
		return UserValue::bool($this->userConfig, $userId, 'opds_library') && $this->available($userId);
	}

	public function configure(string $userId, bool $links, bool $library): void {
		$this->userConfig->setValueBool($userId, Application::APP_ID, 'opds_no_links', !$links);
		$this->userConfig->setValueBool($userId, Application::APP_ID, 'opds_library', $library);
	}

	/**
	 * One entry per book; several formats of a book are one entry.
	 * @return ?list<array> null when the index could not be read
	 */
	public function works(string $userId): ?array {
		if (array_key_exists($userId, $this->works)) {
			return $this->works[$userId];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from('opds_books')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->orderBy('id', 'ASC');
			$res = $qb->executeQuery();
			$rows = $res->fetchAll();
			$res->closeCursor();
			return $this->works[$userId] = self::group($rows);
		} catch (\Throwable $e) {
			$this->logger->info('The OPDS+ book list could not be read: ' . $e->getMessage(), ['app' => 'abscompat']);
			return $this->works[$userId] = null;
		}
	}

	/** @param list<array> $rows of opds_books @return list<array> */
	public static function group(array $rows): array {
		$groups = [];
		foreach ($rows as $r) {
			if (!in_array((string)$r['format'], LibraryService::EBOOK_EXT, true)) {
				continue;
			}
			// "work" ties the formats together since OPDS+ 1.3.
			$key = (string)($r['work'] ?? '') ?: $r['parent'] . '/' . mb_strtolower(pathinfo((string)$r['name'], PATHINFO_FILENAME));
			$groups[$key][] = $r;
		}
		$out = [];
		foreach ($groups as $group) {
			$files = LibraryService::sortEbooks(array_map(static fn ($r) => [
				'id' => (int)$r['file_id'], 'name' => (string)$r['name'], 'path' => ltrim((string)$r['path'], '/'),
				'size' => (int)$r['size'], 'mtime' => (int)$r['mtime'], 'opds' => true,
			], $group));
			$r = $group[0];
			foreach ($group as $g) {
				if ((int)$g['file_id'] === $files[0]['id']) {
					$r = $g;
				}
			}
			$text = static fn (string $k) => MetadataExtractor::cleanText((string)($r[$k] ?? ''));
			$out[] = [
				'files' => $files,
				'title' => $text('title'),
				// Authors are stored as "A & B".
				'author' => str_replace(' & ', '; ', $text('author')),
				'series' => $text('series'),
				'seriesSeq' => (float)($r['series_index'] ?? 0) > 0 ? (string)(float)$r['series_index'] : '',
				'genres' => array_values(array_filter(array_map('trim', explode(';', $text('subjects'))), static fn ($g) => $g !== '')),
				'language' => $text('language'),
				'publisher' => $text('publisher'),
				'year' => (string)((int)($r['pubyear'] ?? 0) ?: ''),
				'description' => trim(html_entity_decode(strip_tags($text('description')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
				'isbn' => $text('isbn'),
				'cover' => (int)($r['has_cover'] ?? 0) === 1,
			];
		}
		return $out;
	}

	/** The ebook with the same title and author, like covers: both must match. */
	public function find(string $userId, string $title, ?string $author): ?array {
		$works = $this->works($userId);
		if (!$works) {
			return null;
		}
		if (!isset($this->index[$userId])) {
			$this->index[$userId] = [];
			foreach ($works as $i => $w) {
				foreach (array_unique([CoverService::norm($w['title'], false), CoverService::norm($w['title'], true)]) as $t) {
					$this->index[$userId][$t][] = $i;
				}
			}
		}
		$found = CoverService::pick(array_map(static fn ($i) => $works[$i] + ['i' => $i], $this->index[$userId][CoverService::norm($title, false)] ?? []), $title, $author);
		if ($found === null) {
			// The audiobook may be the one that has a subtitle.
			$short = CoverService::norm($title, true);
			$found = $short === CoverService::norm($title, false) ? null
				: CoverService::pick(array_map(static fn ($i) => $works[$i] + ['i' => $i], $this->index[$userId][$short] ?? []), (string)preg_replace('/\s*(?::|\s[-–—]\s).*$/u', '', $title), $author);
		}
		return $found === null ? null : $works[$found['i']];
	}

	public function byFile(string $userId, int $fileId): ?array {
		foreach ($this->works($userId) ?? [] as $w) {
			if (in_array($fileId, array_column($w['files'], 'id'), true)) {
				return $w;
			}
		}
		return null;
	}

	/** The cover OPDS+ has stored for a file, if any. */
	public function cover(string $userId, int $fileId): ?File {
		try {
			$instance = $this->config->getSystemValueString('instanceid');
			$node = $instance === '' ? null : $this->rootFolder->get('/appdata_' . $instance . '/opds/covers-' . hash('sha256', $userId) . '/' . $fileId . '.cover');
			return $node instanceof File ? $node : null;
		} catch (\Throwable) {
			return null;
		}
	}
}
