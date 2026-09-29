<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the frozen destination "configured by" identity to runs for the global
 * administration destination setting (milestone 0.5.0, issue #47).
 *
 * The column is nullable so existing runs (and every run started with the
 * default `Files/Runbook` destination) stay untouched. It records the user who
 * selected the configured reference (audit only); it is never part of the
 * destination identity.
 */
class Version0010Date20260902000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook destination configured-by to runs';
	}

	public function description(): string {
		return 'Adds the destination_configured_by column to runs.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('runbook_runs')) {
			return null;
		}

		$runs = $schema->getTable('runbook_runs');
		if ($runs->hasColumn('destination_configured_by')) {
			return null;
		}

		$runs->addColumn('destination_configured_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);

		return $schema;
	}
}
