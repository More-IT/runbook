<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\BackgroundJob;

use OCA\Runbook\BackgroundJob\LegacyMigrationJob;
use OCA\Runbook\Service\LegacyMigrationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The hourly job and the administrator trigger must share the same bounded
 * migration batch path (issue #55).
 */
class LegacyMigrationJobTest extends TestCase {
	/** @var LegacyMigrationService&MockObject */
	private LegacyMigrationService $migration;

	protected function setUp(): void {
		$this->migration = $this->createMock(LegacyMigrationService::class);
	}

	public function testRunInvokesABoundedBatch(): void {
		$this->migration->expects(self::once())
			->method('migrateAll')
			->with(50)
			->willReturn(['processed' => 0, 'migrated' => 0, 'blocked' => 0, 'remaining' => 0, 'busy' => false]);

		/** @var ITimeFactory&MockObject $time */
		$time = $this->createMock(ITimeFactory::class);
		$job = new class($time, $this->migration, new NullLogger()) extends LegacyMigrationJob {
			public function invoke(): void {
				$this->run(null);
			}
		};

		$job->invoke();
	}
}
