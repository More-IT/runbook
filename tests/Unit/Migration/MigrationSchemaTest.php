<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Migration;

use OCA\Runbook\Migration\Version0001Date20260101000000;
use OCA\Runbook\Migration\Version0002Date20260201000000;
use OCA\Runbook\Migration\Version0003Date20260301000000;
use OCA\Runbook\Migration\Version0004Date20260401000000;
use OCA\Runbook\Migration\Version0005Date20260501000000;
use OCA\Runbook\Migration\Version0006Date20260601000000;
use OCA\Runbook\Migration\Version0007Date20260701000000;
use OCA\Runbook\Migration\Version0008Date20260801000000;
use OCA\Runbook\Tests\Support\FakeSchemaWrapper;
use OCA\Runbook\Tests\Support\FakeTable;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Structural tests for the migrations, exercised through the public
 * Nextcloud schema wrapper.
 *
 * The in-memory schema double verifies the tables, columns, indexes, foreign
 * keys and guard behavior without a real database. The remaining limitation is
 * that actual SQL execution against MySQL/PostgreSQL/SQLite is not covered in
 * this environment.
 */
class MigrationSchemaTest extends TestCase {
	/** @var list<class-string<SimpleMigrationStep>> */
	private const MIGRATIONS = [
		Version0001Date20260101000000::class,
		Version0002Date20260201000000::class,
		Version0003Date20260301000000::class,
		Version0004Date20260401000000::class,
		Version0005Date20260501000000::class,
		Version0006Date20260601000000::class,
		Version0007Date20260701000000::class,
		Version0008Date20260801000000::class,
	];

	/** @var IOutput&MockObject */
	private IOutput $output;

	protected function setUp(): void {
		$this->output = $this->createMock(IOutput::class);
	}

	private function applyAll(ISchemaWrapper $schema): void {
		foreach (self::MIGRATIONS as $migration) {
			$instance = new $migration();
			$instance->changeSchema($this->output, static fn (): ISchemaWrapper => $schema, []);
		}
	}

	/**
	 * @return list<FakeTable>
	 */
	private function recordedTables(ISchemaWrapper $schema): array {
		$tables = [];
		foreach ($schema->getTables() as $table) {
			if ($table instanceof FakeTable) {
				$tables[] = $table;
			}
		}

		return $tables;
	}

	private function table(ISchemaWrapper $schema, string $name): FakeTable {
		/** @var FakeTable $table */
		$table = $schema->getTable($name);

		return $table;
	}

	/**
	 * @return list<string>
	 */
	private function allNames(ISchemaWrapper $schema): array {
		$names = [];
		foreach ($schema->getTables() as $table) {
			if (!$table instanceof FakeTable) {
				continue;
			}
			$names[] = $table->getName();
			foreach ($table->recordedColumns() as $column) {
				$names[] = $table->getName() . '.' . $column['type'];
			}
			foreach (array_keys($table->recordedIndexes()) as $index) {
				$names[] = $index;
			}
			foreach (array_keys($table->recordedForeignKeys()) as $foreign) {
				$names[] = $foreign;
			}
		}

		return $names;
	}

	public function testFreshSchemaCreatesAllTables(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		foreach ([
			'runbook_templates',
			'runbook_template_sections',
			'runbook_template_steps',
			'runbook_template_acl',
			'runbook_runs',
			'runbook_run_sections',
			'runbook_run_steps',
			'runbook_run_acl',
			'runbook_comments',
			'runbook_comment_mentions',
			'runbook_attachments',
			'runbook_activity',
			'runbook_notification_deliveries',
		] as $table) {
			self::assertTrue($schema->hasTable($table), 'Missing table ' . $table);
		}
	}

	public function testTemplatesColumnsAndNullableDefaults(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$templates = $this->table($schema, 'runbook_templates');
		foreach (['id', 'uuid', 'title', 'description', 'version', 'status', 'owner', 'created_at', 'updated_at', 'published_at', 'archived_at'] as $column) {
			self::assertTrue($templates->hasColumn($column), 'Missing column ' . $column);
		}
		self::assertSame(['id'], $templates->recordedPrimaryKey());
		$columns = $templates->recordedColumns();
		self::assertFalse($columns['published_at']['options']['notnull']);
		self::assertNull($columns['published_at']['options']['default']);
		self::assertFalse($columns['archived_at']['options']['notnull']);
	}

	public function testRunTemplateForeignKeyPreservesHistory(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$foreignKeys = $this->table($schema, 'runbook_runs')->recordedForeignKeys();
		self::assertArrayHasKey('runbook_runs_template_fk', $foreignKeys);
		self::assertSame('runbook_templates', $foreignKeys['runbook_runs_template_fk']['foreign']);
		self::assertSame('SET NULL', $foreignKeys['runbook_runs_template_fk']['options']['onDelete']);
		self::assertFalse($this->table($schema, 'runbook_runs')->recordedColumns()['template_id']['options']['notnull']);
	}

