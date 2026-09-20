<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Service\AttachmentService;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON and download endpoints for run step evidence.
 *
 * @phpstan-import-type AttachmentData from Attachment
 */
class AttachmentController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AttachmentService $attachmentService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{attachments: list<AttachmentData>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$attachments = array_map(
			static fn (Attachment $attachment): array => $attachment->toArray(),
			$this->attachmentService->listForRun($this->requireId('id')),
		);

		return new JSONResponse(['attachments' => $attachments]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_CREATED, array{attachment: AttachmentData}, array{}>
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$file = $this->request->getUploadedFile('file');
		if (!is_array($file)) {
			throw new ValidationException('attachment_file_required');
		}

		$attachment = $this->attachmentService->upload($this->requireId('id'), $file);

		return new JSONResponse(['attachment' => $attachment->toArray()], Http::STATUS_CREATED);
	}

	/**
	 * @return DataDownloadResponse<Http::STATUS_OK, string, array{}>
	 */
	#[NoAdminRequired]
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
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		$this->attachmentService->delete($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}
}
