<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Service\RunService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoint for editing a run section's notes.
 *
 * @phpstan-import-type RunSectionData from RunSection
 */
class RunSectionController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunService $runService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{section: RunSectionData}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$section = $this->runService->updateSectionNotes(
			$this->requireId('id'),
			$this->body(['notes']),
		);

		return new JSONResponse(['section' => $section->toArray()]);
	}
}
