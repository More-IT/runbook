<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Administration settings endpoints.
 *
 * The read and write endpoints are intentionally not annotated with
 * `#[NoAdminRequired]`, so Nextcloud only allows administrators to call them.
 * The read-only `features` endpoint is available to authenticated users so the
 * main application can reflect globally disabled features in its UI.
 */
class AdminSettingsController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AdminSettings $settings,
		private readonly TemplateCreationPolicyService $creationPolicy,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{settings: array<string, mixed>, bounds: array<string, int>}, array{}>
	 */
	public function index(): JSONResponse {
		return new JSONResponse([
			'settings' => $this->settings->getAll(),
			'bounds' => $this->settings->getBounds(),
		]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{settings: array<string, mixed>, bounds: array<string, int>}, array{}>
	 */
	public function update(): JSONResponse {
		$data = $this->body(array_keys($this->settings->getDefaults()));
		$updated = $this->settings->update($data);

		return new JSONResponse([
			'settings' => $updated,
			'bounds' => $this->settings->getBounds(),
		]);
	}

	/**
	 * Feature flags that affect the main application UI.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{features: array<string, mixed>}, array{}>
	 */
	#[NoAdminRequired]
	public function features(): JSONResponse {
		$uid = $this->currentUserId();

		return new JSONResponse([
			'features' => [
				'commentsEnabled' => $this->settings->isCommentsEnabled(),
				'stepReopenEnabled' => $this->settings->isStepReopenEnabled(),
				'requireSkipReason' => $this->settings->isSkipReasonRequired(),
				'runReopenEnabled' => $this->settings->isRunReopenEnabled(),
				'maxAttachmentSize' => $this->settings->getMaxAttachmentSize(),
				'canCreateTemplates' => $this->creationPolicy->canCreate($uid),
				'uid' => $uid,
				'isAdmin' => $this->groupManager->isAdmin($uid),
			],
		]);
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('not_authenticated');
		}

		return $user->getUID();
	}
}
