<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\WorkController;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\WorkService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WorkControllerTest extends TestCase {
	/** @var WorkService&MockObject */
	private WorkService $workService;

	protected function setUp(): void {
		$this->workService = $this->createMock(WorkService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): WorkController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new WorkController('runbook', $request, $this->workService);
	}

	private function makeRun(): Run {
		$run = new Run();
		$run->setId(1);
		$run->setUuid('00000000-0000-4000-8000-000000000000');
		$run->setTemplateId(1);
		$run->setTemplateVersion(1);
		$run->setTitle('Run');
		$run->setDescription('');
		$run->setOwner('alice');
		$run->setStatus(RunStatus::Active->value);
		$run->setCreatedAt(1000);
		$run->setStartedAt(1000);
		$run->setDueAt(2000);
		$run->setUpdatedAt(1000);

		return $run;
	}

	/**
	 * @return array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool}
	 */
	private function item(): array {
		$section = new RunSection();
		$section->setId(5);
		$section->setRunId(1);
		$section->setTitle('Prep');
		$section->setDescription('');
		$section->setPosition(0);

		$step = new RunStep();
		$step->setId(9);
		$step->setRunSectionId(5);
		$step->setUuid('00000000-0000-4000-8000-000000000000');
		$step->setTitle('Check');
		$step->setDescription('');
		$step->setType('CHECK');
		$step->setRequired(true);
		$step->setPosition(0);
		$step->setConfigArray([]);
		$step->setStatus(RunStepStatus::Pending->value);
		$step->setAssigneeType(PrincipalType::User->value);
		$step->setAssigneeId('bob');
		$step->setDueAt(1500);

		return ['run' => $this->makeRun(), 'section' => $section, 'step' => $step, 'overdue' => false, 'dueToday' => true];
	}

	public function testMyWorkReturnsWorkItems(): void {
		$this->workService->expects(self::once())
			->method('myWork')
			->with('today')
			->willReturn([$this->item()]);

		$response = $this->controller(['filter' => 'today'])->myWork();

		self::assertSame(200, $response->getStatus());
		$work = $response->getData()['work'];
		self::assertSame(1, $work[0]['runId']);
		self::assertSame(9, $work[0]['stepId']);
		self::assertSame('Prep', $work[0]['sectionTitle']);
		self::assertTrue($work[0]['required']);
	}

	public function testOverviewReturnsCounters(): void {
		$this->workService->expects(self::once())
			->method('overview')
			->willReturn([
				'activeRuns' => 2,
				'assignedActiveSteps' => 3,
				'overdue' => 1,
				'completedStepsThisMonth' => 4,
				'completedRunsThisMonth' => 1,
				'assignedWork' => [$this->item()],
				'recentRuns' => [$this->makeRun()],
			]);

		$response = $this->controller([])->overview();

		self::assertSame(2, $response->getData()['activeRuns']);
		self::assertSame(1, $response->getData()['overdue']);
		self::assertCount(1, $response->getData()['recentRuns']);
	}
}
