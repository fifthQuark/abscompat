<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;

/**
 * Builds Audiobookshelf-shaped JSON. Field names follow ABS server v2.x "old JSON" models;
 * every non-null field of the Android app's Kotlin data classes is always present.
 * Books never carry podcast-only keys (the app picks Book vs Podcast by key deduction).
 */
class Serializer {
	public static function seriesId(string $name): string {
		return 'ser_' . substr(md5(mb_strtolower(trim($name))), 0, 16);
	}

	public static function authorId(string $name): string {
		return 'aut_' . substr(md5(mb_strtolower(trim($name))), 0, 16);
	}

	/** @return list<string> */
	public static function splitNames(?string $s): array {
		if ($s === null || trim($s) === '') {
			return [];
		}
		$parts = preg_split('/\s*(?:;|&|,(?!\s*(?:jr|sr)\b)|\band\b)\s*/iu', $s) ?: [];
		return array_values(array_unique(array_filter(array_map('trim', $parts), static fn ($p) => $p !== '')));
	}

	public static function lastFirst(string $name): string {
		$parts = preg_split('/\s+/', trim($name)) ?: [];
		if (count($parts) < 2) {
			return $name;
		}
		$last = array_pop($parts);
		return $last . ', ' . implode(' ', $parts);
	}

	public static function ignorePrefix(string $title): string {
		if (preg_match('/^(the|a|an)\s+(.+)$/i', $title, $m)) {
			return $m[2] . ', ' . $m[1];
		}
		return $title;
	}

	/** What is kept next to the columns: series (further ones), tags, ebooks, publisher, ... */
	public static function extra(array $item): array {
		$e = json_decode((string)($item['extra'] ?? ''), true);
		return is_array($e) ? $e : [];
	}

	/** @return list<array{name: string, seq: ?string}> all series of a book, the main one first */
	public static function seriesList(array $item): array {
		$out = [];
		if (trim((string)($item['series'] ?? '')) !== '') {
			$out[] = ['name' => trim((string)$item['series']), 'seq' => ($item['series_seq'] ?? '') === '' ? null : $item['series_seq']];
		}
		return array_merge($out, self::extra($item)['series'] ?? []);
	}

	public static function isEbookOnly(array $item): bool {
		return !empty(self::extra($item)['ebookOnly']);
	}

	/** @param list<string> $hiddenSeries ids */
	public static function user(string $userId, string $displayName, ?string $email, array $progressRows, array $bookmarks, array $hiddenSeries = []): array {
		return [
			'id' => $userId,
			'username' => $userId,
			'email' => $email,
			'type' => 'user',
			'token' => null,
			'mediaProgress' => array_values(array_map(static fn ($r) => ProgressService::toJson($r, $userId), $progressRows)),
			'seriesHideFromContinueListening' => $hiddenSeries,
			'bookmarks' => $bookmarks,
			'isActive' => true,
			'isLocked' => false,
			'lastSeen' => (int)(microtime(true) * 1000),
			'createdAt' => 0,
			'permissions' => [
				'download' => true,
				// Metadata edits are stored as overrides in Nextcloud; files are never written.
				'update' => true,
				'delete' => false,
				'upload' => false,
				'createEreader' => false,
				'accessAllLibraries' => true,
				'accessAllTags' => true,
				'accessExplicitContent' => true,
			],
			'librariesAccessible' => [],
			'itemTagsSelected' => [],
			'hasOpenIDLink' => false,
			'displayName' => $displayName,
		];
	}

	public static function serverSettings(string $language): array {
		return [
			'id' => 'server-settings',
			'scannerFindCovers' => false,
			'scannerCoverProvider' => 'google',
			'scannerParseSubtitle' => false,
			'scannerPreferMatchedMetadata' => false,
			'scannerDisableWatcher' => true,
			'storeCoverWithItem' => true,
			'storeMetadataWithItem' => false,
			'metadataFileFormat' => 'json',
			'rateLimitLoginRequests' => 10,
			'rateLimitLoginWindow' => 600000,
			'allowIframe' => false,
			'backupSchedule' => false,
			'backupsToKeep' => 2,
			'maxBackupSize' => 1,
			'loggerDailyLogsToKeep' => 7,
			'loggerScannerLogsToKeep' => 2,
			'homeBookshelfView' => 1,
			'bookshelfView' => 1,
			'podcastEpisodeSchedule' => '0 * * * *',
			'sortingIgnorePrefix' => true,
			'sortingPrefixes' => ['the', 'a', 'an'],
			'chromecastEnabled' => false,
			'dateFormat' => 'MM/dd/yyyy',
			'timeFormat' => 'HH:mm',
			'language' => $language,
			'logLevel' => 2,
			'version' => Application::ABS_VERSION,
			'buildNumber' => 1,
			'authLoginCustomMessage' => null,
			'authActiveAuthMethods' => ['local'],
			'authOpenIDAutoLaunch' => false,
			'authOpenIDAutoRegister' => false,
			'authOpenIDButtonText' => 'Login with OpenId',
			'authOpenIDMobileRedirectURIs' => [],
		];
	}

