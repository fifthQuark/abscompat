<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\Files\File;
use Psr\Log\LoggerInterface;

/**
 * Reads duration, tags, chapters and embedded cover art from one audio file with getID3.
 */
class MetadataExtractor {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{duration: float, codec: ?string, bitRate: ?int, tags: array<string,string>, chapters: list<array{start: float, end: ?float, title: string}>, picture: ?array{data: string, mime: string}}
	 */
	public function extract(File $file, bool $wantPicture): array {
		$empty = ['duration' => 0.0, 'codec' => null, 'bitRate' => null, 'tags' => [], 'chapters' => [], 'picture' => null];
		// getID3 is loaded only here, and only if no other app has loaded its copy already
		// (e.g. the Music app bundles it too). Both expose the same public API.
		if (!class_exists(\getID3::class)) {
			$lib = __DIR__ . '/../../vendor/james-heinrich/getid3/getid3/getid3.php';
			if (is_file($lib)) {
				require_once $lib;
			}
		}
		if (!class_exists(\getID3::class)) {
			$this->logger->error('getID3 is missing; the app was installed without its vendor directory', ['app' => 'abscompat']);
			return $empty;
		}
		try {
			$storage = $file->getStorage();
			$localPath = $storage->getLocalFile($file->getInternalPath());
			if (!is_string($localPath) || !is_file($localPath)) {
				return $empty;
			}
			$getID3 = new \getID3();
			$getID3->option_md5_data = false;
			$getID3->option_sha1_data = false;
			$getID3->option_save_attachments = $wantPicture;
			$info = $getID3->analyze($localPath, $file->getSize(), $file->getName());
			\getid3_lib::CopyTagsToComments($info);
		} catch (\Throwable $e) {
			$this->logger->warning('Could not read audio metadata of ' . $file->getPath() . ': ' . $e->getMessage(), ['app' => 'abscompat']);
			return $empty;
		}

		$duration = (float)($info['playtime_seconds'] ?? 0);
		$comments = $info['comments'] ?? [];
		$tag = static function (string ...$keys) use ($comments): ?string {
			foreach ($keys as $k) {
				if (!empty($comments[$k]) && is_array($comments[$k])) {
					$v = self::cleanText((string)reset($comments[$k]));
					if ($v !== '') {
						return $v;
					}
				}
			}
			return null;
		};
		$tags = array_filter([
			'title' => $tag('title'),
			'album' => $tag('album'),
			'artist' => $tag('artist'),
			'albumArtist' => $tag('album_artist', 'band', 'albumartist'),
			'composer' => $tag('composer'),
			'year' => $tag('year', 'creation_date', 'date'),
			'genre' => $tag('genre'),
			'description' => $tag('description', 'comment', 'long_description'),
			'subtitle' => $tag('subtitle'),
			'series' => $tag('series', 'mvnm', 'grouping'),
			'seriesPart' => $tag('series-part', 'mvin'),
			'track' => $tag('track_number', 'tracknumber', 'track'),
			'disc' => $tag('disc_number', 'part_of_a_set', 'discnumber'),
		], static fn ($v) => $v !== null);

		$picture = null;
		if ($wantPicture && !empty($comments['picture'][0]['data'])) {
			$p = $comments['picture'][0];
			$picture = ['data' => (string)$p['data'], 'mime' => (string)($p['image_mime'] ?? 'image/jpeg')];
		}

		return [
			'duration' => $duration,
			'codec' => isset($info['audio']['dataformat']) ? (string)$info['audio']['dataformat'] : null,
			'bitRate' => isset($info['audio']['bitrate']) ? (int)$info['audio']['bitrate'] : null,
			'tags' => $tags,
			'chapters' => self::chapters($info, $duration),
			'picture' => $picture,
		];
	}

	/**
	 * Tags come from arbitrary files: replace invalid UTF-8 and drop control characters (notably NUL).
	 * MariaDB and PostgreSQL refuse to store such text, which would lose the whole book.
	 */
	public static function cleanText(string $s): string {
		$s = mb_scrub($s, 'UTF-8');
		return trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $s));
	}

	/** @return list<array{start: float, end: ?float, title: string}> */
	public static function chapters(array $info, float $duration): array {
		$out = [];
		if (!empty($info['quicktime']['chapters']) && is_array($info['quicktime']['chapters'])) {
			// Nero "chpl" atom; timestamps in seconds.
			foreach ($info['quicktime']['chapters'] as $c) {
				$out[] = ['start' => (float)($c['timestamp'] ?? 0), 'end' => null, 'title' => (string)($c['title'] ?? '')];
			}
		} elseif (!empty($info['quicktime']['bookmarks']) && is_array($info['quicktime']['bookmarks'])) {
			// QuickTime chapter text track.
			foreach ($info['quicktime']['bookmarks'] as $c) {
				if (!isset($c['start_seconds'])) {
					continue;
				}
				$end = isset($c['duration_seconds']) ? (float)$c['start_seconds'] + (float)$c['duration_seconds'] : null;
				$out[] = ['start' => (float)$c['start_seconds'], 'end' => $end, 'title' => (string)($c['title'] ?? '')];
			}
		} elseif (!empty($info['id3v2']['chapters']) && is_array($info['id3v2']['chapters'])) {
			// ID3v2 CHAP frames; times in milliseconds.
			foreach ($info['id3v2']['chapters'] as $c) {
				$out[] = [
					'start' => ((float)($c['time_begin'] ?? 0)) / 1000,
					'end' => isset($c['time_end']) ? ((float)$c['time_end']) / 1000 : null,
					'title' => (string)($c['chapter_name'] ?? ''),
				];
			}
		}
		usort($out, static fn ($a, $b) => $a['start'] <=> $b['start']);
		$n = count($out);
		for ($i = 0; $i < $n; $i++) {
			$next = $i + 1 < $n ? $out[$i + 1]['start'] : $duration;
			if ($out[$i]['end'] === null || $out[$i]['end'] <= $out[$i]['start'] || $out[$i]['end'] > $next + 0.5) {
				$out[$i]['end'] = $next;
			}
			$out[$i]['title'] = self::cleanText($out[$i]['title']);
			if ($out[$i]['title'] === '') {
				$out[$i]['title'] = 'Chapter ' . ($i + 1);
			}
		}
		return array_values(array_filter($out, static fn ($c) => $c['end'] > $c['start']));
	}
}
