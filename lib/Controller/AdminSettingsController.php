<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\RunDestinationResolver;
use OCA\Runbook\Service\TemplateCreationPolicyService;
use OCA\Runbook\Service\ValidationException;
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
		private readonly RunDestinationResolver $destinations,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{settings: array<string, mixed>, bounds: array<string, int>, destination: array<string, mixed>}, array{}>
	 */
	public function index(): JSONResponse {
		return new JSONResponse([
			'settings' => $this->settings->getAll(),
			'bounds' => $this->settings->getBounds(),
			'destination' => $this->settings->describeDestination(),
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
	 * Save the global administration destination folder (#47).
	 *
	 * The client submits a user-visible path from the authenticated
	 * administrator's own Files; the folder is re-resolved server-side and its
	 * identity is captured there. A failed save never changes the stored
	 * reference.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{destination: array<string, mixed>}, array{}>
	 */
	public function updateDestination(): JSONResponse {
		$uid = $this->currentUserId();
		$path = $this->request->getParam('path');
		if (!is_string($path)) {
			throw new ValidationException('invalid_field');
		}

		$reference = $this->destinations->captureReference($uid, $path);
		$this->settings->saveDestinationReference(
			$reference->storageId,
			$reference->fileId,
			$reference->path,
			$uid,
		);

		return new JSONResponse(['destination' => $this->settings->describeDestination()]);
	}

	/**
	 * Clear the global administration destination folder (#47), restoring the
	 * explicit unset state and the #46 default behaviour.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{destination: array<string, mixed>}, array{}>
	 */
	public function clearDestination(): JSONResponse {
		$this->settings->clearDestinationReference();

		return new JSONResponse(['destination' => $this->settings->describeDestination()]);
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
