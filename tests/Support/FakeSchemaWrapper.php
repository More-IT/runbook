<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Support;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use OCP\DB\ISchemaWrapper;

/**
 * In-memory schema wrapper used by the migration schema tests.
 *
 * It implements the public Nextcloud {@see ISchemaWrapper} using recording
 * tables. This verifies the migrations' use of the public schema API, their
 * guards and their resulting structure without requiring a real database.
 */
class FakeSchemaWrapper implements ISchemaWrapper {
	/** @var array<string, FakeTable> */
	private array $tables = [];

	public function getTable($tableName) {
		if (!isset($this->tables[$tableName])) {
			throw new \RuntimeException('Table not found: ' . (string)$tableName);
		}

		return $this->tables[$tableName];
	}

	public function hasTable($tableName) {
		return isset($this->tables[$tableName]);
	}

	public function createTable($tableName) {
		$table = new FakeTable((string)$tableName);
		$this->tables[(string)$tableName] = $table;

		return $table;
	}

	public function dropTable($tableName) {
		unset($this->tables[(string)$tableName]);

		return new Schema();
	}

	/**
	 * @return list<FakeTable>
	 */
	public function getTables() {
		return array_values($this->tables);
	}

	/**
	 * @return list<string>
	 */
	public function getTableNames() {
		return array_keys($this->tables);
	}

	/**
	 * @return list<string>
	 */
	public function getTableNamesWithoutPrefix() {
		return array_keys($this->tables);
	}

	public function getDatabasePlatform() {
		return new AbstractPlatform();
	}

	public function dropAutoincrementColumn(string $table, string $column): void {
	}
}
