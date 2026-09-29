<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the durable Files cleanup records (milestone 0.5.0, issue #54).
 *
 * A record captures the owner-resolved managed-folder identity so the folder can
 * be removed after the run rows are gone, even across a crash
 * (docs/folder-model.md §8/§8.2). The identity `(view_uid, storage_id, file_id)`
 * is authoritative; the descriptor fields are audit/display metadata only.
 */
class Version0013Date20260905000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Create Runbook Files cleanup records';
	}

	public function description(): string {
		return 'Adds the durable runbook_files_cleanup table for managed run folders.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('runbook_files_cleanup')) {
			return null;
		}

		$table = $schema->createTable('runbook_files_cleanup');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('kind', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('status', Types::STRING, ['length' => 16, 'notnull' => true, 'default' => 'pending']);
		$table->addColumn('view_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
		$table->addColumn('storage_id', Types::TEXT, ['notnull' => true]);
		$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('storage_root_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
		$table->addColumn('mount_type', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('mount_provider', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('mount_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('numeric_storage_id', Types::INTEGER, ['notnull' => false, 'default' => null]);
		$table->addColumn('path', Types::TEXT, ['notnull' => false, 'default' => null]);
		$table->addColumn('reason', Types::STRING, ['length' => 32, 'notnull' => false, 'default' => null]);
		$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('last_attempt_at', Types::BIGINT, ['notnull' => false, 'default' => null]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['status'], 'runbook_files_cleanup_status_idx');

		return $schema;
	}
}
