<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal PHPStan stubs for the Doctrine DBAL APIs referenced by Nextcloud's
 * schema and query-builder interfaces. Nextcloud ships doctrine/dbal at runtime,
 * but it is not a dependency of this app, so only the symbols our migrations and
 * tests need are declared here to keep static analysis and unit tests dependency
 * free.
 */

declare(strict_types=1);

namespace Doctrine\DBAL\Schema;

class Table {
	/**
	 * @param array<string, mixed> $options
	 */
	public function addColumn(string $name, string $type, array $options = []): self {
		return $this;
	}

	public function hasColumn(string $name): bool {
		return true;
	}

	public function hasIndex(string $name): bool {
		return true;
	}

	/**
	 * @param list<string> $columnNames
	 * @param array<string, mixed> $options
	 */
	public function addIndex(array $columnNames, ?string $indexName = null, array $options = []): self {
		return $this;
	}

	/**
	 * @param list<string> $columnNames
	 * @param array<string, mixed> $options
	 */
	public function addUniqueIndex(array $columnNames, ?string $indexName = null, array $options = []): self {
		return $this;
	}

	/**
	 * @param Table|string $foreignTable
	 * @param list<string> $localColumnNames
	 * @param list<string> $foreignColumnNames
	 * @param array<string, mixed> $options
	 */
	public function addForeignKeyConstraint($foreignTable, array $localColumnNames, array $foreignColumnNames, array $options = [], ?string $constraintName = null): self {
		return $this;
	}

	/**
	 * @param list<string> $columnNames
	 */
	public function setPrimaryKey(array $columnNames, ?string $indexName = null): self {
		return $this;
	}
}

namespace Doctrine\DBAL;

/**
 * Parameter types referenced by
 * {@see \OCP\DB\QueryBuilder\IQueryBuilder} constants; the real class is
 * provided by Nextcloud (doctrine/dbal) at runtime.
 */
class ParameterType {
	public const NULL = 0;
	public const INTEGER = 1;
	public const STRING = 2;
	public const LARGE_OBJECT = 3;
}

/**
 * Array parameter types referenced by
 * {@see \OCP\DB\QueryBuilder\IQueryBuilder} constants.
 */
class ArrayParameterType {
	public const INTEGER = 101;
	public const STRING = 102;
}

namespace Doctrine\DBAL\Types;

/**
 * Type names referenced by {@see \OCP\DB\QueryBuilder\IQueryBuilder} constants.
 */
class Types {
	public const BOOLEAN = 'boolean';
	public const DATE_MUTABLE = 'date';
	public const DATETIME_MUTABLE = 'datetime';
	public const DATETIMETZ_MUTABLE = 'datetimetz';
	public const TIME_MUTABLE = 'time';
	public const DATE_IMMUTABLE = 'date_immutable';
	public const DATETIME_IMMUTABLE = 'datetime_immutable';
	public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
}
