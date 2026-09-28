<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the legacy AppData migration state (milestone 0.5.0, issue #55).
 *
 * Runs gain `destination_migrated_at` (the timestamp the destination was frozen
 * by the migration), `migration_state` (`pending|blocked|done`) and
 * `migration_reason`. Attachments gain their own `migration_state` /
 * `migration_reason` so an interrupted migration can resume per attachment
 * without copying evidence twice or deleting a source prematurely
 * (docs/folder-model.md §8.1).
 */
class Version0014Date20260906000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook legacy AppData migration state';
	}

	public function description(): string {
		return 'Adds migration state columns for legacy AppData evidence.';
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
			if (!$runs->hasColumn('destination_migrated_at')) {
				$runs->addColumn('destination_migrated_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$runs->hasColumn('migration_state')) {
				$runs->addColumn('migration_state', Types::STRING, ['length' => 16, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$runs->hasColumn('migration_reason')) {
				$runs->addColumn('migration_reason', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$runs->hasColumn('migration_attempted_at')) {
				// Durable "least recently attempted" timestamp so bounded batches
				// make forward progress instead of re-selecting blocked runs.
				$runs->addColumn('migration_attempted_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$runs->hasIndex('runbook_runs_migration_idx')) {
				$runs->addIndex(['migration_state'], 'runbook_runs_migration_idx');
				$changed = true;
			}
		}

		if ($schema->hasTable('runbook_attachments')) {
			$attachments = $schema->getTable('runbook_attachments');
			if (!$attachments->hasColumn('migration_state')) {
				$attachments->addColumn('migration_state', Types::STRING, ['length' => 16, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
			if (!$attachments->hasColumn('migration_reason')) {
				$attachments->addColumn('migration_reason', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
