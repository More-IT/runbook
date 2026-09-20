<?php

declare(strict_types=1);

namespace OCA\Runbook\AppInfo;

use OCA\Runbook\BackgroundJob\DueStepNotificationJob;
use OCA\Runbook\Dashboard\Widget;
use OCA\Runbook\Middleware\ExceptionMiddleware;
use OCA\Runbook\Notification\Notifier;
use OCA\Runbook\Search\Provider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
	public const APP_ID = 'runbook';

	/**
	 * @param array<string, string> $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerMiddleware(ExceptionMiddleware::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerDashboardWidget(Widget::class);
		$context->registerSearchProvider(Provider::class);
	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IJobList $jobList): void {
			$jobList->add(DueStepNotificationJob::class);
		});
	}
}
