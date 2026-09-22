<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Serializes the conflicting evidence operations of one step.
 *
 * Completion ("does this required FILE step have evidence?") and evidence
 * deletion ("is this the last attachment of a completed required FILE step?")
 * read the attachment count and then act on it. Without a shared lock two
 * concurrent requests could both observe two attachments and both delete, or a
 * deletion could slip between the completion check and the status update,
 * leaving a completed required FILE step with no evidence.
 *
 * A database transaction alone does not serialize these read-then-write
 * sequences under the supported isolation levels, and row locking SQL is not
 * portable across MySQL/MariaDB, PostgreSQL and SQLite. Nextcloud's
 * {@see ILockingProvider} is the portable primitive: both operations take the
 * same exclusive lock keyed by run and step, so the read-then-write critical
 * section can never interleave. Contention is retried briefly and then reported
 * as a conflict rather than risking the invariant.
 */
class EvidenceLockService {
	private const MAX_ATTEMPTS = 20;
	private const RETRY_DELAY_MICROSECONDS = 25000;

	public function __construct(
		private readonly ILockingProvider $locking,
		private readonly int $maxAttempts = self::MAX_ATTEMPTS,
		private readonly int $retryDelayMicroseconds = self::RETRY_DELAY_MICROSECONDS,
	) {
	}

	/**
	 * Run an operation while holding the exclusive evidence lock of one step.
	 *
	 * @template T
	 * @param callable(): T $operation
	 * @return T
	 *
	 * @throws ConflictException When the lock cannot be acquired in time.
	 */
	public function synchronized(int $runId, int $stepId, callable $operation): mixed {
		$path = $this->path($runId, $stepId);
		$this->acquire($path);

		try {
			return $operation();
		} finally {
			$this->locking->releaseLock($path, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Shared lock key. Must be identical for completion and deletion.
	 */
	public function path(int $runId, int $stepId): string {
		return 'runbook/evidence/' . $runId . '/' . $stepId;
	}

	private function acquire(string $path): void {
		for ($attempt = 0; $attempt < $this->maxAttempts; $attempt++) {
			try {
				$this->locking->acquireLock($path, ILockingProvider::LOCK_EXCLUSIVE);

				return;
			} catch (LockedException) {
				if ($attempt < $this->maxAttempts - 1 && $this->retryDelayMicroseconds > 0) {
					usleep($this->retryDelayMicroseconds);
				}
			}
		}

		throw new ConflictException('evidence_locked');
	}
}