	public function testCascadeForeignKeysAreIntentional(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$expectations = [
			['runbook_template_sections', 'runbook_templates'],
			['runbook_template_steps', 'runbook_template_sections'],
			['runbook_run_sections', 'runbook_runs'],
			['runbook_run_steps', 'runbook_run_sections'],
			['runbook_comments', 'runbook_runs'],
			['runbook_comment_mentions', 'runbook_comments'],
			['runbook_attachments', 'runbook_runs'],
			['runbook_activity', 'runbook_runs'],
			['runbook_notification_deliveries', 'runbook_runs'],
		];

		foreach ($expectations as [$local, $foreign]) {
			$found = false;
			foreach ($this->table($schema, $local)->recordedForeignKeys() as $fk) {
				if ($fk['foreign'] === $foreign) {
					self::assertSame('CASCADE', $fk['options']['onDelete']);
					$found = true;
				}
			}
			self::assertTrue($found, sprintf('Missing CASCADE foreign key %s -> %s', $local, $foreign));
		}
	}

	public function testRunStepsAssigneeColumnsAndIndex(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$steps = $this->table($schema, 'runbook_run_steps');
		self::assertTrue($steps->hasColumn('assignee_type'));
		self::assertTrue($steps->hasColumn('assignee_id'));
		self::assertTrue($steps->hasColumn('due_at'));
		self::assertFalse($steps->recordedColumns()['assignee_type']['options']['notnull']);
		self::assertFalse($steps->recordedColumns()['due_at']['options']['notnull']);
		self::assertTrue($steps->hasIndex('runbook_run_steps_assignee_idx'));
	}

	public function testNotificationLedgerDefaultsAndUniqueIndex(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$ledger = $this->table($schema, 'runbook_notification_deliveries');
		$columns = $ledger->recordedColumns();
		self::assertSame('pending', $columns['status']['options']['default']);
		self::assertSame(0, $columns['attempts']['options']['default']);
		self::assertSame(0, $columns['updated_at']['options']['default']);
		self::assertArrayHasKey('runbook_notification_deliveries_uniq', $ledger->recordedUniqueIndexes());
		self::assertSame(['dedupe_key'], $ledger->recordedUniqueIndexes()['runbook_notification_deliveries_uniq']);
	}

	public function testUniquePrincipalIndexes(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$templateAcl = $this->table($schema, 'runbook_template_acl');
		self::assertArrayHasKey('runbook_acl_principal_uniq', $templateAcl->recordedUniqueIndexes());
		self::assertSame(
			['template_id', 'principal_type', 'principal_id'],
			$templateAcl->recordedUniqueIndexes()['runbook_acl_principal_uniq'],
		);

		$runAcl = $this->table($schema, 'runbook_run_acl');
		self::assertArrayHasKey('runbook_run_acl_principal_uniq', $runAcl->recordedUniqueIndexes());
	}

	public function testSectionNotesColumnsAreNullable(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		foreach (['runbook_template_sections', 'runbook_run_sections'] as $tableName) {
			$table = $this->table($schema, $tableName);
			self::assertTrue($table->hasColumn('notes'), 'Missing notes column on ' . $tableName);
			$column = $table->recordedColumns()['notes'];
			self::assertFalse($column['options']['notnull']);
			self::assertNull($column['options']['default']);
		}
	}

	public function testSectionFlowColumnsAreNullable(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		foreach (['runbook_template_sections', 'runbook_run_sections'] as $tableName) {
			$table = $this->table($schema, $tableName);
			foreach (['depends_on', 'condition_config'] as $column) {
				self::assertTrue($table->hasColumn($column), 'Missing ' . $column . ' on ' . $tableName);
				self::assertFalse($table->recordedColumns()[$column]['options']['notnull']);
				self::assertNull($table->recordedColumns()[$column]['options']['default']);
			}
		}
	}

	public function testGuardedMigrationsAreIdempotent(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$tablesBefore = count($this->recordedTables($schema));
		$columnsBefore = array_map(static fn (FakeTable $table): int => count($table->recordedColumns()), $this->recordedTables($schema));
		$indexesBefore = array_map(static fn (FakeTable $table): int => count($table->recordedIndexes()), $this->recordedTables($schema));

		// Re-running must not add columns, indexes or tables.
		$this->applyAll($schema);

		self::assertCount($tablesBefore, $this->recordedTables($schema));
		self::assertSame($columnsBefore, array_map(static fn (FakeTable $table): int => count($table->recordedColumns()), $this->recordedTables($schema)));
		self::assertSame($indexesBefore, array_map(static fn (FakeTable $table): int => count($table->recordedIndexes()), $this->recordedTables($schema)));
	}

	public function testGuardedMigrationsReturnNullWhenAlreadyApplied(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		$alreadyGuarded = [
			new Version0002Date20260201000000(),
			new Version0006Date20260601000000(),
			new Version0007Date20260701000000(),
			new Version0008Date20260801000000(),
		];
		foreach ($alreadyGuarded as $migration) {
			self::assertNull($migration->changeSchema($this->output, static fn (): ISchemaWrapper => $schema, []));
		}
	}

	public function testIdentifierLengthsArePortable(): void {
		$schema = new FakeSchemaWrapper();
		$this->applyAll($schema);

		foreach ($this->allNames($schema) as $name) {
			// Oracle is not supported; the portable limit for the remaining
			// databases is 63 characters.
			self::assertLessThanOrEqual(63, strlen($name), 'Identifier too long: ' . $name);
		}
	}

	public function testMigrationVersionsAreOrdered(): void {
		self::assertSame(self::MIGRATIONS, [
			Version0001Date20260101000000::class,
			Version0002Date20260201000000::class,
			Version0003Date20260301000000::class,
			Version0004Date20260401000000::class,
			Version0005Date20260501000000::class,
			Version0006Date20260601000000::class,
			Version0007Date20260701000000::class,
			Version0008Date20260801000000::class,
		]);
	}
}
