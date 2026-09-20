<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\ActivityController;
use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Service\RunService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ActivityControllerTest extends TestCase {
	/** @var RunService&MockObject */
	private RunService $runService;

	protected function setUp(): void {
		$this->runService = $this->createMock(RunService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): ActivityController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new ActivityController('runbook', $request, $this->runService);
	}

	private function event(int $id = 1): ActivityEvent {
		$event = new ActivityEvent();
		$event->setId($id);
		$event->setRunId(1);
		$event->setStepId(null);
		$event->setActorUid('alice');
		$event->setEventType(ActivityType::RunStarted->value);
		$event->setMetadataArray(['title' => 'Release']);
		$event->setCreatedAt(1000);

		return $event;
	}

	public function testIndexReturnsActivity(): void {
		$this->runService->expects(self::once())
			->method('listActivity')
			->with(1, null, null)
			->willReturn([$this->event()]);

		$response = $this->controller(['id' => '1'])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame('run_started', $response->getData()['activity'][0]['eventType']);
		self::assertSame('Release', $response->getData()['activity'][0]['metadata']['title']);
	}

	public function testIndexPassesLimit(): void {
		$this->runService->expects(self::once())
			->method('listActivity')
			->with(1, 50, null)
			->willReturn([]);

		$response = $this->controller(['id' => '1', 'limit' => '50'])->index();

		self::assertSame([], $response->getData()['activity']);
	}

	public function testIndexPassesOrder(): void {
		$this->runService->expects(self::once())
			->method('listActivity')
			->with(1, null, 'asc')
			->willReturn([]);

		$response = $this->controller(['id' => '1', 'order' => 'asc'])->index();

		self::assertSame([], $response->getData()['activity']);
	}
}
