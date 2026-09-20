<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\RunStepController;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\RunStepService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunStepControllerTest extends TestCase {
	/** @var RunStepService&MockObject */
	private RunStepService $runStepService;

	protected function setUp(): void {
		$this->runStepService = $this->createMock(RunStepService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): RunStepController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new RunStepController('runbook', $request, $this->runStepService);
	}

	private function step(string $status = RunStepStatus::Pending->value): RunStep {
		$step = new RunStep();
		$step->setId(1);
		$step->setRunSectionId(5);
		$step->setUuid('00000000-0000-4000-8000-000000000000');
		$step->setTitle('Step');
		$step->setDescription('');
		$step->setType('CHECK');
		$step->setRequired(false);
		$step->setPosition(0);
		$step->setConfigArray([]);
		$step->setStatus($status);

		return $step;
	}

	public function testStartReturnsInProgressStep(): void {
		$this->runStepService->expects(self::once())->method('start')->with(1)
			->willReturn($this->step(RunStepStatus::InProgress->value));

		$response = $this->controller(['id' => '1'])->start();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(RunStepStatus::InProgress->value, $response->getData()['step']['status']);
	}

	public function testCompletePassesResponse(): void {
		$this->runStepService->expects(self::once())
			->method('complete')
			->with(1, ['response' => true])
			->willReturn($this->step(RunStepStatus::Completed->value));

		$response = $this->controller(['id' => '1', 'response' => true])->complete();

		self::assertSame(RunStepStatus::Completed->value, $response->getData()['step']['status']);
	}

	public function testSkipPassesReason(): void {
		$this->runStepService->expects(self::once())
			->method('skip')
			->with(1, ['reason' => 'Not applicable'])
			->willReturn($this->step(RunStepStatus::Skipped->value));

		$response = $this->controller(['id' => '1', 'reason' => 'Not applicable'])->skip();

		self::assertSame(RunStepStatus::Skipped->value, $response->getData()['step']['status']);
	}

	public function testUpdatePassesResponse(): void {
		$this->runStepService->expects(self::once())
			->method('update')
			->with(1, ['response' => 'draft'])
			->willReturn($this->step());

		$response = $this->controller(['id' => '1', 'response' => 'draft'])->update();

		self::assertSame(RunStepStatus::Pending->value, $response->getData()['step']['status']);
	}

	public function testUpdatePassesAssignmentFields(): void {
		$this->runStepService->expects(self::once())
			->method('update')
			->with(1, ['assigneeType' => 'USER', 'assigneeId' => 'bob', 'dueAt' => 2000])
			->willReturn($this->step());

		$response = $this->controller([
			'id' => '1',
			'assigneeType' => 'USER',
			'assigneeId' => 'bob',
			'dueAt' => 2000,
		])->update();

		self::assertInstanceOf(JSONResponse::class, $response);
	}

	public function testReopenReturnsPendingStep(): void {
		$this->runStepService->expects(self::once())->method('reopen')->with(1)->willReturn($this->step());

		$response = $this->controller(['id' => '1'])->reopen();

		self::assertSame(RunStepStatus::Pending->value, $response->getData()['step']['status']);
	}
}
