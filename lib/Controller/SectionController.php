<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template sections.
 *
 * @phpstan-import-type SectionData from TemplateSection
 */
class SectionController extends ApiController {
	private const FIELDS = ['title', 'description'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TemplateService $templateService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_CREATED, array{section: SectionData}, array{}>
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$section = $this->templateService->createSection(
			$this->requireId('templateId'),
			$this->body(self::FIELDS),
		);

		return new JSONResponse(['section' => $section->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{section: SectionData}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$section = $this->templateService->updateSection($this->requireId('id'), $this->body(self::FIELDS));

		return new JSONResponse(['section' => $section->toArray()]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		$this->templateService->deleteSection($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{sections: list<SectionData>}, array{}>
	 */
	#[NoAdminRequired]
	public function reorder(): JSONResponse {
		$sections = $this->templateService->reorderSection(
			$this->requireId('id'),
			$this->requireInt('position'),
		);

		return new JSONResponse([
			'sections' => array_map(
				static fn (TemplateSection $section): array => $section->toArray(),
				$sections,
			),
		]);
	}
}
