<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds collaboration, evidence and activity history tables.
 *
 * - runbook_comments: plain-text comments on runs and run steps.
 * - runbook_comment_mentions: valid user mentions extracted from comments.
 * - runbook_attachments: evidence metadata; the file itself lives in AppData.
 * - runbook_activity: append-only activity history.
 *
 * Comments, mentions and attachments are subordinate to a run and cascade when
 * the run is deleted. Activity is append-only through the application.
 */
class Version0005Date20260501000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook comments, evidence and activity tables';
	}

	public function description(): string {
		return 'Adds comments, mentions, evidence attachments and append-only activity history.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('runbook_comments')) {
			$table = $schema->createTable('runbook_comments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('step_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('author_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('body', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'runbook_comments_run_idx');
			$table->addIndex(['step_id'], 'runbook_comments_step_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_runs'),
				['run_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_comments_run_fk',
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_run_steps'),
				['step_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_comments_step_fk',
			);
			$changed = true;
		}

		if (!$schema->hasTable('runbook_comment_mentions')) {
			$table = $schema->createTable('runbook_comment_mentions');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('comment_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('mentioned_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['comment_id', 'mentioned_uid'], 'runbook_comment_mentions_uniq');
			$table->addIndex(['mentioned_uid'], 'runbook_comment_mentions_user_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_comments'),
				['comment_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_comment_mentions_comment_fk',
			);
			$changed = true;
		}

		if (!$schema->hasTable('runbook_attachments')) {
			$table = $schema->createTable('runbook_attachments');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('step_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uploader_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('filename', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('storage_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('mime_type', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('size', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('checksum', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'runbook_attachments_run_idx');
			$table->addIndex(['step_id'], 'runbook_attachments_step_idx');
			$table->addIndex(['uploader_uid'], 'runbook_attachments_uploader_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_runs'),
				['run_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_attachments_run_fk',
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_run_steps'),
				['step_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_attachments_step_fk',
			);
			$changed = true;
		}

		if (!$schema->hasTable('runbook_activity')) {
			$table = $schema->createTable('runbook_activity');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('step_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('actor_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('event_type', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('metadata', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'runbook_activity_run_idx');
			$table->addIndex(['step_id'], 'runbook_activity_step_idx');
			$table->addIndex(['created_at'], 'runbook_activity_created_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_runs'),
				['run_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_activity_run_fk',
			);
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_run_steps'),
				['step_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_activity_step_fk',
			);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
