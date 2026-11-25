<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** More details per book (tags, further series, ebooks, ...), and playlists, collections and series notes. */
class Version1003Date20261126120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$items = $schema->getTable('abs_items');
		if (!$items->hasColumn('extra')) {
			$items->addColumn('extra', Types::TEXT, ['notnull' => false]);
		}
		if (!$schema->hasTable('abs_lists')) {
			$t = $schema->createTable('abs_lists');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('list_id', Types::STRING, ['length' => 40]);
			$t->addColumn('kind', Types::STRING, ['length' => 16]);
			$t->addColumn('library_id', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('name', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('items', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'list_id'], 'abs_lists_user_list');
		}
		return $schema;
	}
}
