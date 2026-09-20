<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the notification delivery ledger.
 *
 * The ledger records the delivery state (pending, sent or failed) of a
 * notification so that event-driven and background notifications are never
 * delivered twice, while transient failures stay retryable. Rows cascade when
 * the referenced run or run step is deleted.
 */
class Version0006Date20260601000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook notification delivery ledger';
	}

	public function description(): string {
		return 'Adds a deduplication ledger for Runbook notifications.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('runbook_notification_deliveries')) {
			return null;
		}

		$table = $schema->createTable('runbook_notification_deliveries');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('dedupe_key', Types::STRING, ['length' => 255, 'notnull' => true]);
		$table->addColumn('type', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('user_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('step_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
		$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['dedupe_key'], 'runbook_notification_deliveries_uniq');
		$table->addIndex(['type', 'step_id'], 'runbook_notification_deliveries_type_idx');
		$table->addIndex(['run_id'], 'runbook_notification_deliveries_run_idx');
		$table->addIndex(['user_uid'], 'runbook_notification_deliveries_user_idx');
		$table->addForeignKeyConstraint(
			$schema->getTable('runbook_runs'),
			['run_id'],
			['id'],
			['onDelete' => 'CASCADE'],
			'runbook_notification_deliveries_run_fk',
		);
		$table->addForeignKeyConstraint(
			$schema->getTable('runbook_run_steps'),
			['step_id'],
			['id'],
			['onDelete' => 'CASCADE'],
			'runbook_notification_deliveries_step_fk',
		);

		return $schema;
	}
}
