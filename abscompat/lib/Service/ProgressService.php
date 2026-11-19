<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Media progress, following Audiobookshelf's MediaProgress.applyProgressUpdate().
 */
class ProgressService {
	private const TABLE = 'abs_progress';
	private const FINISH_TIME_REMAINING = 10.0;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	private static function nowMs(): int {
		return (int)floor(microtime(true) * 1000);
	}

	public function get(string $userId, string $itemKey): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($itemKey)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row ?: null;
	}

	/** @return array<string, array> item_key => row */
	public function allForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('last_update', 'DESC');
		$res = $qb->executeQuery();
		$out = [];
		while ($row = $res->fetch()) {
			$out[(string)$row['item_key']] = $row;
		}
		$res->closeCursor();
		return $out;
	}

	/**
	 * Apply an ABS progress payload. Returns the stored row.
	 *
	 * @param array<string,mixed> $p keys: duration, progress, currentTime, isFinished, hideFromContinueListening,
	 *                               ebookLocation, ebookProgress, finishedAt, lastUpdate
	 * @param float $itemDuration duration we know from the scan (used when the payload has none)
	 */
	public function apply(string $userId, string $itemKey, array $p, float $itemDuration): array {
		$now = self::nowMs();
		$row = $this->get($userId, $itemKey);
		[$s, $lastUpdate] = self::compute($row, $p, $itemDuration, $now);
		$this->save($userId, $itemKey, $s, $lastUpdate, $row === null);
		return $this->get($userId, $itemKey);
	}

	/**
	 * The ABS progress rules, without storage.
	 *
	 * @param array<string,mixed>|null $row stored row, or null for a new record
	 * @return array{0: array{cur_time: float, duration: float, progress: float, is_finished: bool, hide_continue: bool, ebook_progress: ?float, ebook_location: ?string, started_at: int, finished_at: ?int}, 1: int}
	 *         new state and its lastUpdate (ms)
	 */
	public static function compute(?array $row, array $p, float $itemDuration, int $now): array {
		$cur = $row ?? [
			'cur_time' => 0.0, 'duration' => $itemDuration, 'progress' => 0.0, 'is_finished' => 0,
			'hide_continue' => 0, 'ebook_progress' => null, 'ebook_location' => null,
			'started_at' => $now, 'finished_at' => null, 'last_update' => $now,
		];
		$s = [
			'cur_time' => (float)$cur['cur_time'],
			'duration' => (float)$cur['duration'],
			'progress' => (float)$cur['progress'],
			'is_finished' => (bool)$cur['is_finished'],
			'hide_continue' => (bool)$cur['hide_continue'],
			'ebook_progress' => $cur['ebook_progress'] !== null ? (float)$cur['ebook_progress'] : null,
			'ebook_location' => $cur['ebook_location'],
			'started_at' => (int)$cur['started_at'],
			'finished_at' => $cur['finished_at'] !== null ? (int)$cur['finished_at'] : null,
		];
		$oldTime = $s['cur_time'];
		$num = static fn ($v) => is_int($v) || is_float($v) || (is_string($v) && is_numeric($v));
		$hasCurrentTime = array_key_exists('currentTime', $p) && $num($p['currentTime']);

		if (array_key_exists('isFinished', $p) && $p['isFinished'] !== null) {
			$fin = filter_var($p['isFinished'], FILTER_VALIDATE_BOOLEAN);
			if ($fin && !$s['is_finished']) {
				$s['finished_at'] = $num($p['finishedAt'] ?? null) ? (int)$p['finishedAt'] : $now;
				$s['progress'] = 1.0;
			} elseif (!$fin && $s['is_finished']) {
				$s['finished_at'] = null;
				$s['progress'] = 0.0;
				$s['cur_time'] = 0.0;
				$hasCurrentTime = false;
			}
			$s['is_finished'] = $fin;
		} elseif (array_key_exists('progress', $p) && $num($p['progress']) && (float)$p['progress'] !== $s['progress']) {
			$s['progress'] = min(1.0, max(0.0, (float)$p['progress']));
		}

		if ($num($p['duration'] ?? null) && (float)$p['duration'] > 0) {
			$s['duration'] = (float)$p['duration'];
		} elseif ($s['duration'] <= 0) {
			$s['duration'] = $itemDuration;
		}
		if ($hasCurrentTime) {
			$s['cur_time'] = max(0.0, (float)$p['currentTime']);
			if (!array_key_exists('progress', $p) && !$s['is_finished'] && $s['duration'] > 0) {
				$s['progress'] = min(1.0, $s['cur_time'] / $s['duration']);
			}
		} elseif (array_key_exists('progress', $p) && $num($p['progress']) && !array_key_exists('isFinished', $p) && $s['duration'] > 0) {
			// Deviation from ABS: a progress-only update (CrossPoint Sync without a duration) also moves
			// the audio position, because players resume from currentTime.
			$s['cur_time'] = $s['progress'] * $s['duration'];
		}
		if (array_key_exists('ebookProgress', $p)) {
			$s['ebook_progress'] = $num($p['ebookProgress']) ? min(1.0, max(0.0, (float)$p['ebookProgress'])) : null;
		}
		if (array_key_exists('ebookLocation', $p)) {
			$s['ebook_location'] = is_string($p['ebookLocation']) ? $p['ebookLocation'] : null;
		}
		$timeChanged = abs($s['cur_time'] - $oldTime) > 0.0001;
		if (array_key_exists('hideFromContinueListening', $p)) {
			$s['hide_continue'] = filter_var($p['hideFromContinueListening'], FILTER_VALIDATE_BOOLEAN);
		} elseif ($timeChanged) {
			$s['hide_continue'] = false;
		}

		$shouldFinish = $s['duration'] > 0 && ($s['duration'] - $s['cur_time']) < self::FINISH_TIME_REMAINING;
		if (!$s['is_finished'] && $shouldFinish) {
			$s['is_finished'] = true;
			$s['finished_at'] = $s['finished_at'] ?? $now;
			$s['progress'] = 1.0;
		} elseif ($s['is_finished'] && $timeChanged && !$shouldFinish) {
			$s['is_finished'] = false;
			$s['finished_at'] = null;
		}

		$lastUpdate = $num($p['lastUpdate'] ?? null) && (int)$p['lastUpdate'] > 0 ? (int)$p['lastUpdate'] : $now;
		return [$s, $lastUpdate];
	}

	private function save(string $userId, string $itemKey, array $s, int $lastUpdate, bool $isNew): void {
		$vals = [
			'cur_time' => [$s['cur_time'], IQueryBuilder::PARAM_STR],
			'duration' => [$s['duration'], IQueryBuilder::PARAM_STR],
			'progress' => [$s['progress'], IQueryBuilder::PARAM_STR],
			'is_finished' => [$s['is_finished'] ? 1 : 0, IQueryBuilder::PARAM_INT],
			'hide_continue' => [$s['hide_continue'] ? 1 : 0, IQueryBuilder::PARAM_INT],
			'ebook_progress' => [$s['ebook_progress'], $s['ebook_progress'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR],
			'ebook_location' => [$s['ebook_location'], $s['ebook_location'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR],
			'finished_at' => [$s['finished_at'], $s['finished_at'] === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT],
			'last_update' => [$lastUpdate, IQueryBuilder::PARAM_INT],
		];
		$qb = $this->db->getQueryBuilder();
		if ($isNew) {
			$qb->insert(self::TABLE);
			$qb->setValue('user_id', $qb->createNamedParameter($userId));
			$qb->setValue('item_key', $qb->createNamedParameter($itemKey));
			$qb->setValue('started_at', $qb->createNamedParameter($s['started_at'], IQueryBuilder::PARAM_INT));
			foreach ($vals as $k => [$v, $t]) {
				$qb->setValue($k, $qb->createNamedParameter($v, $t));
			}
		} else {
			$qb->update(self::TABLE);
			foreach ($vals as $k => [$v, $t]) {
				$qb->set($k, $qb->createNamedParameter($v, $t));
			}
			$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($itemKey)));
		}
		$qb->executeStatement();
	}

	public function delete(string $userId, string $itemKey): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($itemKey)));
		$qb->executeStatement();
	}

	/** ABS "old" media progress JSON. */
	public static function toJson(array $row, string $userId): array {
		$key = (string)$row['item_key'];
		return [
			'id' => 'mp_' . $key,
			'userId' => $userId,
			'libraryItemId' => $key,
			'episodeId' => null,
			'mediaItemId' => 'b' . $key,
			'mediaItemType' => 'book',
			'duration' => (float)$row['duration'],
			'progress' => (float)$row['progress'],
			'currentTime' => (float)$row['cur_time'],
			'isFinished' => (bool)$row['is_finished'],
			'hideFromContinueListening' => (bool)$row['hide_continue'],
			'ebookLocation' => $row['ebook_location'],
			'ebookProgress' => $row['ebook_progress'] !== null ? (float)$row['ebook_progress'] : null,
			'lastUpdate' => (int)$row['last_update'],
			'startedAt' => (int)$row['started_at'],
			'finishedAt' => $row['finished_at'] !== null ? (int)$row['finished_at'] : null,
		];
	}
}
