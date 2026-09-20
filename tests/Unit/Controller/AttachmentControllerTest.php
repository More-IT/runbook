<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\AttachmentController;
use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Service\AttachmentService;
use OCA\Runbook\Service\ValidationException;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AttachmentControllerTest extends TestCase {
	/** @var AttachmentService&MockObject */
	private AttachmentService $attachmentService;

	protected function setUp(): void {
		$this->attachmentService = $this->createMock(AttachmentService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 * @param array<string, mixed>|null $file Uploaded file entry.
	 */
	private function controller(array $params, ?array $file = null): AttachmentController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);
		$request->method('getUploadedFile')->willReturn($file);

		return new AttachmentController('runbook', $request, $this->attachmentService);
	}

	private function attachment(int $id = 1): Attachment {
		$attachment = new Attachment();
		$attachment->setId($id);
		$attachment->setUuid('00000000-0000-4000-8000-000000000000');
		$attachment->setRunId(1);
		$attachment->setStepId(7);
		$attachment->setUploaderUid('alice');
		$attachment->setFilename('evidence.txt');
		$attachment->setStorageKey('secret');
		$attachment->setMimeType('text/plain');
		$attachment->setSize(5);
		$attachment->setChecksum('abc');
		$attachment->setCreatedAt(1000);

		return $attachment;
	}

	public function testIndexReturnsAttachmentsWithoutStorageKey(): void {
		$this->attachmentService->expects(self::once())
			->method('listForRun')
			->with(1)
			->willReturn([$this->attachment()]);

		$response = $this->controller(['id' => '1'])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame('evidence.txt', $response->getData()['attachments'][0]['filename']);
		self::assertArrayNotHasKey('storageKey', $response->getData()['attachments'][0]);
	}

	public function testCreateReturnsCreatedAttachment(): void {
		$file = ['name' => 'evidence.txt', 'tmp_name' => '/tmp/x', 'error' => 0];
		$this->attachmentService->expects(self::once())
			->method('upload')
			->with(7, $file)
			->willReturn($this->attachment());

		$response = $this->controller(['id' => '7'], $file)->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('evidence.txt', $response->getData()['attachment']['filename']);
	}

	public function testCreateWithoutFileIsRejected(): void {
		$this->attachmentService->expects(self::never())->method('upload');

		$this->expectException(ValidationException::class);
		$this->controller(['id' => '7'], null)->create();
	}

	public function testDestroyReturnsSuccess(): void {
		$this->attachmentService->expects(self::once())->method('delete')->with(3);

		$response = $this->controller(['id' => '3'])->destroy();

		self::assertTrue($response->getData()['success']);
	}
}
