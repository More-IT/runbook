<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\AppInfo;

use OCA\Runbook\AppInfo\Application;
use OCA\Runbook\BackgroundJob\DueStepNotificationJob;
use OCA\Runbook\BackgroundJob\FilesCleanupRetryJob;
use OCA\Runbook\BackgroundJob\LegacyMigrationJob;
use OCA\Runbook\Dashboard\Widget;
use OCA\Runbook\Middleware\ExceptionMiddleware;
use OCA\Runbook\Notification\Notifier;
use OCA\Runbook\Search\Provider;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {
	private function application(): Application {
		/** @var Application $application */
		$application = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();

		return $application;
	}

	public function testRegistersIntegrations(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects(self::once())->method('registerMiddleware')->with(ExceptionMiddleware::class);
		$context->expects(self::once())->method('registerNotifierService')->with(Notifier::class);
		$context->expects(self::once())->method('registerDashboardWidget')->with(Widget::class);
		$context->expects(self::once())->method('registerSearchProvider')->with(Provider::class);

		$this->application()->register($context);
	}

	public function testBootRegistersBackgroundJobs(): void {
		$registered = [];
		$jobList = $this->createMock(IJobList::class);
		$jobList->method('add')->willReturnCallback(function (string $job) use (&$registered): void {
			$registered[] = $job;
		});

		$context = $this->createMock(IBootContext::class);
		$context->method('injectFn')->willReturnCallback(
			static fn (callable $callback): mixed => $callback($jobList),
		);

		$this->application()->boot($context);

		self::assertContains(DueStepNotificationJob::class, $registered);
		self::assertContains(FilesCleanupRetryJob::class, $registered);
		self::assertContains(LegacyMigrationJob::class, $registered);
	}
}
