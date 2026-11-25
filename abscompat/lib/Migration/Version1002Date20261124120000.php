<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Metadata edits get their own table. They used to sit on abs_items, whose rows are dropped when
 * a book goes missing, so the edits were lost with them.
 */
class Version1002Date20261124120000 extends SimpleMigrationStep {
	/** @var list<array{user_id: string, item_key: string, overrides: string}> */
	private array $existing = [];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abs_items') || !$schema->getTable('abs_items')->hasColumn('overrides')) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'item_key', 'overrides')->from('abs_items')
			->where($qb->expr()->isNotNull('overrides'));
		$res = $qb->executeQuery();
		$this->existing = $res->fetchAll();
		$res->closeCursor();
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('abs_overrides')) {
			$t = $schema->createTable('abs_overrides');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('item_key', Types::STRING, ['length' => 32]);
			$t->addColumn('data', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'item_key'], 'abs_overrides_user_key');
		}
		$items = $schema->getTable('abs_items');
		if ($items->hasColumn('overrides')) {
			$items->dropColumn('overrides');
		}
		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		foreach ($this->existing as $row) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('abs_overrides')->values([
				'user_id' => $qb->createNamedParameter($row['user_id']),
				'item_key' => $qb->createNamedParameter($row['item_key']),
				'data' => $qb->createNamedParameter($row['overrides']),
			]);
			$qb->executeStatement();
		}
	}
}
