<?php

/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal PHPStan stubs for the Doctrine DBAL schema API used by Nextcloud
 * migrations. Nextcloud ships doctrine/dbal at runtime, but it is not a
 * dependency of this app, so only the methods used by our migrations are
 * declared here to keep static analysis dependency free.
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
