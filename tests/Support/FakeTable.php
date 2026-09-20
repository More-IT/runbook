<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Support;

use Doctrine\DBAL\Schema\Table;

/**
 * In-memory recording table used by the migration schema tests.
 *
 * It extends the minimal Doctrine DBAL schema stub and records the columns,
 * indexes, primary key and foreign keys a migration adds, so migrations can be
 * verified without a real database.
 */
class FakeTable extends Table {
	/** @var array<string, array{type: string, options: array<string, mixed>}> */
	private array $columns = [];
	/** @var array<string, list<string>> */
	private array $indexes = [];
	/** @var array<string, list<string>> */
	private array $uniqueIndexes = [];
	/** @var array<string, array{foreign: string, local: list<string>, foreignColumns: list<string>, options: array<string, mixed>}> */
	private array $foreignKeys = [];
	/** @var list<string> */
	private array $primaryKey = [];

	public function __construct(
		private readonly string $tableName,
	) {
	}

	public function getName(): string {
		return $this->tableName;
	}

	/**
	 * @param array<string, mixed> $options
	 */
	public function addColumn(string $name, string $type, array $options = []): self {
		$this->columns[$name] = ['type' => $type, 'options' => $options];

		return $this;
	}

	public function hasColumn(string $name): bool {
		return isset($this->columns[$name]);
	}

	/**
	 * @param list<string> $columnNames
	 * @param array<string, mixed> $options
	 */
	public function addIndex(array $columnNames, ?string $indexName = null, array $options = []): self {
		$this->indexes[$indexName ?? implode('_', $columnNames)] = $columnNames;

		return $this;
	}

	/**
	 * @param list<string> $columnNames
	 * @param array<string, mixed> $options
	 */
	public function addUniqueIndex(array $columnNames, ?string $indexName = null, array $options = []): self {
		$name = $indexName ?? implode('_', $columnNames);
		$this->uniqueIndexes[$name] = $columnNames;
		$this->indexes[$name] = $columnNames;

		return $this;
	}

	public function hasIndex(string $name): bool {
		return isset($this->indexes[$name]);
	}

	/**
	 * @param Table|string $foreignTable
	 * @param list<string> $localColumnNames
	 * @param list<string> $foreignColumnNames
	 * @param array<string, mixed> $options
	 */
	public function addForeignKeyConstraint($foreignTable, array $localColumnNames, array $foreignColumnNames, array $options = [], ?string $constraintName = null): self {
		$name = $constraintName ?? implode('_', $localColumnNames);
		if ($foreignTable instanceof FakeTable) {
			$foreignName = $foreignTable->getName();
		} elseif (is_string($foreignTable)) {
			$foreignName = $foreignTable;
		} else {
			$foreignName = '';
		}
		$this->foreignKeys[$name] = [
			'foreign' => $foreignName,
			'local' => $localColumnNames,
			'foreignColumns' => $foreignColumnNames,
			'options' => $options,
		];

		return $this;
	}

	/**
	 * @param list<string> $columnNames
	 */
	public function setPrimaryKey(array $columnNames, ?string $indexName = null): self {
		$this->primaryKey = $columnNames;

		return $this;
	}

	/**
	 * @return array<string, array{type: string, options: array<string, mixed>}>
	 */
	public function recordedColumns(): array {
		return $this->columns;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function recordedIndexes(): array {
		return $this->indexes;
	}

	/**
	 * @return array<string, list<string>>
	 */
	public function recordedUniqueIndexes(): array {
		return $this->uniqueIndexes;
	}

	/**
	 * @return array<string, array{foreign: string, local: list<string>, foreignColumns: list<string>, options: array<string, mixed>}>
	 */
	public function recordedForeignKeys(): array {
		return $this->foreignKeys;
	}

	/**
	 * @return list<string>
	 */
	public function recordedPrimaryKey(): array {
		return $this->primaryKey;
	}
}
