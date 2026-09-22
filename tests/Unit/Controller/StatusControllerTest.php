<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\StatusController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class StatusControllerTest extends TestCase {
	public function testStatusReportsHealthyFoundation(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager
			->expects(self::once())
			->method('getAppVersion')
			->with('runbook')
			->willReturn('0.4.0');

		$controller = new StatusController('runbook', $this->createMock(IRequest::class), $appManager);

		$response = $controller->status();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(200, $response->getStatus());
		self::assertSame([
			'status' => 'ok',
			'app' => 'runbook',
			'version' => '0.4.0',
		], $response->getData());
	}
}