	public static function library(array $lib, int $displayOrder, ?array $stats = null): array {
		$out = [
			'id' => $lib['id'],
			'name' => $lib['name'],
			'folders' => [[
				'id' => 'fol_' . $lib['id'],
				'fullPath' => $lib['path'],
				'libraryId' => $lib['id'],
				'addedAt' => 0,
			]],
			'displayOrder' => $displayOrder,
			'icon' => $lib['id'] === OpdsLink::LIBRARY ? 'books-1' : 'audiobookshelf',
			'mediaType' => 'book',
			'provider' => 'google',
			'settings' => [
				// 1 = square, 0 = book covers.
				'coverAspectRatio' => $lib['id'] === OpdsLink::LIBRARY ? 0 : 1,
				'disableWatcher' => true,
				'skipMatchingMediaWithAsin' => false,
				'skipMatchingMediaWithIsbn' => false,
				'autoScanCronExpression' => null,
				'audiobooksOnly' => false,
				'epubsAllowScriptedContent' => false,
				'hideSingleBookSeries' => false,
				'onlyShowLaterBooksInContinueSeries' => false,
				'metadataPrecedence' => ['folderStructure', 'audioMetatags'],
				'markAsFinishedPercentComplete' => null,
				'markAsFinishedTimeRemaining' => 10,
			],
			'lastScan' => null,
			'lastScanVersion' => Application::ABS_VERSION,
			'createdAt' => 0,
			'lastUpdate' => 0,
		];
		if ($stats !== null) {
			$out['stats'] = $stats;
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public static function bookMetadata(array $item, bool $minified): array {
		$title = (string)($item['title'] ?? '');
		$authors = self::splitNames($item['author'] ?? null);
		$narrators = self::splitNames($item['narrator'] ?? null);
		$genres = json_decode((string)($item['genres'] ?? '[]'), true) ?: [];
		$extra = self::extra($item);
		$series = self::seriesList($item);
		$seriesStr = implode(', ', array_map(static fn ($s) => $s['name'] . ($s['seq'] !== null ? ' #' . $s['seq'] : ''), $series));
		$m = [
			'title' => $title,
			'titleIgnorePrefix' => self::ignorePrefix($title),
			'subtitle' => $item['subtitle'] ?? null,
			'authorName' => implode(', ', $authors),
			'authorNameLF' => implode(', ', array_map([self::class, 'lastFirst'], $authors)),
			'narratorName' => implode(', ', $narrators),
			'seriesName' => $seriesStr,
			'genres' => $genres,
			'publishedYear' => $item['published_year'] ?? null,
			'publishedDate' => null,
			'publisher' => $extra['publisher'] ?? null,
			'description' => $item['description'] ?? null,
			'isbn' => $extra['isbn'] ?? null,
			'asin' => $extra['asin'] ?? null,
			'language' => $extra['language'] ?? null,
			'explicit' => !empty($extra['explicit']),
			'abridged' => !empty($extra['abridged']),
		];
		if (!$minified) {
			$m['authors'] = array_map(static fn ($a) => ['id' => self::authorId($a), 'name' => $a], $authors);
			$m['narrators'] = $narrators;
			$m['series'] = array_map(static fn ($s) => ['id' => self::seriesId($s['name']), 'name' => $s['name'], 'sequence' => $s['seq']], $series);
			$m['descriptionPlain'] = $item['description'] ?? null;
		}
		return $m;
	}

	private static function fileMetadata(array $track, string $libPath): array {
		$filename = (string)$track['filename'];
		return [
			'filename' => $filename,
			'ext' => '.' . strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
			'path' => rtrim($libPath, '/') . '/' . $track['rel_path'],
			'relPath' => (string)$track['rel_path'],
			'size' => (int)$track['size'],
			'mtimeMs' => (int)$track['mtime'] * 1000,
			'ctimeMs' => (int)$track['mtime'] * 1000,
			'birthtimeMs' => 0,
		];
	}

	public static function audioFile(array $item, array $track, string $libPath): array {
		$meta = json_decode((string)($track['meta'] ?? '{}'), true) ?: [];
		$filename = (string)$track['filename'];
		return [
			'index' => (int)$track['track_index'],
			'ino' => (string)$track['file_id'],
			'metadata' => self::fileMetadata($track, $libPath),
			'addedAt' => (int)$item['added_at'],
			'updatedAt' => (int)$item['updated_at'],
			'trackNumFromMeta' => isset($meta['tags']['track']) ? (int)$meta['tags']['track'] : null,
			'discNumFromMeta' => isset($meta['tags']['disc']) ? (int)$meta['tags']['disc'] : null,
			'trackNumFromFilename' => null,
			'discNumFromFilename' => null,
			'manuallyVerified' => false,
			'exclude' => false,
			'error' => null,
			'format' => strtoupper(pathinfo($filename, PATHINFO_EXTENSION)),
			'duration' => (float)$track['duration'],
			'bitRate' => $meta['bitRate'] ?? null,
			'language' => null,
			'codec' => $meta['codec'] ?? null,
			'timeBase' => null,
			'channels' => null,
			'channelLayout' => null,
			'chapters' => [],
			'embeddedCoverArt' => null,
			'metaTags' => (object)[],
			'mimeType' => (string)($track['mime'] ?? 'application/octet-stream'),
		];
	}

	public static function audioTrack(array $item, array $track, string $libPath): array {
		$meta = json_decode((string)($track['meta'] ?? '{}'), true) ?: [];
		return [
			'index' => (int)$track['track_index'],
			'startOffset' => (float)$track['start_offset'],
			'duration' => (float)$track['duration'],
			'title' => (string)($meta['tags']['title'] ?? $track['filename']),
			'contentUrl' => '/api/items/' . $item['item_key'] . '/file/' . $track['file_id'],
			'mimeType' => (string)($track['mime'] ?? 'application/octet-stream'),
			'codec' => $meta['codec'] ?? null,
			'metadata' => self::fileMetadata($track, $libPath),
			'isLocal' => false,
		];
	}

	/** @return list<array{id: int, name: string, path: string, size: int, mtime: int, opds?: bool}> */
	public static function ebooks(array $item): array {
		$list = self::extra($item)['ebooks'] ?? [];
		// Scanned before 0.8: only the file id of an EPUB is known until the next scan.
		return $list === [] && $item['ebook_file_id'] !== null
			? [['id' => (int)$item['ebook_file_id'], 'name' => 'book.epub', 'path' => 'book.epub', 'size' => 0, 'mtime' => 0]] : $list;
	}

	private static function ebookMetadata(array $e, string $libPath): array {
		$root = !empty($e['opds']) ? '' : rtrim($libPath, '/');
		return [
			'filename' => $e['name'],
			'ext' => '.' . strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)),
			'path' => $root . '/' . $e['path'],
			'relPath' => $e['path'],
			'size' => $e['size'],
			'mtimeMs' => $e['mtime'] * 1000,
			'ctimeMs' => $e['mtime'] * 1000,
			'birthtimeMs' => 0,
		];
	}

