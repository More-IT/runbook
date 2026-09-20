<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Service\ValidationException;
use OCA\Runbook\Service\WorkService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for "My Work" and the overview counters.
 *
 * @phpstan-import-type RunData from Run
 *
 * @phpstan-type WorkItemData array{
 *     runId: int,
 *     runTitle: string,
 *     runStatus: string,
 *     runDueAt: int|null,
 *     stepId: int,
 *     stepTitle: string,
 *     stepStatus: string,
 *     stepType: string,
 *     assigneeType: string|null,
 *     assigneeId: string|null,
 *     dueAt: int|null,
 *     overdue: bool,
 *     dueToday: bool,
 *     sectionTitle: string,
 *     required: bool
 * }
 */
class WorkController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WorkService $workService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{work: list<WorkItemData>}, array{}>
	 */
	#[NoAdminRequired]
	public function myWork(): JSONResponse {
		$filter = $this->request->getParam('filter', 'all');
		if (!is_string($filter)) {
			throw new ValidationException('invalid_field');
		}

		$work = array_map(
			fn (array $item): array => $this->serializeWorkItem($item),
			$this->workService->myWork($filter),
		);

		return new JSONResponse(['work' => $work]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{activeRuns: int, assignedActiveSteps: int, overdue: int, completedStepsThisMonth: int, completedRunsThisMonth: int, assignedWork: list<WorkItemData>, recentRuns: list<RunData>}, array{}>
	 */
	#[NoAdminRequired]
	public function overview(): JSONResponse {
		$overview = $this->workService->overview();

		return new JSONResponse([
			'activeRuns' => $overview['activeRuns'],
			'assignedActiveSteps' => $overview['assignedActiveSteps'],
			'overdue' => $overview['overdue'],
			'completedStepsThisMonth' => $overview['completedStepsThisMonth'],
			'completedRunsThisMonth' => $overview['completedRunsThisMonth'],
			'assignedWork' => array_map(
				fn (array $item): array => $this->serializeWorkItem($item),
				$overview['assignedWork'],
			),
			'recentRuns' => array_map(
				static fn (Run $run): array => $run->toArray(),
				$overview['recentRuns'],
			),
		]);
	}

	/**
	 * @param array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool} $item
	 * @return WorkItemData
	 */
	private function serializeWorkItem(array $item): array {
		$run = $item['run'];
		$step = $item['step'];

		return [
			'runId' => $run->getId(),
			'runTitle' => $run->getTitle(),
			'runStatus' => $run->getStatus(),
			'runDueAt' => $run->getDueAt(),
			'stepId' => $step->getId(),
			'stepTitle' => $step->getTitle(),
			'stepStatus' => $step->getStatus(),
			'stepType' => $step->getType(),
			'assigneeType' => $step->getAssigneeType(),
			'assigneeId' => $step->getAssigneeId(),
			'dueAt' => $step->getDueAt(),
			'overdue' => $item['overdue'],
			'dueToday' => $item['dueToday'],
			'sectionTitle' => $item['section']->getTitle(),
			'required' => $step->getRequired(),
		];
	}
}
