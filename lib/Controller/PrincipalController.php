<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Service\AclService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
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
	 * Search users and groups available to the ACL editor.
	 *
	 * @param string $search Search term.
	 * @param int $limit Maximum number of results.
	 * @return JSONResponse<Http::STATUS_OK, array{principals: list<array{principalType: string, principalId: string, displayName: string}>}, array{}>
	 *
	 * 200: Principals returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/principals')]
	public function index(string $search = '', int $limit = self::DEFAULT_LIMIT): JSONResponse {
		if ($search === '') {
			$value = $this->param('search', '');
			if (is_string($value)) {
				$search = $value;
			}
		}
		if ($limit < 0) {
			throw new ValidationException('invalid_field');
		}

		return new JSONResponse([
			'principals' => $this->aclService->searchPrincipals($search, $limit),
		]);
	}
}
