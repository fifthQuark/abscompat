<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Podcasts the way rePod has them: subscriptions and play positions live in GPodderSync, which rePod,
 * AntennaPod and the other gpodder clients share. Subscriptions are only read here. Positions are
 * read, and written through GPodderSync's own EpisodeActionSaver, the way its sync API saves them.
 * Episodes are not downloaded to Nextcloud: the apps get them from the publisher.
 */
class PodcastService {
	public const LIBRARY = 'podcasts';
	private const MAX_AGE = 3600;
	private const MAX_FEED = 20 * 1024 * 1024;
	private const SAVER = 'OCA\GPodderSync\Core\EpisodeAction\EpisodeActionSaver';

	/** @var array<string, array> parsed feeds of this request */
	private array $feeds = [];
	/** @var array<string, array> */
	private array $actions = [];

	public function __construct(
		private IDBConnection $db,
		private IAppManager $apps,
		private IUserManager $users,
		private IUserConfig $userConfig,
		private IClientService $clients,
		private IAppData $appData,
		private LoggerInterface $logger,
	) {
	}

	public function available(string $userId): bool {
		$user = $this->users->get($userId);
		try {
			return $user !== null && $this->apps->isEnabledForUser('gpoddersync', $user)
				&& $this->db->tableExists('gpodder_subscriptions') && $this->db->tableExists('gpodder_episode_action');
		} catch (\Throwable) {
			return false;
		}
	}

	/** Show the podcasts in the apps. On unless the user switches it off. */
	public function isOn(string $userId): bool {
		return !UserValue::bool($this->userConfig, $userId, 'podcasts_off') && $this->available($userId);
	}

	public function set(string $userId, bool $on): void {
		$this->userConfig->setValueBool($userId, Application::APP_ID, 'podcasts_off', !$on);
	}

	public static function podcastKey(string $feedUrl): string {
		return 'p' . substr(sha1($feedUrl), 0, 12);
	}

	public static function isPodcastKey(string $id): bool {
		return (bool)preg_match('/^p[0-9a-f]{12}$/D', $id);
	}

	public static function isEpisodeKey(string $id): bool {
		return (bool)preg_match('/^e[0-9a-f]{12}$/D', $id);
	}

	// ----- subscriptions and feeds -----

	/** @return list<string> feed addresses the user subscribed to in rePod or another gpodder client */
	public function subscriptions(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('url')->from('gpodder_subscriptions')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('subscribed', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'ASC');
		$res = $qb->executeQuery();
		$urls = [];
		while (($u = $res->fetchOne()) !== false) {
			if (preg_match('#^https?://#i', (string)$u)) {
				$urls[] = (string)$u;
			}
		}
		$res->closeCursor();
		return array_values(array_unique($urls));
	}

	/**
	 * The user's podcasts, each with its episodes. Feeds are kept for an hour; one that can't be fetched
	 * is shown as it was last time, or with its address only.
	 * @return list<array>
	 */
	public function podcasts(string $userId, bool $refresh = true): array {
		$out = [];
		foreach ($this->subscriptions($userId) as $url) {
			$out[] = $this->feed($url, $refresh);
		}
		return $out;
	}

	public function podcast(string $userId, string $key): ?array {
		foreach ($this->subscriptions($userId) as $url) {
			if (self::podcastKey($url) === $key) {
				return $this->feed($url, true);
			}
		}
		return null;
	}

	/** @return ?array{0: array, 1: array} the podcast and the episode */
	public function episode(string $userId, string $podcastKey, string $episodeKey): ?array {
		$p = $this->podcast($userId, $podcastKey);
		foreach ($p['episodes'] ?? [] as $e) {
			if ($e['id'] === $episodeKey) {
				return [$p, $e];
			}
		}
		return null;
	}

