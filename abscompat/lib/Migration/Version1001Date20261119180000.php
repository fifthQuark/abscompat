<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Bookmarks, and user metadata overrides that survive rescans. */
class Version1001Date20261119180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('abs_bookmarks')) {
			$t = $schema->createTable('abs_bookmarks');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('item_key', Types::STRING, ['length' => 32]);
			$t->addColumn('time', Types::FLOAT, ['default' => 0]);
			$t->addColumn('title', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['user_id', 'item_key'], 'abs_bookmarks_user_item');
		}

		$items = $schema->getTable('abs_items');
		if (!$items->hasColumn('overrides')) {
			$items->addColumn('overrides', Types::TEXT, ['notnull' => false]);
		}
		return $schema;
	}
}
