<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Playback sessions, following ABS PlaybackSessionManager. Only direct play (playMethod 0).
 */
class SessionService {
	private const TABLE = 'abs_sessions';

	public function __construct(
		private IDBConnection $db,
		private ProgressService $progress,
		private Catalog $catalog,
	) {
	}

	private static function nowMs(): int {
		return (int)floor(microtime(true) * 1000);
	}

	private function uuid(): string {
		$h = bin2hex(random_bytes(16));
		return sprintf('%s-%s-4%s-%s%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), dechex(8 | (hexdec($h[16]) & 3)), substr($h, 17, 3), substr($h, 20, 12));
	}

	public static function deviceInfo(array $d): array {
		$s = static fn ($k) => is_scalar($d[$k] ?? null) ? mb_substr((string)$d[$k], 0, 128) : '';
		return [
			'id' => $s('deviceId') ?: $s('id'),
			'deviceId' => $s('deviceId') ?: $s('id'),
			'clientName' => $s('clientName') ?: 'Abs Android',
			'clientVersion' => $s('clientVersion'),
			'manufacturer' => $s('manufacturer'),
			'model' => $s('model'),
			'sdkVersion' => (int)($d['sdkVersion'] ?? 0),
			'deviceName' => trim($s('manufacturer') . ' ' . $s('model')),
		];
	}

	/** POST /api/items/:id/play */
	public function start(string $userId, array $item, array $deviceInfo, string $mediaPlayer): array {
		$now = self::nowMs();
		$key = (string)$item['item_key'];
		$p = $this->progress->get($userId, $key);
		$startTime = $p !== null && !$p['is_finished'] ? (float)$p['cur_time'] : 0.0;
		$sid = $this->uuid();
		$dev = self::deviceInfo($deviceInfo);

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'session_id' => $qb->createNamedParameter($sid),
			'user_id' => $qb->createNamedParameter($userId),
			'item_key' => $qb->createNamedParameter($key),
			'play_method' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			'media_player' => $qb->createNamedParameter(mb_substr($mediaPlayer, 0, 64)),
			'device_info' => $qb->createNamedParameter(json_encode($dev)),
			'started_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			'time_listening' => $qb->createNamedParameter(0.0),
			'start_time' => $qb->createNamedParameter($startTime),
			'cur_time' => $qb->createNamedParameter($startTime),
		]);
		$qb->executeStatement();

