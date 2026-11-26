<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * Finds covers for books that have none, in Apple's public iTunes search (audiobooks there have
 * square covers). Only the search words (title and author) are sent, by the server. Nothing is
 * looked up unless the user asks for it or has switched on automatic covers.
 */
class CoverService {
	private const SEARCH = 'https://itunes.apple.com/search';
	private const MAX_BYTES = 8 * 1024 * 1024;
	/** Automatic lookups that found nothing are repeated after this many seconds. */
	private const RETRY_AFTER = 30 * 86400;

	public function __construct(
		private IClientService $clients,
		private ICrypto $crypto,
		private IConfig $config,
		private IUserConfig $userConfig,
		private LibraryService $library,
		private LoggerInterface $logger,
	) {
	}

	public function online(): bool {
		return $this->config->getSystemValueBool('has_internet_connection', true);
	}

	public function isAuto(string $userId): bool {
		return UserValue::bool($this->userConfig, $userId, 'auto_covers');
	}

	public function setAuto(string $userId, bool $on): void {
		$this->userConfig->setValueBool($userId, Application::APP_ID, 'auto_covers', $on);
	}

	// ----- search -----

	/**
	 * @return list<array{title: string, author: string, year: string, thumb: string, url: string}>
	 * @throws \RuntimeException when the service can't be reached or is busy
	 */
	public function search(string $words): array {
		if (!$this->online()) {
			throw new \RuntimeException('This server has no internet connection');
		}
		$words = mb_substr(trim((string)preg_replace('/\s+/u', ' ', MetadataExtractor::cleanText($words))), 0, 200);
		if ($words === '') {
			return [];
		}
		try {
			$res = $this->clients->newClient()->get(self::SEARCH, [
				'query' => ['term' => $words, 'media' => 'audiobook', 'entity' => 'audiobook', 'limit' => 12],
				'timeout' => 15,
				'allow_redirects' => false,
			]);
			$body = (string)$res->getBody();
		} catch (\Throwable $e) {
			$this->logger->info('Cover search failed: ' . $e->getMessage(), ['app' => 'abscompat']);
			throw new \RuntimeException('The cover search is not available right now. Try again in a minute.');
		}
		return self::parse($body);
	}

	/** @return list<array{title: string, author: string, year: string, thumb: string, url: string}> */
	public static function parse(string $json): array {
		$out = [];
		$data = json_decode($json, true);
		$results = is_array($data) && is_array($data['results'] ?? null) ? $data['results'] : [];
		foreach ($results as $r) {
			if (!is_array($r)) {
				continue;
			}
			$text = static fn (string $k) => is_string($r[$k] ?? null) ? MetadataExtractor::cleanText($r[$k]) : '';
			$art = is_string($r['artworkUrl100'] ?? null) ? $r['artworkUrl100'] : '';
			if (!self::allowedUrl($art) || !preg_match('#/\d+x\d+[a-z]*\.(?:jpg|png)$#', $art)) {
				continue;
			}
			$size = static fn (int $n) => (string)preg_replace('#/\d+x\d+[a-z]*\.(?:jpg|png)$#', '/' . $n . 'x' . $n . 'bb.jpg', $art);
			$out[] = [
				'title' => $text('collectionName'),
				'author' => $text('artistName'),
				'year' => substr($text('releaseDate'), 0, 4),
				'thumb' => $size(200),
				'url' => $size(1200),
			];
		}
		return $out;
	}

	/** Only Apple's image servers, so a request can't be pointed anywhere else. */
	public static function allowedUrl(string $url): bool {
		return (bool)preg_match('#^https://[a-z0-9-]+\.mzstatic\.com/[A-Za-z0-9/._%-]+$#D', $url) && !str_contains($url, '..');
	}

	public function sign(string $url): string {
		return bin2hex($this->crypto->calculateHMAC('abscompat-cover:' . $url));
	}

	public function verify(string $url, string $sig): bool {
		return self::allowedUrl($url) && hash_equals($this->sign($url), $sig);
	}

	/** @throws \RuntimeException */
	public function download(string $url): string {
		if (!self::allowedUrl($url)) {
			throw new \RuntimeException('Not a cover address');
		}
		try {
			$res = $this->clients->newClient()->get($url, ['timeout' => 15, 'allow_redirects' => false, 'stream' => true]);
			$body = $res->getBody();
			if (is_resource($body)) {
				$data = (string)stream_get_contents($body, self::MAX_BYTES + 1);
				fclose($body);
			} else {
				$data = (string)$body;
			}
		} catch (\Throwable $e) {
			$this->logger->info('Cover download failed: ' . $e->getMessage(), ['app' => 'abscompat']);
			throw new \RuntimeException('The cover could not be downloaded. Try again in a minute.');
		}
		if (strlen($data) > self::MAX_BYTES) {
			throw new \RuntimeException('The cover is too large');
		}
		return $data;
	}

	// ----- matching -----

