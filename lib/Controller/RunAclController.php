<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\RunAcl;
use OCA\Runbook\Service\RunAclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for a run's participant and viewer list.
 *
 * @phpstan-import-type RunAclData from RunAcl
 */
class RunAclController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly RunAclService $runAclService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{owner: string, entries: list<RunAclData>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$result = $this->runAclService->getAcl($this->requireId('id'));

		return new JSONResponse([
			'owner' => $result['owner'],
			'entries' => array_map(
				static fn (RunAcl $entry): array => $entry->toArray(),
				$result['entries'],
			),
		]);
	}

	/**
	 * Replace the complete ACL of a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{owner: string, entries: list<RunAclData>}, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$entries = $this->body(['entries'])['entries'] ?? null;
		if (!is_array($entries)) {
			throw new ValidationException('invalid_acl_entries');
		}

		$result = $this->runAclService->replaceAcl($this->requireId('id'), $entries);

		return new JSONResponse([
			'owner' => $result['owner'],
			'entries' => array_map(
				static fn (RunAcl $entry): array => $entry->toArray(),
				$result['entries'],
			),
		]);
	}
}
