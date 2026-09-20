<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\BackgroundJob;

use OCA\Runbook\BackgroundJob\DueStepNotificationJob;
use OCA\Runbook\Service\DueNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Test double exposing the protected job body.
 */
class TestableDueStepNotificationJob extends DueStepNotificationJob {
	public function execute(): void {
		$this->run(null);
	}
}

class DueStepNotificationJobTest extends TestCase {
	private function job(DueNotificationService $service): TestableDueStepNotificationJob {
		return new TestableDueStepNotificationJob(
			$this->createMock(ITimeFactory::class),
			$service,
			new NullLogger(),
		);
	}

	public function testIntervalAndProcessing(): void {
		$service = $this->createMock(DueNotificationService::class);
		$service->expects(self::once())->method('process');

		$job = $this->job($service);

		self::assertSame(3600, $job->getInterval());
		$job->execute();
	}

	public function testFailuresAreSwallowed(): void {
		$service = $this->createMock(DueNotificationService::class);
		$service->method('process')->willThrowException(new \RuntimeException('boom'));

		$this->expectNotToPerformAssertions();

		$job = $this->job($service);
		$job->execute();
	}
}
