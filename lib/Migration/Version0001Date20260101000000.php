<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the template authoring tables.
 *
 * Physical table names get the Nextcloud table prefix, e.g.
 * oc_runbook_templates, oc_runbook_template_sections, oc_runbook_template_steps.
 */
class Version0001Date20260101000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Create Runbook template tables';
	}

	public function description(): string {
		return 'Adds tables for templates, template sections and template steps.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('runbook_templates')) {
			$table = $schema->createTable('runbook_templates');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true]);
			$table->addColumn('version', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('owner', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('published_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->addColumn('archived_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner'], 'runbook_templates_owner_idx');
			$table->addIndex(['status'], 'runbook_templates_status_idx');
			$changed = true;
		}

		if (!$schema->hasTable('runbook_template_sections')) {
			$table = $schema->createTable('runbook_template_sections');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('template_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['template_id'], 'runbook_sections_template_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_templates'),
				['template_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_sections_template_fk',
			);
			$changed = true;
		}

		if (!$schema->hasTable('runbook_template_steps')) {
			$table = $schema->createTable('runbook_template_steps');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('section_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('uuid', Types::STRING, ['length' => 36, 'notnull' => true]);
			$table->addColumn('title', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('description', Types::TEXT, ['notnull' => true]);
			$table->addColumn('type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('required', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
			$table->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('config', Types::TEXT, ['notnull' => true]);
			$table->addColumn('default_assignee', Types::STRING, ['length' => 255, 'notnull' => false, 'default' => null]);
			$table->addColumn('due_offset', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['section_id'], 'runbook_steps_section_idx');
			$table->addForeignKeyConstraint(
				$schema->getTable('runbook_template_sections'),
				['section_id'],
				['id'],
				['onDelete' => 'CASCADE'],
				'runbook_steps_section_fk',
			);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
