<?php

declare(strict_types=1);

namespace OCA\Runbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the template access control table.
 *
 * The physical table is oc_runbook_template_acl. Only the principal type and
 * identifier are stored; users and groups remain owned by Nextcloud.
 */
class Version0002Date20260201000000 extends SimpleMigrationStep {
	public function name(): string {
		return 'Create Runbook template ACL table';
	}

	public function description(): string {
		return 'Adds the table storing direct user and group access control entries for templates.';
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array<string, mixed> $options
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('runbook_template_acl')) {
			return null;
		}

		$table = $schema->createTable('runbook_template_acl');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('template_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('principal_type', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('principal_id', Types::STRING, ['length' => 255, 'notnull' => true]);
		$table->addColumn('role', Types::STRING, ['length' => 16, 'notnull' => true]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['template_id', 'principal_type', 'principal_id'], 'runbook_acl_principal_uniq');
		$table->addIndex(['template_id'], 'runbook_acl_template_idx');
		$table->addIndex(['principal_type', 'principal_id'], 'runbook_acl_principal_idx');
		$table->addIndex(['role'], 'runbook_acl_role_idx');
		$table->addForeignKeyConstraint(
			$schema->getTable('runbook_templates'),
			['template_id'],
			['id'],
			['onDelete' => 'CASCADE'],
			'runbook_acl_template_fk',
		);

		return $schema;
	}
}
