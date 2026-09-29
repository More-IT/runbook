<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the run execution tables.
 *
 * Physical tables: oc_runbook_runs, oc_runbook_run_sections and
 * oc_runbook_run_steps. Run content is an independent snapshot of the source
 * template. The template reference is nullable and uses ON DELETE SET NULL so
 * that deleting a template never deletes its historical runs.
 */
class Version0003Date20260301000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Create Runbook run tables';
	}

	public function description(): string {
		return 'Adds tables for runs, run sections and run steps.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('runbook_runs')) {
			$table = $schema->createTable('runbook_runs');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('template_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('template_version', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true]);
			$table->addColumn('owner', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('cancelled_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('reopened_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('completed_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('cancelled_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner'], 'runbook_runs_owner_idx');
			$table->addIndex(['status'], 'runbook_runs_status_idx');
			$table->addIndex(['template_id'], 'runbook_runs_template_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_templates'),
				['template_id'],
				['id'],
				['onDelete' => 'SET NULL'],
				'runbook_runs_template_fk',
			);
			$changed = true;
		}

		if (!$schema->hasTable('runbook_run_sections')) {
			$table = $schema->createTable('runbook_run_sections');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('source_section_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_id'], 'runbook_run_sections_run_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_runs'),
				['run_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_run_sections_run_fk',
			);
			$changed = true;
		}

		if (!$schema->hasTable('runbook_run_steps')) {
			$table = $schema->createTable('runbook_run_steps');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('run_section_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('source_step_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true, 'default' => null]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true]);
			$table->addColumn('type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('config', Types::TEXT, ['notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true]);
			$table->addColumn('response', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('skip_reason', Types::TEXT, ['notnull' => false, 'default' => null]);
			$table->addColumn('started_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('completed_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('skipped_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('reopened_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['run_section_id'], 'runbook_run_steps_section_idx');
			$table->addIndex(['status'], 'runbook_run_steps_status_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_run_sections'),
				['run_section_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_run_steps_section_fk',
			);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
