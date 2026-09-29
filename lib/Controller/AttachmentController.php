<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\AttachmentService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON and download endpoints for run step evidence.
 *
 * @psalm-import-type RunbookAttachmentData from ResponseDefinitions
 * @psalm-import-type RunbookAttachmentDataWithState from ResponseDefinitions
 */
class AttachmentController extends ApiController {
	/** Request fields accepted by {@see self::copy()} (issue #53). */
	private const COPY_FIELDS = ['sourcePath'];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AttachmentService $attachmentService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * List evidence attachments for a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{attachments: list<RunbookAttachmentDataWithState>, degraded: bool}, array{}>
	 *
	 * 200: Attachments returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/runs/{id}/attachments')]
	public function index(): JSONResponse {
		$described = $this->attachmentService->describeForRun($this->requireId('id'));
		$attachments = array_map(
			static fn (array $entry): array => $entry['attachment']->toArrayWithState($entry['fileState']),
			$described['attachments'],
		);

		return new JSONResponse([
			'attachments' => $attachments,
			'degraded' => $described['degraded'],
		]);
	}

	/**
	 * Upload an evidence attachment.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{attachment: RunbookAttachmentData}, array{}>
	 *
	 * 201: Attachment created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/attachments')]
	public function create(): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file)) {
			throw new ValidationException('attachment_file_required');
		}

		$attachment = $this->attachmentService->upload($this->requireId('id'), $file);

		return new JSONResponse(['attachment' => $attachment->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Attach a copy of an existing Files item as evidence (issue #53).
	 *
	 * The client submits only an advisory `sourcePath` inside its own Files; the
	 * server resolves and authorises the source, copies the bytes into the run
	 * owner's managed folder and returns the new attachment. The original file is
	 * never modified.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, array{attachment: RunbookAttachmentData}, array{}>
	 *
	 * 201: Attachment created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/run-steps/{id}/attachments/copy')]
	public function copy(): JSONResponse {
		$attachment = $this->attachmentService->copyFromFiles($this->requireId('id'), $this->body(self::COPY_FIELDS));

		return new JSONResponse(['attachment' => $attachment->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * Download an evidence attachment.
	 *
	 * @return DataDownloadResponse<Http::STATUS_OK, string, array{}>
	 *
	 * 200: Attachment content returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/attachments/{id}')]
	public function show(): DataDownloadResponse {
		$result = $this->attachmentService->download($this->requireId('id'));
		$attachment = $result['attachment'];

		return new DataDownloadResponse(
			$result['content'],
			$attachment->getFilename(),
			$attachment->getMimeType(),
		);
	}

	/**
	 * Delete an evidence attachment.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Attachment deleted
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/attachments/{id}')]
	public function destroy(): JSONResponse {
		$this->attachmentService->delete($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}
}
