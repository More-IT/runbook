<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the process-flow configuration to template and run sections.
 *
 * - depends_on: JSON array of prerequisite section ids.
 * - condition_config: JSON condition ({stepId, operator, value}) or NULL.
 *
 * Both columns are nullable so the migration is portable and existing linear
 * templates keep working unchanged (empty dependencies, no condition).
 */
class Version0008Date20260801000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook section flow configuration';
	}

	public function description(): string {
		return 'Adds depends_on and condition columns to template and run sections.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		foreach (['runbook_template_sections', 'runbook_run_sections'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if (!$table->hasColumn('depends_on')) {
				$table->addColumn('depends_on', Types::TEXT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$table->hasColumn('condition_config')) {
				$table->addColumn('condition_config', Types::TEXT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
