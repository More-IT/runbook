<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\TemplateAcl;
use OCA\Runbook\Service\AclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for template access control lists.
 *
 * @phpstan-import-type AclData from TemplateAcl
 */
class AclController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AclService $aclService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{owner: string, entries: list<AclData>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$result = $this->aclService->getAcl($this->requireId('id'));

		return new JSONResponse([
			'owner' => $result['owner'],
			'entries' => array_map(
				static fn (TemplateAcl $entry): array => $entry->toArray(),
				$result['entries'],
			),
		]);
	}

	/**
	 * Replace the complete ACL of a template.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{owner: string, entries: list<AclData>}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$entries = $this->body(['entries'])['entries'] ?? null;
		if (!is_array($entries)) {
			throw new ValidationException('invalid_acl_entries');
		}

		$result = $this->aclService->replaceAcl($this->requireId('id'), $entries);

		return new JSONResponse([
			'owner' => $result['owner'],
			'entries' => array_map(
				static fn (TemplateAcl $entry): array => $entry->toArray(),
				$result['entries'],
			),
		]);
	}
}
