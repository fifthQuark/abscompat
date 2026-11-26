<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;

/**
 * Podcasts in Audiobookshelf's JSON. Episodes look downloaded to the apps; their audio addresses
 * send the apps on to the publisher. Podcast media must carry autoDownloadEpisodes and
 * metadata.feedUrl: the Android app tells books and podcasts apart by their keys.
 */
final class PodcastJson {
	public static function library(int $order): array {
		$lib = Serializer::library(['id' => PodcastService::LIBRARY, 'name' => 'Podcasts', 'path' => '/'], $order);
		$lib['mediaType'] = 'podcast';
		$lib['icon'] = 'podcast';
		$lib['provider'] = 'itunes';
		$lib['folders'] = [];
		return $lib;
	}

	private static function metadata(array $p): array {
		return [
			'title' => $p['title'],
			'titleIgnorePrefix' => Serializer::ignorePrefix((string)$p['title']),
			'author' => $p['author'],
			'description' => $p['description'],
			'releaseDate' => null,
			'genres' => $p['genres'],
			'feedUrl' => $p['feedUrl'],
			'imageUrl' => $p['image'],
			'itunesPageUrl' => null,
			'itunesId' => null,
			'itunesArtistId' => null,
			'explicit' => $p['explicit'],
			'language' => $p['language'],
			'type' => $p['type'],
		];
	}

	private static function audioFile(array $p, array $e): array {
		$name = basename((string)parse_url($e['url'], PHP_URL_PATH)) ?: 'episode';
		return [
			'index' => 1,
			'ino' => $e['id'],
			'metadata' => ['filename' => $name, 'ext' => '.' . (pathinfo($name, PATHINFO_EXTENSION) ?: 'mp3'), 'path' => '/' . $p['id'] . '/' . $name, 'relPath' => $name,
				'size' => $e['size'], 'mtimeMs' => (int)$e['publishedAt'], 'ctimeMs' => (int)$e['publishedAt'], 'birthtimeMs' => (int)$e['publishedAt']],
			'addedAt' => (int)$e['publishedAt'],
			'updatedAt' => (int)$e['publishedAt'],
			'trackNumFromMeta' => null, 'discNumFromMeta' => null, 'trackNumFromFilename' => null, 'discNumFromFilename' => null,
			'manuallyVerified' => false, 'exclude' => false, 'error' => null,
			'format' => strtoupper(pathinfo($name, PATHINFO_EXTENSION) ?: 'MP3'),
			'duration' => $e['duration'],
			'bitRate' => null, 'language' => null, 'codec' => null, 'timeBase' => null, 'channels' => null, 'channelLayout' => null,
			'chapters' => [], 'embeddedCoverArt' => null, 'metaTags' => (object)[],
			'mimeType' => $e['type'],
		];
	}

	public static function audioTrack(array $p, array $e): array {
		$f = self::audioFile($p, $e);
		return [
			'index' => 1,
			'startOffset' => 0.0,
			'duration' => $e['duration'],
			'title' => $e['title'],
			'contentUrl' => '/api/items/' . $p['id'] . '/file/' . $e['id'],
			'mimeType' => $e['type'],
			'codec' => null,
			'metadata' => $f['metadata'],
			'isLocal' => false,
		];
	}

	public static function episode(array $p, array $e, int $index): array {
		$at = (int)($e['publishedAt'] ?? 0);
		return [
			'libraryItemId' => $p['id'],
			'podcastId' => 'b' . $p['id'],
			'id' => $e['id'],
			'oldEpisodeId' => null,
			'index' => $index,
			'season' => $e['season'],
			'episode' => $e['episode'],
			'episodeType' => $e['episodeType'],
			'title' => $e['title'],
			'subtitle' => $e['subtitle'],
			'description' => $e['description'],
			'enclosure' => ['url' => $e['url'], 'type' => $e['type'], 'length' => (string)$e['size']],
			'guid' => $e['guid'],
			'pubDate' => $e['pubDate'],
			'chapters' => [],
			'audioFile' => self::audioFile($p, $e),
			'publishedAt' => $at ?: null,
			'addedAt' => $at,
			'updatedAt' => $at,
			'audioTrack' => self::audioTrack($p, $e),
			'size' => $e['size'],
			'duration' => $e['duration'],
		];
	}

	/** A podcast as a library item; with all its episodes when expanded. */
	public static function item(array $p, bool $expanded): array {
		$newest = (int)($p['episodes'][0]['publishedAt'] ?? 0) ?: (int)$p['fetched'] * 1000;
		$n = count($p['episodes']);
		$media = [
			'id' => 'b' . $p['id'],
			'metadata' => self::metadata($p),
			'coverPath' => $p['image'] !== null ? '/covers/' . $p['id'] : null,
			'tags' => [],
			'numEpisodes' => $n,
			'autoDownloadEpisodes' => false,
			'autoDownloadSchedule' => null,
			'lastEpisodeCheck' => (int)$p['fetched'] * 1000 ?: null,
			'maxEpisodesToKeep' => 0,
			'maxNewEpisodesToDownload' => 0,
			'size' => array_sum(array_column($p['episodes'], 'size')),
		];
		if ($expanded) {
			$media['libraryItemId'] = $p['id'];
			$media['episodes'] = array_map(static fn ($e, $i) => self::episode($p, $e, $n - $i), $p['episodes'], array_keys($p['episodes']));
		}
		return [
			'id' => $p['id'], 'ino' => $p['id'], 'oldLibraryItemId' => null,
			'libraryId' => PodcastService::LIBRARY, 'folderId' => 'fol_' . PodcastService::LIBRARY,
			'path' => '/' . $p['id'], 'relPath' => $p['id'], 'isFile' => false,
			'mtimeMs' => $newest, 'ctimeMs' => $newest, 'birthtimeMs' => $newest,
			'addedAt' => $newest, 'updatedAt' => (int)$p['fetched'] * 1000 ?: $newest, 'lastScan' => (int)$p['fetched'] * 1000,
			'scanVersion' => Application::ABS_VERSION, 'isMissing' => false, 'isInvalid' => false,
			'mediaType' => 'podcast',
			'media' => $media,
			'numFiles' => $n,
			'size' => $media['size'],
		];
	}

	/** For episode shelves: the podcast, minified, with the episode as recentEpisode. */
	public static function withEpisode(array $p, array $e): array {
		$i = array_search($e['id'], array_column($p['episodes'], 'id'), true);
		return self::item($p, false) + ['recentEpisode' => self::episode($p, $e, count($p['episodes']) - (int)$i)];
	}

	/** GPodderSync's position as ABS media progress. */
	public static function progress(string $userId, array $p, array $e, array $a): array {
		$total = (float)($a['total'] ?: $e['duration']);
		$done = PodcastService::finished($a);
		$ms = $a['at'] * 1000;
		return [
			'id' => 'mp_' . $p['id'] . '-' . $e['id'],
			'userId' => $userId,
			'libraryItemId' => $p['id'],
			'episodeId' => $e['id'],
			'mediaItemId' => $e['id'],
			'mediaItemType' => 'podcastEpisode',
			'duration' => $total,
			'progress' => $done ? 1.0 : ($total > 0 ? min(1.0, $a['position'] / $total) : 0.0),
			'currentTime' => (float)min($a['position'], $total ?: $a['position']),
			'isFinished' => $done,
			'hideFromContinueListening' => false,
			'ebookLocation' => null,
			'ebookProgress' => null,
			'lastUpdate' => $ms,
			'startedAt' => $ms,
			'finishedAt' => $done ? $ms : null,
		];
	}
}