	private function feed(string $url, bool $refresh): array {
		if (isset($this->feeds[$url])) {
			return $this->feeds[$url];
		}
		$name = sha1($url) . '.json';
		$cached = null;
		try {
			$cached = json_decode($this->folder()->getFile($name)->getContent(), true);
		} catch (NotFoundException) {
		} catch (\Throwable $e) {
			$this->logger->info('Podcast cache unreadable: ' . $e->getMessage(), ['app' => 'abscompat']);
		}
		if (is_array($cached) && (!$refresh || time() - (int)$cached['fetched'] < self::MAX_AGE)) {
			return $this->feeds[$url] = $cached;
		}
		try {
			$res = $this->clients->newClient()->get($url, ['timeout' => 10, 'headers' => ['User-Agent' => 'Nextcloud podcast reader']]);
			$body = (string)$res->getBody();
			if (strlen($body) > self::MAX_FEED) {
				throw new \RuntimeException('feed too large');
			}
			$feed = self::parse($body, $url) + ['fetched' => time()];
			try {
				$folder = $this->folder();
				$folder->fileExists($name) ? $folder->getFile($name)->putContent(json_encode($feed)) : $folder->newFile($name, json_encode($feed));
			} catch (\Throwable $e) {
				$this->logger->info('Podcast cache not written: ' . $e->getMessage(), ['app' => 'abscompat']);
			}
			return $this->feeds[$url] = $feed;
		} catch (\Throwable $e) {
			$this->logger->info('Podcast feed ' . $url . ' not read: ' . $e->getMessage(), ['app' => 'abscompat']);
			return $this->feeds[$url] = is_array($cached) ? $cached : self::parse('', $url) + ['fetched' => 0];
		}
	}

	private function folder(): \OCP\Files\SimpleFS\ISimpleFolder {
		try {
			return $this->appData->getFolder('podcasts');
		} catch (NotFoundException) {
			return $this->appData->newFolder('podcasts');
		}
	}

	/** Fetch stale feeds ahead of time, for the background job. */
	public function refresh(string $userId, float $deadline): void {
		if (!$this->isOn($userId)) {
			return;
		}
		foreach ($this->subscriptions($userId) as $url) {
			if (microtime(true) > $deadline) {
				return;
			}
			$this->feed($url, true);
		}
	}

