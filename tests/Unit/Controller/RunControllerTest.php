<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\RunController;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\RunService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RunControllerTest extends TestCase {
	/** @var RunService&MockObject */
	private RunService $runService;

	protected function setUp(): void {
		$this->runService = $this->createMock(RunService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): RunController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new RunController('runbook', $request, $this->runService);
	}

	private function makeRun(int $id = 1): Run {
		$run = new Run();
		$run->setId($id);
		$run->setUuid('00000000-0000-4000-8000-000000000000');
		$run->setTemplateId(3);
		$run->setTemplateVersion(2);
		$run->setTitle('Run');
		$run->setDescription('');
		$run->setOwner('alice');
		$run->setStatus(RunStatus::Active->value);
		$run->setCreatedAt(1000);
		$run->setStartedAt(1000);
		$run->setUpdatedAt(1000);

		return $run;
	}

	private function section(int $id, int $runId): RunSection {
		$section = new RunSection();
		$section->setId($id);
		$section->setRunId($runId);
		$section->setTitle('Section');
		$section->setDescription('');
		$section->setPosition(0);

		return $section;
	}

	private function step(int $id, int $sectionId): RunStep {
		$step = new RunStep();
		$step->setId($id);
		$step->setRunSectionId($sectionId);
		$step->setUuid('00000000-0000-4000-8000-000000000000');
		$step->setTitle('Step');
		$step->setDescription('');
		$step->setType('CHECK');
		$step->setRequired(false);
		$step->setPosition(0);
		$step->setConfigArray([]);
		$step->setStatus(RunStepStatus::Pending->value);

		return $step;
	}

	public function testIndexReturnsRunsWithProgress(): void {
		$progress = ['total' => 1, 'completed' => 0, 'skipped' => 0, 'pending' => 1, 'percentage' => 0, 'canComplete' => false];
		$this->runService->expects(self::once())
			->method('listRuns')
			->willReturn([['run' => $this->makeRun(), 'progress' => $progress]]);

		$response = $this->controller([])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame('alice', $response->getData()['runs'][0]['run']['owner']);
		self::assertSame($progress, $response->getData()['runs'][0]['progress']);
	}

	public function testCreateReturnsCreatedRun(): void {
		$this->runService->expects(self::once())
			->method('startRun')
			->with(3, ['title' => 'Release'])
			->willReturn($this->makeRun());

		$response = $this->controller(['templateId' => '3', 'title' => 'Release'])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('Run', $response->getData()['run']['title']);
	}

	public function testCreateForwardsTheRunTimeDestinationPath(): void {
		$this->runService->expects(self::once())
			->method('startRun')
			->with(3, ['title' => 'Release', 'destinationPath' => '/Shared/Reports'])
			->willReturn($this->makeRun());

		$response = $this->controller([
			'templateId' => '3',
			'title' => 'Release',
			'destinationPath' => '/Shared/Reports',
		])->create();

		self::assertSame(201, $response->getStatus());
	}

	public function testShowReturnsDetail(): void {
		$run = $this->makeRun();
		$section = $this->section(10, $run->getId());
		$step = $this->step(20, $section->getId());
		$progress = ['total' => 1, 'completed' => 0, 'skipped' => 0, 'pending' => 1, 'percentage' => 0, 'canComplete' => false];
		$permissions = [
			'role' => 'OWNER',
			'canManage' => true,
			'canModify' => true,
			'canCancel' => true,
			'canReopen' => false,
			'canManageAssignments' => true,
			'executableStepIds' => [20],
		];

		$this->runService->expects(self::once())
			->method('getRunDetail')
			->with(1)
			->willReturn([
				'run' => $run,
				'sections' => [[
					'section' => $section,
					'steps' => [$step],
					'state' => 'available',
					'blockedBy' => [],
					'reason' => [],
				]],
				'progress' => $progress,
				'permissions' => $permissions,
				'evidenceDegraded' => false,
				'managedFolderState' => 'available',
			]);

		$response = $this->controller(['id' => '1'])->show();

		self::assertSame(200, $response->getStatus());
		self::assertSame($progress, $response->getData()['progress']);
		self::assertSame('Step', $response->getData()['sections'][0]['steps'][0]['title']);
		self::assertSame([], $response->getData()['sections'][0]['reason']);
		self::assertFalse($response->getData()['evidenceDegraded']);
		self::assertSame('available', $response->getData()['managedFolderState']);
	}

	public function testCompleteReturnsCompletedRun(): void {
		$completed = $this->makeRun();
		$completed->setStatus(RunStatus::Completed->value);

		$this->runService->expects(self::once())->method('completeRun')->with(1)->willReturn($completed);

		$response = $this->controller(['id' => '1'])->complete();

		self::assertSame(RunStatus::Completed->value, $response->getData()['run']['status']);
	}

	public function testCancelReturnsCancelledRun(): void {
		$cancelled = $this->makeRun();
		$cancelled->setStatus(RunStatus::Cancelled->value);

		$this->runService->expects(self::once())->method('cancelRun')->with(1)->willReturn($cancelled);

		$response = $this->controller(['id' => '1'])->cancel();

		self::assertSame(RunStatus::Cancelled->value, $response->getData()['run']['status']);
	}

	public function testReopenReturnsActiveRun(): void {
		$this->runService->expects(self::once())->method('reopenRun')->with(1)->willReturn($this->makeRun());

		$response = $this->controller(['id' => '1'])->reopen();

		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(RunStatus::Active->value, $response->getData()['run']['status']);
	}

	public function testDestroyDeletesTheRun(): void {
		$this->runService->expects(self::once())->method('deleteRun')->with(1);

		$response = $this->controller(['id' => '1'])->destroy();

		self::assertSame(200, $response->getStatus());
		self::assertTrue($response->getData()['success']);
	}

	public function testDestroyLetsABlockedDeletionReachTheErrorMiddleware(): void {
		// The controller must not swallow the fail-closed reason: the error
		// middleware maps `run_delete_blocked` to a 409 JSON body so the client
		// can show the specific message instead of a generic failure.
		$this->runService->expects(self::once())->method('deleteRun')->with(1)
			->willThrowException(new ConflictException('run_delete_blocked'));

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('run_delete_blocked');

		$this->controller(['id' => '1'])->destroy();
	}
}
