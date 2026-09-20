<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Service\RunService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for the run lifecycle.
 *
 * @phpstan-import-type RunData from Run
 * @phpstan-import-type RunSectionData from RunSection
 * @phpstan-import-type RunStepData from RunStep
 */
class RunController extends ApiController {
	private const FIELDS = ['title', 'description', 'dueAt'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunService $runService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{runs: list<array{run: RunData, progress: array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}}>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$runs = array_map(
			static fn (array $entry): array => [
				'run' => $entry['run']->toArray(),
				'progress' => $entry['progress'],
			],
			$this->runService->listRuns(),
		);

		return new JSONResponse(['runs' => $runs]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_CREATED, array{run: RunData}, array{}>
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$run = $this->runService->startRun($this->requireId('templateId'), $this->body(self::FIELDS));

		return new JSONResponse(['run' => $run->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunData, sections: list<array{section: RunSectionData, steps: list<RunStepData>}>, progress: array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}, permissions: array{role: string|null, canManage: bool, canModify: bool, canCancel: bool, canReopen: bool, canManageAssignments: bool, executableStepIds: list<int>}}, array{}>
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		$detail = $this->runService->getRunDetail($this->requireId('id'));

		$sections = [];
		foreach ($detail['sections'] as $entry) {
			$steps = array_map(
				static fn (RunStep $step): array => $step->toArray(),
				$entry['steps'],
			);
			$sections[] = ['section' => $entry['section']->toArray(), 'steps' => $steps];
		}

		return new JSONResponse([
			'run' => $detail['run']->toArray(),
			'sections' => $sections,
			'progress' => $detail['progress'],
			'permissions' => $detail['permissions'],
		]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunData}, array{}>
	 */
	#[NoAdminRequired]
	public function complete(): JSONResponse {
		$run = $this->runService->completeRun($this->requireId('id'));

		return new JSONResponse(['run' => $run->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunData}, array{}>
	 */
	#[NoAdminRequired]
	public function cancel(): JSONResponse {
		$run = $this->runService->cancelRun($this->requireId('id'));

		return new JSONResponse(['run' => $run->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunData}, array{}>
	 */
	#[NoAdminRequired]
	public function reopen(): JSONResponse {
		$run = $this->runService->reopenRun($this->requireId('id'));

		return new JSONResponse(['run' => $run->toArray()]);
	}
}