	/** The ebook to read; the other ones are supplementary. */
	public static function ebookFile(array $item, string $libPath): ?array {
		foreach ($item['ebook_file_id'] === null ? [] : self::ebooks($item) as $e) {
			if ($e['id'] === (int)$item['ebook_file_id']) {
				return [
					'ino' => (string)$e['id'],
					'metadata' => self::ebookMetadata($e, $libPath),
					'ebookFormat' => strtolower(pathinfo($e['name'], PATHINFO_EXTENSION)),
					'addedAt' => (int)$item['added_at'],
					'updatedAt' => (int)$item['updated_at'],
					'isLocal' => false,
					'localFileId' => null,
					'contentUrl' => null,
				];
			}
		}
		return null;
	}

	public static function hasCover(array $item): bool {
		return $item['cover_file_id'] !== null || ($item['cover_cache'] ?? null) !== null || isset(self::extra($item)['opdsCover']);
	}

	private static function itemBase(array $item, string $libPath): array {
		$isFile = (bool)$item['is_file'];
		$key = (string)$item['item_key'];
		return [
			'id' => $key,
			'ino' => $key,
			'oldLibraryItemId' => null,
			'libraryId' => (string)$item['library_id'],
			'folderId' => 'fol_' . $item['library_id'],
			'path' => rtrim($libPath, '/') . '/' . $item['rel_path'],
			'relPath' => (string)$item['rel_path'],
			'isFile' => $isFile,
			'mtimeMs' => (int)$item['mtime'] * 1000,
			'ctimeMs' => (int)$item['mtime'] * 1000,
			'birthtimeMs' => (int)$item['added_at'],
			'addedAt' => (int)$item['added_at'],
			'updatedAt' => (int)$item['updated_at'],
			'lastScan' => (int)$item['updated_at'],
			'scanVersion' => Application::ABS_VERSION,
			'isMissing' => false,
			'isInvalid' => false,
			'mediaType' => 'book',
		];
	}

