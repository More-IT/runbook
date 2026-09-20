<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a free-form notes column to template and run sections.
 *
 * Notes are authored on the template and copied into the run snapshot. They are
 * nullable so the migration is portable and safe for existing rows on MySQL,
 * PostgreSQL and SQLite.
 */
class Version0007Date20260701000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook section notes';
	}

	public function description(): string {
		return 'Adds a notes field to template sections and run sections.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		foreach (['runbook_template_sections', 'runbook_run_sections'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('notes')) {
				continue;
			}
			$table->addColumn('notes', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
