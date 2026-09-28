<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the frozen Files destination and the managed per-run folder identity to
 * runs (milestone 0.5.0, issue #46).
 *
 * Every column is nullable so existing runs stay untouched and are recognisable
 * as legacy (all destination/run-folder columns NULL). Identity is
 * `destination_view_uid` + `destination_storage_id` + `destination_file_id`;
 * the remaining destination_/run_folder_ columns are non-authoritative
 * descriptor metadata for audit/display only and are never an identity,
 * deduplication or tie-break key.
 *
 * `destination_view_uid` is a Nextcloud user id (bounded by Nextcloud) and
 * `destination_source` is a short enum string. The Files storage/mount strings
 * (`*_storage_id`, `*_path`, `*_mount_type`, `*_mount_provider`) use unbounded
 * `TEXT` because the OCP contract documents no maximum length for them.
 */
class Version0009Date20260901000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook run Files destination';
	}

	public function description(): string {
		return 'Adds the frozen destination and managed per-run folder identity columns to runs.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('runbook_runs')) {
			return null;
		}

		$runs = $schema->getTable('runbook_runs');
		$changed = false;

		$stringColumns = [
			'destination_view_uid' => 64,
			'destination_source' => 16,
		];
		foreach ($stringColumns as $column => $length) {
			if (!$runs->hasColumn($column)) {
				$runs->addColumn($column, Types::STRING, ['length' => $length, 'notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		$textColumns = [
			'destination_storage_id',
			'destination_path',
			'destination_mount_type',
			'destination_mount_provider',
			'run_folder_storage_id',
			'run_folder_path',
			'run_folder_mount_type',
			'run_folder_mount_provider',
		];
		foreach ($textColumns as $column) {
			if (!$runs->hasColumn($column)) {
				$runs->addColumn($column, Types::TEXT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		$bigintColumns = [
			'destination_file_id',
			'destination_storage_root_id',
			'run_folder_file_id',
			'run_folder_storage_root_id',
		];
		foreach ($bigintColumns as $column) {
			if (!$runs->hasColumn($column)) {
				$runs->addColumn($column, Types::BIGINT, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		$intColumns = [
			'destination_mount_id',
			'destination_numeric_storage_id',
			'run_folder_mount_id',
			'run_folder_numeric_storage_id',
		];
		foreach ($intColumns as $column) {
			if (!$runs->hasColumn($column)) {
				$runs->addColumn($column, Types::INTEGER, ['notnull' => false, 'default' => null]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
