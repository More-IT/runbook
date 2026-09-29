<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\RunService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for the run lifecycle.
 *
 * @psalm-import-type RunbookRunData from ResponseDefinitions
 * @psalm-import-type RunbookRunSectionData from ResponseDefinitions
 * @psalm-import-type RunbookRunStepData from ResponseDefinitions
 */
class RunController extends ApiController {
	private const FIELDS = ['title', 'description', 'dueAt', 'destinationPath'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunService $runService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List runs.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{runs: list<array{run: RunbookRunData, progress: array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}}>}, array{}>
	 *
	 * 200: Runs returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/runs')]
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
	 * Create a run.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{run: RunbookRunData}, array{}>
	 *
	 * 201: Run created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/{templateId}/runs')]
	public function create(): JSONResponse {
		$run = $this->runService->startRun($this->requireId('templateId'), $this->body(self::FIELDS));

		return new JSONResponse(['run' => $run->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Show a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunbookRunData, sections: list<array{section: RunbookRunSectionData, steps: list<RunbookRunStepData>, state: string, blockedBy: list<string>, reason: list<array<string, mixed>>}>, progress: array{total: int, completed: int, skipped: int, pending: int, percentage: int, canComplete: bool}, permissions: array{uid: string, role: string|null, canManage: bool, canModify: bool, canCancel: bool, canReopen: bool, canManageAssignments: bool, canComment: bool, canDelete: bool, executableStepIds: list<int>}, evidenceDegraded: bool, managedFolderState: string}, array{}>
	 *
	 * 200: Run returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/runs/{id}')]
	public function show(): JSONResponse {
		$detail = $this->runService->getRunDetail($this->requireId('id'));

		$sections = [];
		foreach ($detail['sections'] as $entry) {
			$steps = array_map(
				static fn (RunStep $step): array => $step->toArray(),
				$entry['steps'],
			);
			$sections[] = [
				'section' => $entry['section']->toArray(),
				'steps' => $steps,
				'state' => $entry['state'],
				'blockedBy' => $entry['blockedBy'],
				'reason' => $entry['reason'],
			];
		}

		return new JSONResponse([
			'run' => $detail['run']->toArray(),
			'sections' => $sections,
			'progress' => $detail['progress'],
			'permissions' => $detail['permissions'],
			'evidenceDegraded' => $detail['evidenceDegraded'],
			'managedFolderState' => $detail['managedFolderState'],
		]);
	}

	/**
	 * Complete a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunbookRunData}, array{}>
	 *
	 * 200: Run completed
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/runs/{id}/complete')]
	public function complete(): JSONResponse {
		$run = $this->runService->completeRun($this->requireId('id'));

		return new JSONResponse(['run' => $run->toArray()]);
	}

	/**
	 * Cancel a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunbookRunData}, array{}>
	 *
	 * 200: Run cancelled
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/runs/{id}/cancel')]
	public function cancel(): JSONResponse {
		$run = $this->runService->cancelRun($this->requireId('id'));

		return new JSONResponse(['run' => $run->toArray()]);
	}

	/**
	 * Reopen a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{run: RunbookRunData}, array{}>
	 *
	 * 200: Run reopened
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/runs/{id}/reopen')]
	public function reopen(): JSONResponse {
		$run = $this->runService->reopenRun($this->requireId('id'));

		return new JSONResponse(['run' => $run->toArray()]);
	}

	/**
	 * Delete a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Run deleted
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/runs/{id}')]
	public function destroy(): JSONResponse {
		$this->runService->deleteRun($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}
}
