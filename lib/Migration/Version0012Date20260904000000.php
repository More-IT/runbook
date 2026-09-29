<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the Files attachment identity/metadata columns to attachments
 * (milestone 0.5.0, issue #50).
 *
 * New evidence is stored in the run's managed folder in Nextcloud Files; the
 * row records `storage_kind='files'` and the stable identity
 * `(storage_id, file_id)` plus descriptive metadata for audit/display only
 * (docs/folder-model.md §4.4). Existing rows are backfilled with
 * `storage_kind='appdata'` and keep their legacy `storage_key` until #55.
 */
class Version0012Date20260904000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook attachment Files identity';
	}

	public function description(): string {
		return 'Adds storage_kind and the Files identity/metadata columns to attachments.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('runbook_attachments')) {
			return null;
		}

		$table = $schema->getTable('runbook_attachments');
		$changed = false;

		if (!$table->hasColumn('storage_kind')) {
			// Existing rows are legacy AppData attachments.
			$table->addColumn('storage_kind', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'appdata']);
			$changed = true;
		}
		if (!$table->hasColumn('file_id')) {
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('storage_id')) {
			$table->addColumn('storage_id', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('storage_root_id')) {
			$table->addColumn('storage_root_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('mount_type')) {
			$table->addColumn('mount_type', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('mount_provider')) {
			$table->addColumn('mount_provider', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('mount_id')) {
			$table->addColumn('mount_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('numeric_storage_id')) {
			$table->addColumn('numeric_storage_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$table->hasColumn('path')) {
			$table->addColumn('path', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}

		if (!$table->hasIndex('runbook_attachments_file_idx')) {
			$table->addIndex(['file_id'], 'runbook_attachments_file_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