		$expanded = $this->catalog->expanded($userId, $item);
		$meta = $expanded['media']['metadata'];
		return [
			'id' => $sid,
			'userId' => $userId,
			'libraryId' => (string)$item['library_id'],
			'libraryItemId' => $key,
			'bookId' => 'b' . $key,
			'episodeId' => null,
			'mediaType' => 'book',
			'mediaMetadata' => $meta,
			'chapters' => $expanded['media']['chapters'],
			'displayTitle' => $meta['title'],
			'displayAuthor' => $meta['authorName'],
			'coverPath' => $expanded['media']['coverPath'],
			'duration' => (float)$item['duration'],
			'playMethod' => 0,
			'mediaPlayer' => $mediaPlayer,
			'deviceInfo' => $dev,
			'serverVersion' => Application::ABS_VERSION,
			'date' => date('Y-m-d'),
			'dayOfWeek' => date('l'),
			'timeListening' => 0,
			'startTime' => $startTime,
			'currentTime' => $startTime,
			'startedAt' => $now,
			'updatedAt' => $now,
			'audioTracks' => $expanded['media']['tracks'],
			'libraryItem' => $expanded,
		];
	}

	private function getSession(string $userId, string $sid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('session_id', $qb->createNamedParameter($sid)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row ?: null;
	}

	/**
	 * POST /api/session/:id/sync and /close. Body: currentTime, timeListened, duration.
	 * Returns false when the session is unknown.
	 */
	public function sync(string $userId, string $sid, array $body, bool $close): bool {
		$s = $this->getSession($userId, $sid);
		if ($s === null) {
			return false;
		}
		$num = static fn ($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v));
		$now = self::nowMs();
		$cur = $num($body['currentTime'] ?? null) ? (float)$body['currentTime'] : (float)$s['cur_time'];
		$listened = $num($body['timeListened'] ?? null) ? max(0.0, (float)$body['timeListened']) : 0.0;

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('cur_time', $qb->createNamedParameter($cur))
			->set('time_listening', $qb->createNamedParameter((float)$s['time_listening'] + $listened))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->set('closed', $qb->createNamedParameter($close ? 1 : 0, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$s['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		if ($num($body['currentTime'] ?? null)) {
			$item = $this->catalog->item($userId, (string)$s['item_key']);
			$dur = $num($body['duration'] ?? null) && (float)$body['duration'] > 0 ? (float)$body['duration'] : (float)($item['duration'] ?? 0);
			if ($item !== null) {
				$this->progress->apply($userId, (string)$s['item_key'], [
					'currentTime' => $cur,
					'duration' => $dur,
					'progress' => $dur > 0 ? min(1.0, $cur / $dur) : 0.0,
				], (float)$item['duration']);
			}
		}
		return true;
	}

	/**
	 * One local (offline) session from the phone, as in ABS syncLocalSession().
	 * @return array{id: string, success: bool, progressSynced: bool, error?: string}
	 */
	public function syncLocal(string $userId, array $s, array $deviceInfo): array {
		$sid = is_string($s['id'] ?? null) ? mb_substr($s['id'], 0, 64) : '';
		$key = is_scalar($s['libraryItemId'] ?? null) ? (string)$s['libraryItemId'] : '';
		$item = $key !== '' ? $this->catalog->item($userId, $key) : null;
		if ($sid === '' || $item === null) {
			return ['id' => $sid, 'success' => false, 'progressSynced' => false, 'error' => 'Media item not found'];
		}
		$num = static fn ($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v));
		$updatedAt = $num($s['updatedAt'] ?? null) ? (int)$s['updatedAt'] : self::nowMs();
		$cur = $num($s['currentTime'] ?? null) ? (float)$s['currentTime'] : 0.0;
		$dev = self::deviceInfo($deviceInfo ?: (is_array($s['deviceInfo'] ?? null) ? $s['deviceInfo'] : []));

		$existing = $this->getSession($userId, $sid);
		$qb = $this->db->getQueryBuilder();
		if ($existing === null) {
			$qb->insert(self::TABLE)->values([
				'session_id' => $qb->createNamedParameter($sid),
				'user_id' => $qb->createNamedParameter($userId),
				'item_key' => $qb->createNamedParameter($key),
				'play_method' => $qb->createNamedParameter((int)($s['playMethod'] ?? 3), IQueryBuilder::PARAM_INT),
				'media_player' => $qb->createNamedParameter(is_string($s['mediaPlayer'] ?? null) ? mb_substr($s['mediaPlayer'], 0, 64) : null),
				'device_info' => $qb->createNamedParameter(json_encode($dev)),
				'started_at' => $qb->createNamedParameter($num($s['startedAt'] ?? null) ? (int)$s['startedAt'] : $updatedAt, IQueryBuilder::PARAM_INT),
				'updated_at' => $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT),
				'time_listening' => $qb->createNamedParameter($num($s['timeListening'] ?? null) ? (float)$s['timeListening'] : 0.0),
				'start_time' => $qb->createNamedParameter($num($s['startTime'] ?? null) ? (float)$s['startTime'] : 0.0),
				'cur_time' => $qb->createNamedParameter($cur),
			]);
		} else {
			$qb->update(self::TABLE)
				->set('cur_time', $qb->createNamedParameter($cur))
				->set('time_listening', $qb->createNamedParameter($num($s['timeListening'] ?? null) ? (float)$s['timeListening'] : (float)$existing['time_listening']))
				->set('updated_at', $qb->createNamedParameter($updatedAt, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
		}
		$qb->executeStatement();

		$result = ['id' => $sid, 'success' => true, 'progressSynced' => false];
		$p = $this->progress->get($userId, $key);
		// Never move progress backwards over a newer update from another device (e.g. CrossPoint Sync).
		if ($p !== null && (int)$p['last_update'] > $updatedAt) {
			return $result;
		}
		$dur = (float)$item['duration'];
		$this->progress->apply($userId, $key, [
			'currentTime' => $cur,
			'duration' => $dur,
			'progress' => $dur > 0 ? min(1.0, $cur / $dur) : 0.0,
			'lastUpdate' => $updatedAt,
		], $dur);
		$result['progressSynced'] = true;
		return $result;
	}

	/** @return list<array> newest first */
	private function sessionRows(string $userId, ?int $limit = null, int $offset = 0): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('updated_at', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit)->setFirstResult($offset);
		}
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		return $rows;
	}

	private function countSessions(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/** Session in ABS JSON (as listed in stats and listening-sessions). */
	private function sessionJson(string $userId, array $s, array &$itemCache): array {
		$key = (string)$s['item_key'];
		if (!array_key_exists($key, $itemCache)) {
			$itemCache[$key] = $this->catalog->item($userId, $key);
		}
		$item = $itemCache[$key];
		$meta = $item !== null ? Serializer::bookMetadata($item, false) : ['title' => '', 'authorName' => ''];
		$started = (int)$s['started_at'];
		return [
			'id' => (string)$s['session_id'],
			'userId' => $userId,
			'libraryId' => $item['library_id'] ?? null,
			'libraryItemId' => $key,
			'bookId' => 'b' . $key,
			'episodeId' => null,
			'mediaType' => 'book',
			'mediaMetadata' => $meta,
			'chapters' => [],
			'displayTitle' => $meta['title'],
			'displayAuthor' => $meta['authorName'],
			'coverPath' => $item !== null && Serializer::hasCover($item) ? '/covers/' . $key : null,
			'duration' => (float)($item['duration'] ?? 0),
			'playMethod' => (int)$s['play_method'],
			'mediaPlayer' => $s['media_player'],
			'deviceInfo' => json_decode((string)$s['device_info'], true) ?: null,
			'serverVersion' => Application::ABS_VERSION,
			'date' => date('Y-m-d', intdiv($started, 1000)),
			'dayOfWeek' => date('l', intdiv($started, 1000)),
			'timeListening' => (float)$s['time_listening'],
			'startTime' => (float)$s['start_time'],
			'currentTime' => (float)$s['cur_time'],
			'startedAt' => $started,
			'updatedAt' => (int)$s['updated_at'],
		];
	}

	/** GET /api/me/listening-sessions */
	public function listSessions(string $userId, int $page, int $perPage): array {
		$total = $this->countSessions($userId);
		$cache = [];
		$sessions = array_map(fn ($s) => $this->sessionJson($userId, $s, $cache), $this->sessionRows($userId, $perPage, $page * $perPage));
		return [
			'total' => $total,
			'numPages' => (int)ceil($total / $perPage),
			'itemsPerPage' => $perPage,
			'page' => $page,
			'sessions' => $sessions,
		];
	}

	/** GET /api/me/listening-stats: totals per item, per day and per weekday (seconds). */
	public function stats(string $userId): array {
		$items = [];
		$days = [];
		$dow = [];
		$total = 0.0;
		$today = 0.0;
		$todayStr = date('Y-m-d');
		$cache = [];
		$rows = $this->sessionRows($userId);
		foreach ($rows as $s) {
			$t = (float)$s['time_listening'];
			if ($t <= 0) {
				continue;
			}
			$sec = intdiv((int)$s['started_at'], 1000);
			$day = date('Y-m-d', $sec);
			$total += $t;
			$days[$day] = ($days[$day] ?? 0) + $t;
			$dow[date('l', $sec)] = ($dow[date('l', $sec)] ?? 0) + $t;
			if ($day === $todayStr) {
				$today += $t;
			}
			$key = (string)$s['item_key'];
			if (!isset($items[$key])) {
				$json = $this->sessionJson($userId, $s, $cache);
				$items[$key] = ['id' => $key, 'timeListening' => 0.0, 'mediaMetadata' => $json['mediaMetadata']];
			}
			$items[$key]['timeListening'] += $t;
		}
		$recent = [];
		foreach (array_slice($rows, 0, 10) as $s) {
			$recent[] = $this->sessionJson($userId, $s, $cache);
		}
		return [
			'totalTime' => $total,
			'items' => (object)$items,
			'days' => (object)$days,
			'dayOfWeek' => (object)$dow,
			'today' => $today,
			'recentSessions' => $recent,
		];
	}

	public function deleteOldSessions(int $olderThanMs): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->lt('updated_at', $qb->createNamedParameter($olderThanMs, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