	/** Minified library item, as in shelves and library listings. */
	public static function itemMinified(array $item, array $tracks, string $libPath): array {
		$chapters = json_decode((string)($item['chapters'] ?? '[]'), true) ?: [];
		$out = self::itemBase($item, $libPath);
		$out['media'] = [
			'id' => 'b' . $item['item_key'],
			'metadata' => self::bookMetadata($item, true),
			'coverPath' => self::hasCover($item) ? '/covers/' . $item['item_key'] : null,
			'tags' => self::extra($item)['tags'] ?? [],
			'numTracks' => count($tracks),
			'numAudioFiles' => count($tracks),
			'numChapters' => count($chapters),
			'duration' => (float)$item['duration'],
			'size' => (int)$item['size'],
			'ebookFormat' => self::ebookFile($item, $libPath)['ebookFormat'] ?? null,
		];
		$out['numFiles'] = count($tracks) + count(self::ebooks($item)) + ($item['cover_file_id'] !== null ? 1 : 0);
		$out['size'] = (int)$item['size'];
		return $out;
	}

	/** Expanded library item, as in GET /api/items/:id?expanded=1. */
	public static function itemExpanded(array $item, array $tracks, string $libPath): array {
		$chapters = json_decode((string)($item['chapters'] ?? '[]'), true) ?: [];
		$audioFiles = array_map(static fn ($t) => self::audioFile($item, $t, $libPath), $tracks);
		$out = self::itemBase($item, $libPath);
		$out['media'] = [
			'id' => 'b' . $item['item_key'],
			'libraryItemId' => (string)$item['item_key'],
			'metadata' => self::bookMetadata($item, false),
			'coverPath' => self::hasCover($item) ? '/covers/' . $item['item_key'] : null,
			'tags' => self::extra($item)['tags'] ?? [],
			'audioFiles' => $audioFiles,
			'chapters' => array_map(static fn ($c) => [
				'id' => (int)$c['id'], 'start' => (float)$c['start'], 'end' => (float)$c['end'], 'title' => (string)$c['title'],
			], $chapters),
			'ebookFile' => self::ebookFile($item, $libPath),
			'duration' => (float)$item['duration'],
			'size' => (int)$item['size'],
			'tracks' => array_map(static fn ($t) => self::audioTrack($item, $t, $libPath), $tracks),
			'missingParts' => [],
			'numTracks' => count($tracks),
		];
		$libraryFiles = array_map(static fn ($af) => [
			'ino' => $af['ino'],
			'metadata' => $af['metadata'],
			'isSupplementary' => false,
			'addedAt' => $af['addedAt'],
			'updatedAt' => $af['updatedAt'],
			'fileType' => 'audio',
		], $audioFiles);
		foreach (self::ebooks($item) as $e) {
			$libraryFiles[] = [
				'ino' => (string)$e['id'], 'metadata' => self::ebookMetadata($e, $libPath), 'isSupplementary' => (int)($item['ebook_file_id'] ?? 0) !== $e['id'],
				'addedAt' => (int)$item['added_at'], 'updatedAt' => (int)$item['updated_at'], 'fileType' => 'ebook',
			];
		}
		$out['libraryFiles'] = $libraryFiles;
		$out['size'] = (int)$item['size'];
		return $out;
	}
}
