<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Audio bookmarks. ABS identifies a bookmark by (item, time). */
class BookmarkService {
	private const TABLE = 'abs_bookmarks';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public static function toJson(array $row): array {
		return [
			'libraryItemId' => (string)$row['item_key'],
			'title' => (string)($row['title'] ?? ''),
			'time' => (float)$row['time'],
			'createdAt' => (int)$row['created_at'],
		];
	}

	/** @return list<array> */
	public function allForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'ASC');
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		return array_map([self::class, 'toJson'], $rows);
	}

	private function find(string $userId, string $key, float $time): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('item_key', $qb->createNamedParameter($key)));
		$res = $qb->executeQuery();
		$found = null;
		while ($row = $res->fetch()) {
			if (abs((float)$row['time'] - $time) < 0.001) {
				$found = $row;
				break;
			}
		}
		$res->closeCursor();
		return $found;
	}

	/** Create, or rename when one exists at that time. */
	public function upsert(string $userId, string $key, float $time, string $title): array {
		$title = mb_substr($title, 0, 512);
		$existing = $this->find($userId, $key, $time);
		$qb = $this->db->getQueryBuilder();
		if ($existing !== null) {
			$qb->update(self::TABLE)->set('title', $qb->createNamedParameter($title))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existing['id'], IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			$existing['title'] = $title;
			return self::toJson($existing);
		}
		$now = (int)(microtime(true) * 1000);
		$qb->insert(self::TABLE)->values([
			'user_id' => $qb->createNamedParameter($userId),
			'item_key' => $qb->createNamedParameter($key),
			'time' => $qb->createNamedParameter($time),
			'title' => $qb->createNamedParameter($title),
			'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
		]);
		$qb->executeStatement();
		return ['libraryItemId' => $key, 'title' => $title, 'time' => $time, 'createdAt' => $now];
	}

	public function update(string $userId, string $key, float $time, string $title): ?array {
		return $this->find($userId, $key, $time) === null ? null : $this->upsert($userId, $key, $time, $title);
	}

	public function delete(string $userId, string $key, float $time): bool {
		$row = $this->find($userId, $key, $time);
		if ($row === null) {
			return false;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return true;
	}

	public function deleteAllForUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
