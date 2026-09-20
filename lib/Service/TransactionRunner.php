<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCP\IDBConnection;

/**
 * Thin wrapper around a database transaction.
 *
 * Keeping transactions behind this helper lets the services stay unit testable
 * without loading the Nextcloud database connection and its Doctrine types.
 */
class TransactionRunner {
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 */
	public function run(callable $operation): mixed {
		$this->db->beginTransaction();
		try {
			$result = $operation();
			$this->db->commit();

			return $result;
		} catch (\Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}
	}
}
