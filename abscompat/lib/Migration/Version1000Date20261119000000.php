<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261119000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('abs_items')) {
			$t = $schema->createTable('abs_items');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('library_id', Types::STRING, ['length' => 32]);
			$t->addColumn('item_key', Types::STRING, ['length' => 32]);
			$t->addColumn('is_file', Types::SMALLINT, ['default' => 0]);
			$t->addColumn('rel_path', Types::STRING, ['length' => 4000, 'notnull' => false]);
			$t->addColumn('title', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('subtitle', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('author', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('narrator', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('series', Types::STRING, ['length' => 512, 'notnull' => false]);
			$t->addColumn('series_seq', Types::STRING, ['length' => 32, 'notnull' => false]);
			$t->addColumn('published_year', Types::STRING, ['length' => 16, 'notnull' => false]);
			$t->addColumn('description', Types::TEXT, ['notnull' => false]);
			$t->addColumn('genres', Types::TEXT, ['notnull' => false]);
			$t->addColumn('duration', Types::FLOAT, ['default' => 0]);
			$t->addColumn('size', Types::BIGINT, ['default' => 0]);
			$t->addColumn('chapters', Types::TEXT, ['notnull' => false]);
			$t->addColumn('cover_file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('cover_cache', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('ebook_file_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('added_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('mtime', Types::BIGINT, ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'item_key'], 'abs_items_user_key');
			$t->addIndex(['user_id', 'library_id'], 'abs_items_user_lib');
		}

		if (!$schema->hasTable('abs_tracks')) {
			$t = $schema->createTable('abs_tracks');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('item_id', Types::BIGINT);
			$t->addColumn('track_index', Types::INTEGER, ['default' => 0]);
			$t->addColumn('file_id', Types::BIGINT);
			$t->addColumn('filename', Types::STRING, ['length' => 512]);
			$t->addColumn('rel_path', Types::STRING, ['length' => 4000, 'notnull' => false]);
			$t->addColumn('size', Types::BIGINT, ['default' => 0]);
			$t->addColumn('duration', Types::FLOAT, ['default' => 0]);
			$t->addColumn('start_offset', Types::FLOAT, ['default' => 0]);
			$t->addColumn('mime', Types::STRING, ['length' => 128, 'notnull' => false]);
			$t->addColumn('mtime', Types::BIGINT, ['default' => 0]);
			$t->addColumn('meta', Types::TEXT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['item_id'], 'abs_tracks_item');
			$t->addIndex(['file_id'], 'abs_tracks_file');
		}

		if (!$schema->hasTable('abs_progress')) {
			$t = $schema->createTable('abs_progress');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('item_key', Types::STRING, ['length' => 32]);
			$t->addColumn('cur_time', Types::FLOAT, ['default' => 0]);
			$t->addColumn('duration', Types::FLOAT, ['default' => 0]);
			$t->addColumn('progress', Types::FLOAT, ['default' => 0]);
			$t->addColumn('is_finished', Types::SMALLINT, ['default' => 0]);
			$t->addColumn('hide_continue', Types::SMALLINT, ['default' => 0]);
			$t->addColumn('ebook_progress', Types::FLOAT, ['notnull' => false]);
			$t->addColumn('ebook_location', Types::TEXT, ['notnull' => false]);
			$t->addColumn('started_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('finished_at', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('last_update', Types::BIGINT, ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['user_id', 'item_key'], 'abs_progress_user_key');
		}

		if (!$schema->hasTable('abs_sessions')) {
			$t = $schema->createTable('abs_sessions');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('session_id', Types::STRING, ['length' => 64]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('item_key', Types::STRING, ['length' => 32]);
			$t->addColumn('play_method', Types::SMALLINT, ['default' => 0]);
			$t->addColumn('media_player', Types::STRING, ['length' => 64, 'notnull' => false]);
			$t->addColumn('device_info', Types::TEXT, ['notnull' => false]);
			$t->addColumn('started_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('updated_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('time_listening', Types::FLOAT, ['default' => 0]);
			$t->addColumn('start_time', Types::FLOAT, ['default' => 0]);
			$t->addColumn('cur_time', Types::FLOAT, ['default' => 0]);
			$t->addColumn('closed', Types::SMALLINT, ['default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['session_id'], 'abs_sessions_sid');
			$t->addIndex(['user_id', 'updated_at'], 'abs_sessions_user');
		}

		if (!$schema->hasTable('abs_tokens')) {
			$t = $schema->createTable('abs_tokens');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('user_id', Types::STRING, ['length' => 64]);
			$t->addColumn('name', Types::STRING, ['length' => 255]);
			// apikey | access | refresh
			$t->addColumn('kind', Types::STRING, ['length' => 16]);
			$t->addColumn('token_hash', Types::STRING, ['length' => 64]);
			$t->addColumn('parent_id', Types::BIGINT, ['notnull' => false]);
			$t->addColumn('created_at', Types::BIGINT, ['default' => 0]);
			$t->addColumn('last_used', Types::BIGINT, ['default' => 0]);
			$t->addColumn('expires_at', Types::BIGINT, ['notnull' => false]);
			$t->setPrimaryKey(['id']);
			$t->addUniqueIndex(['token_hash'], 'abs_tokens_hash');
			$t->addIndex(['user_id'], 'abs_tokens_user');
			$t->addIndex(['parent_id'], 'abs_tokens_parent');
		}

		return $schema;
	}
}
