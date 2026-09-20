<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds assignment and due-date support to runs.
 *
 * - runbook_runs gains an optional due_at.
 * - runbook_run_steps gains assignee_type, assignee_id and an optional due_at.
 * - runbook_run_acl stores run participant/viewer entries.
 *
 * Existing run snapshots keep their values; new nullable columns default to
 * null so upgrades preserve historical data.
 */
class Version0004Date20260401000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook run assignments and due dates';
	}

	public function description(): string {
		return 'Adds run and step due dates, step assignees and the run access control list.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('runbook_runs')) {
			$runs = $schema->getTable('runbook_runs');
			if (!$runs->hasColumn('due_at')) {
				$runs->addColumn('due_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		if ($schema->hasTable('runbook_run_steps')) {
			$steps = $schema->getTable('runbook_run_steps');
			if (!$steps->hasColumn('assignee_type')) {
				$steps->addColumn('assignee_type', Types::STRING, ['length' => 16, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$steps->hasColumn('assignee_id')) {
				$steps->addColumn('assignee_id', Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$steps->hasColumn('due_at')) {
				$steps->addColumn('due_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$steps->hasIndex('runbook_run_steps_assignee_idx')) {
				$steps->addIndex(['assignee_type', 'assignee_id'], 'runbook_run_steps_assignee_idx');
				$changed = true;
			}
		}

		if (!$schema->hasTable('runbook_run_acl')) {
			$table = $schema->createTable('runbook_run_acl');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('principal_type', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('principal_id', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('role', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['run_id', 'principal_type', 'principal_id'], 'runbook_run_acl_principal_uniq');
			$table->addIndex(['run_id'], 'runbook_run_acl_run_idx');
			$table->addIndex(['principal_type', 'principal_id'], 'runbook_run_acl_principal_idx');
			$table->addIndex(['role'], 'runbook_run_acl_role_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_runs'),
				['run_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_run_acl_run_fk',
			);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
