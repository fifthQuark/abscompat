<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** Playlists, collections and descriptions of series, per user. */
class ListService {
	public const PLAYLIST = 'playlist';
	public const COLLECTION = 'collection';
	private const SERIES = 'series';
	private const TABLE = 'abs_lists';
	private const MAX_ITEMS = 2000;

	/** @var array<string, array<string, string>> */
	private array $descriptions = [];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/** @return list<array> */
	public function all(string $userId, string $kind, ?string $libraryId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)));
		if ($libraryId !== null) {
			$qb->andWhere($qb->expr()->eq('library_id', $qb->createNamedParameter($libraryId)));
		}
		$res = $qb->executeQuery();
		$rows = $res->fetchAll();
		$res->closeCursor();
		usort($rows, static fn ($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']) ?: (int)$a['id'] <=> (int)$b['id']);
		return $rows;
	}

	public function get(string $userId, string $kind, string $listId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter($kind)))
			->andWhere($qb->expr()->eq('list_id', $qb->createNamedParameter($listId)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row ?: null;
	}

	/** @return list<string> item keys */
	public static function items(array $list): array {
		return array_map('strval', json_decode((string)$list['items'], true) ?: []);
	}

	public static function cleanName(mixed $v): string {
		return is_string($v) ? mb_substr(MetadataExtractor::cleanText(strip_tags($v)), 0, 200) : '';
	}

	public static function cleanDescription(mixed $v): ?string {
		$v = is_string($v) ? mb_substr(MetadataExtractor::cleanText($v), 0, 20000) : '';
		return $v === '' ? null : $v;
	}

	/** @param list<string> $items */
	public function create(string $userId, string $kind, string $libraryId, string $name, ?string $description, array $items): array {
		$h = bin2hex(random_bytes(16));
		$id = sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
		$this->insert($userId, $kind, $id, $libraryId, $name, $description, $items);
		return $this->get($userId, $kind, $id);
	}

	private function insert(string $userId, string $kind, string $id, ?string $libraryId, ?string $name, ?string $description, array $items): void {
		$now = (int)(microtime(true) * 1000);
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE);
		foreach (['user_id' => $userId, 'list_id' => $id, 'kind' => $kind, 'library_id' => $libraryId, 'name' => $name, 'description' => $description, 'items' => self::encode($items)] as $k => $v) {
			$qb->setValue($k, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR));
		}
		foreach (['created_at', 'updated_at'] as $k) {
			$qb->setValue($k, $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT));
		}
		$qb->executeStatement();
	}

	private static function encode(array $items): string {
		return json_encode(array_slice(array_values(array_unique(array_map('strval', $items))), 0, self::MAX_ITEMS));
	}

	/** @param array{name?: string, description?: ?string, items?: list<string>} $changes */
	public function update(string $userId, array $list, array $changes): array {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)->set('updated_at', $qb->createNamedParameter((int)(microtime(true) * 1000), IQueryBuilder::PARAM_INT));
		foreach ($changes as $k => $v) {
			$v = $k === 'items' ? self::encode($v) : $v;
			$qb->set($k, $qb->createNamedParameter($v, $v === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$list['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		return $this->get($userId, (string)$list['kind'], (string)$list['list_id']);
	}

	public function delete(array $list): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$list['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	// ----- series -----

	public function seriesDescription(string $userId, string $seriesId): ?string {
		if (!isset($this->descriptions[$userId])) {
			$this->descriptions[$userId] = [];
			foreach ($this->all($userId, self::SERIES) as $r) {
				$this->descriptions[$userId][(string)$r['list_id']] = (string)$r['description'];
			}
		}
		return $this->descriptions[$userId][$seriesId] ?? null;
	}

	public function setSeriesDescription(string $userId, string $seriesId, ?string $text): void {
		$row = $this->get($userId, self::SERIES, $seriesId);
		if ($text === null && $row !== null) {
			$this->delete($row);
		} elseif ($text !== null && $row !== null) {
			$this->update($userId, $row, ['description' => $text]);
		} elseif ($text !== null) {
			$this->insert($userId, self::SERIES, $seriesId, null, null, $text, []);
		}
		unset($this->descriptions[$userId]);
	}
}
