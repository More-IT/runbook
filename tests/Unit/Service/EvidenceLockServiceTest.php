<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\EvidenceLockService;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/**
 * In-memory locking provider that models Nextcloud's rejection semantics: a
 * second exclusive acquisition of a held path throws LockedException instead of
 * waiting. This lets the tests deterministically prove mutual exclusion without
 * a second process.
 */
final class FakeLockingProvider implements ILockingProvider {
	/** @var array<string, int> */
	public array $held = [];

	public function isLocked(string $path, int $type): bool {
		return isset($this->held[$path]);
	}

	public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
		if (isset($this->held[$path])) {
			throw new LockedException($path);
		}
		$this->held[$path] = $type;
	}

	public function releaseLock(string $path, int $type): void {
		unset($this->held[$path]);
	}

	public function changeLock(string $path, int $targetType): void {
		$this->held[$path] = $targetType;
	}

	public function releaseAll(): void {
		$this->held = [];
	}
}

/**
 * Tests for the per-step evidence lock shared by FILE-step completion and
 * evidence deletion.
 */
class EvidenceLockServiceTest extends TestCase {
	/**
	 * @return array{FakeLockingProvider, EvidenceLockService}
	 */
	private function service(int $maxAttempts = 2): array {
		$provider = new FakeLockingProvider();

		return [$provider, new EvidenceLockService($provider, $maxAttempts, 0)];
	}

	public function testOperationsOnTheSameStepAreMutuallyExclusive(): void {
		[$provider, $service] = $this->service();
		$nestedEntered = false;

		try {
			$service->synchronized(7, 11, function () use ($service, &$nestedEntered): void {
				// A concurrent request for the same step must not enter while
				// the first operation holds the lock.
				$service->synchronized(7, 11, function () use (&$nestedEntered): void {
					$nestedEntered = true;
				});
			});
			self::fail('The nested concurrent operation should not have been allowed to enter');
		} catch (ConflictException $exception) {
			self::assertSame('evidence_locked', $exception->getReason());
		}

		self::assertFalse($nestedEntered, 'the concurrent operation never entered the critical section');
		self::assertSame([], $provider->held, 'the lock is released even when the operation fails');
	}

	public function testDifferentStepsAreNotBlockedByEachOther(): void {
		[, $service] = $this->service();

		$inner = null;
		$service->synchronized(1, 1, function () use ($service, &$inner): void {
			$inner = $service->synchronized(1, 2, static fn (): string => 'ok');
		});

		self::assertSame('ok', $inner);
	}

	public function testSustainedContentionFailsWithAConflict(): void {
		[$provider, $service] = $this->service();
		$provider->held[$service->path(9, 9)] = ILockingProvider::LOCK_EXCLUSIVE;

		$this->expectException(ConflictException::class);
		$service->synchronized(9, 9, static fn (): string => 'never');
	}
}