	/** Comparable form: lower case, no accents that vary, no punctuation, no subtitle or "(Unabridged)". */
	public static function norm(string $s, bool $cutSubtitle): string {
		$s = (string)preg_replace('/\s*[(\[][^)\]]*[)\]]/u', '', mb_strtolower($s));
		if ($cutSubtitle) {
			$s = (string)preg_replace('/\s*(?::|\s[-–—]\s).*$/u', '', $s);
		}
		if (class_exists(\Normalizer::class)) {
			$s = (string)preg_replace('/\p{Mn}+/u', '', (string)\Normalizer::normalize($s, \Normalizer::FORM_D));
		}
		$s = str_replace('&', ' and ', $s);
		$s = trim((string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace(['\'', '’'], '', $s)));
		return (string)preg_replace('/^(?:the|a|an) /', '', $s);
	}

	/**
	 * The result that is surely this book: the same title, and one of its authors.
	 * @param list<array{title: string, author: string, year: string, thumb: string, url: string}> $results
	 */
	public static function pick(array $results, string $title, ?string $author): ?array {
		$names = array_map(static fn ($n) => self::norm($n, false), Serializer::splitNames($author));
		$names = array_filter($names, static fn ($n) => mb_strlen($n) >= 3);
		$full = self::norm($title, false);
		if ($names === [] || $full === '') {
			return null;
		}
		$mine = static function (array $r) use ($names): bool {
			$by = ' ' . self::norm($r['author'], false) . ' ';
			foreach ($names as $n) {
				if (str_contains($by, ' ' . $n . ' ')) {
					return true;
				}
			}
			return false;
		};
		$subtitled = [];
		foreach ($results as $r) {
			$their = self::norm($r['title'], false);
			if ($their === $full && $mine($r)) {
				return $r;
			}
			if (self::norm($r['title'], true) === $full && $mine($r)) {
				$subtitled[$their] ??= $r;
			}
		}
		// "Title: Subtitle" counts only when no other subtitle fits as well.
		return count($subtitled) === 1 ? reset($subtitled) : null;
	}

	// ----- storing -----

	/** A cover chosen in the app; it wins over the one in the files. */
	public static function hasChosenCover(array $row): bool {
		return str_starts_with((string)($row['cover_cache'] ?? ''), LibraryService::CUSTOM_COVER);
	}

	/** Books with a cover in their files (an image file or embedded art). */
	public static function hasOwnCover(array $row): bool {
		return $row['cover_file_id'] !== null
			|| ($row['cover_cache'] !== null && !str_starts_with((string)$row['cover_cache'], LibraryService::CUSTOM_COVER));
	}

	/** Make it square and use it for the book. */
	public function set(string $userId, array $row, string $data): bool {
		$jpeg = SquareImage::make($data);
		if ($jpeg === null) {
			return false;
		}
		$this->library->setCustomCover($userId, (string)$row['item_key'], $jpeg);
		return true;
	}

	/**
	 * Look for the cover of one book and use it if the match is certain.
	 * @throws \RuntimeException when the search is not available
	 */
	public function auto(string $userId, array $row): bool {
		$key = (string)$row['item_key'];
		$title = (string)$row['title'];
		$authors = Serializer::splitNames($row['author']);
		$hit = null;
		if ($authors !== []) {
			// One author is enough to search; the narrator is often listed as another.
			foreach (array_slice($authors, 0, 2) as $a) {
				$hit = self::pick($this->search(self::norm($title, true) . ' ' . $a), $title, $row['author']);
				if ($hit !== null) {
					break;
				}
			}
		}
		if ($hit !== null) {
			$data = $this->download($hit['url']);
			$ratio = SquareImage::ratio($data);
			// Not square: not the audiobook's cover. A person can still choose it.
			if ($ratio !== null && $ratio <= 1.05 && $this->set($userId, $row, $data)) {
				return true;
			}
		}
		$this->library->patchOverrides($userId, $key, ['coverTried' => time()]);
		return false;
	}

	/**
	 * Background run for one user. Slow on purpose: the search allows about 20 requests a minute.
	 * @return int covers found
	 */
	public function autoAll(string $userId, float $deadline, int $max = 20): int {
		if (!$this->isAuto($userId) || !$this->online()) {
			return 0;
		}
		$found = 0;
		foreach ($this->library->visibleItemRows($userId) as $row) {
			if (Serializer::hasCover($row) || Serializer::isEbookOnly($row)) {
				continue;
			}
			$tried = (int)($this->library->getOverrides($userId, (string)$row['item_key'])['coverTried'] ?? 0);
			if (time() - $tried < self::RETRY_AFTER) {
				continue;
			}
			if ($max-- <= 0 || microtime(true) > $deadline) {
				break;
			}
			try {
				$found += $this->auto($userId, $row) ? 1 : 0;
			} catch (\RuntimeException) {
				// Busy or offline: the next run continues.
				break;
			}
			sleep(4);
		}
		return $found;
	}
}
