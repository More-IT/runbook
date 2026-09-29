<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the optional per-template destination folder reference to templates
 * (milestone 0.5.0, issue #48).
 *
 * The reference is stored exactly like the administration reference
 * (docs/folder-model.md §4.2): the exact identity `destination_storage_id` +
 * `destination_file_id`, the advisory `destination_path` and the
 * `destination_configured_by` audit value. Every column is nullable so existing
 * templates are untouched and remain "unset"; a partially populated reference is
 * invalid and fails closed at run start. `destination_view_uid` is not stored:
 * the reference is always re-resolved in the run owner's view.
 *
 * The storage id / path use unbounded `TEXT` because the OCP contract documents
 * no maximum length for `IStorage::getId()`; `destination_configured_by` is a
 * Nextcloud user id (bounded by Nextcloud).
 */
class Version0011Date20260903000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Add Runbook template destination reference';
	}

	public function description(): string {
		return 'Adds the optional per-template Files destination reference columns to templates.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if (!$schema->hasTable('runbook_templates')) {
			return null;
		}

		$templates = $schema->getTable('runbook_templates');
		$changed = false;

		if (!$templates->hasColumn('destination_storage_id')) {
			$templates->addColumn('destination_storage_id', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$templates->hasColumn('destination_file_id')) {
			$templates->addColumn('destination_file_id', Types::BIGINT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$templates->hasColumn('destination_path')) {
			$templates->addColumn('destination_path', Types::TEXT, ['notnull' => false, 'default' => null]);
			$changed = true;
		}
		if (!$templates->hasColumn('destination_configured_by')) {
			$templates->addColumn('destination_configured_by', Types::STRING, ['length' => 64, 'notnull' => false, 'default' => null]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