	/** RSS (with iTunes and content tags) to the podcast and its episodes, newest first. */
	public static function parse(string $xml, string $url): array {
		$text = static fn ($v) => MetadataExtractor::cleanText(trim(html_entity_decode(strip_tags((string)$v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
		$out = ['id' => self::podcastKey($url), 'feedUrl' => $url, 'title' => (string)parse_url($url, PHP_URL_HOST), 'author' => null, 'description' => null,
			'image' => null, 'language' => null, 'explicit' => false, 'genres' => [], 'type' => null, 'episodes' => []];
		// No entities, no network, whatever the feed asks for.
		if ($xml === '' || stripos($xml, '<!ENTITY') !== false) {
			return $out;
		}
		$doc = @simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NOCDATA);
		if ($doc === false || !isset($doc->channel)) {
			return $out;
		}
		$ch = $doc->channel;
		$it = $ch->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
		$out['title'] = $text($ch->title) ?: $out['title'];
		$out['author'] = $text($it->author) ?: null;
		$out['description'] = $text($ch->description) ?: ($text($it->summary) ?: null);
		$img = (string)($it->image ? $it->image->attributes()['href'] : '') ?: (string)$ch->image->url;
		$out['image'] = preg_match('#^https?://#i', $img) ? $img : null;
		$out['language'] = $text($ch->language) ?: null;
		$out['explicit'] = in_array(strtolower($text($it->explicit)), ['yes', 'true', 'explicit'], true);
		foreach ($it->category ?? [] as $c) {
			$g = $text($c->attributes()['text'] ?? '');
			if ($g !== '') {
				$out['genres'][] = $g;
			}
		}
		$out['genres'] = array_values(array_unique($out['genres']));
		$out['type'] = $text($it->type) ?: null;
		$seen = [];
		foreach ($ch->item as $item) {
			$enc = $item->enclosure;
			$audio = $enc ? (string)$enc->attributes()['url'] : '';
			if (!preg_match('#^https?://#i', $audio)) {
				continue;
			}
			$guid = trim((string)$item->guid);
			$id = 'e' . substr(sha1($guid !== '' ? $guid : $audio), 0, 12);
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$iti = $item->children('http://www.itunes.com/dtds/podcast-1.0.dtd');
			$content = $item->children('http://purl.org/rss/1.0/modules/content/');
			$pub = trim((string)$item->pubDate);
			$at = $pub !== '' ? strtotime($pub) : false;
			$out['episodes'][] = [
				'id' => $id,
				'guid' => $guid !== '' ? $guid : null,
				'url' => $audio,
				'type' => (string)$enc->attributes()['type'] ?: 'audio/mpeg',
				'size' => (int)$enc->attributes()['length'],
				'title' => $text($item->title) ?: $text($iti->title),
				'subtitle' => $text($iti->subtitle) ?: null,
				'description' => trim((string)($content->encoded ?? '')) ?: (trim((string)$item->description) ?: null),
				'pubDate' => $pub !== '' ? $pub : null,
				'publishedAt' => $at !== false ? $at * 1000 : null,
				'duration' => self::seconds((string)$iti->duration),
				'episode' => $text($iti->episode) ?: null,
				'season' => $text($iti->season) ?: null,
				'episodeType' => $text($iti->episodeType) ?: null,
			];
		}
		usort($out['episodes'], static fn ($a, $b) => ($b['publishedAt'] ?? 0) <=> ($a['publishedAt'] ?? 0));
		return $out;
	}

	/** "1:02:03", "62:03" or "3723" */
	public static function seconds(string $d): float {
		$d = trim($d);
		if (preg_match('/^\d+(?:\.\d+)?$/', $d)) {
			return (float)$d;
		}
		if (!preg_match('/^\d+(?::\d{1,2}){1,2}(?:\.\d+)?$/', $d)) {
			return 0.0;
		}
		$s = 0.0;
		foreach (explode(':', $d) as $part) {
			$s = $s * 60 + (float)$part;
		}
		return $s;
	}

	// ----- positions, in GPodderSync -----

	/** @return array<string, array{position: int, total: int, at: int}> by episode address and by guid */
	public function positions(string $userId): array {
		if (isset($this->actions[$userId])) {
			return $this->actions[$userId];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from('gpodder_episode_action')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('PLAY')));
		$res = $qb->executeQuery();
		$out = [];
		while ($r = $res->fetch()) {
			$a = ['position' => (int)$r['position'], 'total' => (int)$r['total'], 'at' => (int)($r['timestamp_epoch'] ?? 0) ?: (int)strtotime((string)($r['timestamp'] ?? '')) ?: 0];
			foreach (['u:' . $r['episode'], 'g:' . ($r['guid'] ?? '')] as $k) {
				if ($k !== 'g:' && ($out[$k]['at'] ?? -1) < $a['at']) {
					$out[$k] = $a;
				}
			}
		}
		$res->closeCursor();
		return $this->actions[$userId] = $out;
	}

	/** @return ?array{position: int, total: int, at: int} */
	public function position(string $userId, array $episode): ?array {
		$p = $this->positions($userId);
		return ($episode['guid'] !== null ? $p['g:' . $episode['guid']] ?? null : null) ?? $p['u:' . $episode['url']] ?? null;
	}

	/** Same rule as rePod: played when the position reaches the end. */
	public static function finished(?array $a): bool {
		return $a !== null && $a['total'] > 0 && $a['position'] >= $a['total'];
	}

	/** Write a position, through GPodderSync's own code. */
	public function save(string $userId, array $podcast, array $episode, float $position, float $total, bool $finished): bool {
		if (!class_exists(self::SAVER)) {
			return false;
		}
		$total = (int)round($total > 0 ? $total : (float)$episode['duration']);
		$pos = $finished ? max($total, (int)round($position)) : (int)round($position);
		try {
			Server::get(self::SAVER)->saveEpisodeActions([[
				'podcast' => $podcast['feedUrl'],
				'episode' => $episode['url'],
				'guid' => $episode['guid'],
				'action' => 'PLAY',
				'timestamp' => gmdate('Y-m-d\TH:i:s'),
				'started' => 0,
				'position' => max(0, $pos),
				'total' => max(0, $total),
			]], $userId);
			unset($this->actions[$userId]);
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Podcast position not saved in GPodderSync: ' . $e->getMessage(), ['app' => 'abscompat']);
			return false;
		}
	}
}
