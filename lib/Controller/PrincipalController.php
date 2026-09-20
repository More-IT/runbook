<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Service\AclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoint used by the ACL editor to search Nextcloud users and groups.
 */
class PrincipalController extends ApiController {
	private const DEFAULT_LIMIT = 25;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AclService $aclService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{principals: list<array{principalType: string, principalId: string, displayName: string}>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$search = $this->request->getParam('search', '');
		if (!is_string($search)) {
			throw new ValidationException('invalid_field');
		}

		$limit = self::DEFAULT_LIMIT;
		$rawLimit = $this->request->getParam('limit');
		if (is_int($rawLimit)) {
			$limit = $rawLimit;
		} elseif (is_string($rawLimit) && preg_match('/^[0-9]+$/', $rawLimit) === 1) {
			$limit = (int)$rawLimit;
		}

		return new JSONResponse([
			'principals' => $this->aclService->searchPrincipals($search, $limit),
		]);
	}
}
