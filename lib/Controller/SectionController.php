<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\TemplateService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template sections.
 *
 * @psalm-import-type RunbookSectionData from ResponseDefinitions
 */
class SectionController extends ApiController {
	private const FIELDS = ['title', 'description', 'notes', 'dependsOn', 'condition', 'conditions'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TemplateService $templateService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Create a template section.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{section: RunbookSectionData}, array{}>
	 *
	 * 201: Section created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/templates/{templateId}/sections')]
	public function create(): JSONResponse {
		$section = $this->templateService->createSection(
			$this->requireId('templateId'),
			$this->body(self::FIELDS),
		);

		return new JSONResponse(['section' => $section->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Update a template section.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{section: RunbookSectionData}, array{}>
	 *
	 * 200: Section updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/sections/{id}')]
	public function update(): JSONResponse {
		$section = $this->templateService->updateSection($this->requireId('id'), $this->body(self::FIELDS));

		return new JSONResponse(['section' => $section->toArray()]);
	}

	/**
	 * Delete a template section.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Section deleted
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/sections/{id}')]
	public function destroy(): JSONResponse {
		$this->templateService->deleteSection($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * Reorder template sections.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{sections: list<RunbookSectionData>}, array{}>
	 *
	 * 200: Sections reordered
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/sections/{id}/reorder')]
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
