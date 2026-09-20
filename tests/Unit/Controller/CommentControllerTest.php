<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Controller;

use OCA\Runbook\Controller\CommentController;
use OCA\Runbook\Db\Comment;
use OCA\Runbook\Service\CommentService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CommentControllerTest extends TestCase {
	/** @var CommentService&MockObject */
	private CommentService $commentService;

	protected function setUp(): void {
		$this->commentService = $this->createMock(CommentService::class);
	}

	/**
	 * @param array<string, mixed> $params Request parameters.
	 */
	private function controller(array $params): CommentController {
		/** @var IRequest&MockObject $request */
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default,
		);
		$request->method('getParams')->willReturn($params);

		return new CommentController('runbook', $request, $this->commentService);
	}

	private function comment(int $id = 1): Comment {
		$comment = new Comment();
		$comment->setId($id);
		$comment->setUuid('00000000-0000-4000-8000-000000000000');
		$comment->setRunId(1);
		$comment->setStepId(null);
		$comment->setAuthorUid('alice');
		$comment->setBody('Looks good');
		$comment->setCreatedAt(1000);
		$comment->setUpdatedAt(1000);

		return $comment;
	}

	/**
	 * @return array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>}
	 */
	private function item(): array {
		return [
			'comment' => $this->comment(),
			'authorDisplayName' => 'alice',
			'mentions' => [['uid' => 'bob', 'displayName' => 'bob']],
		];
	}

	public function testIndexReturnsComments(): void {
		$this->commentService->expects(self::once())
			->method('listComments')
			->with(1)
			->willReturn([$this->item()]);

		$response = $this->controller(['id' => '1'])->index();

		self::assertSame(200, $response->getStatus());
		self::assertSame('Looks good', $response->getData()['comments'][0]['comment']['body']);
		self::assertSame('bob', $response->getData()['comments'][0]['mentions'][0]['uid']);
	}

	public function testCreateReturnsCreatedComment(): void {
		$this->commentService->expects(self::once())
			->method('createComment')
			->with(1, ['body' => 'Hi', 'stepId' => '5'])
			->willReturn($this->item());

		$response = $this->controller(['id' => '1', 'body' => 'Hi', 'stepId' => '5'])->create();

		self::assertSame(201, $response->getStatus());
		self::assertSame('alice', $response->getData()['authorDisplayName']);
	}

	public function testUpdateReturnsUpdatedComment(): void {
		$this->commentService->expects(self::once())
			->method('updateComment')
			->with(2, ['body' => 'Edited'])
			->willReturn($this->item());

		$response = $this->controller(['id' => '2', 'body' => 'Edited'])->update();

		self::assertSame(200, $response->getStatus());
		self::assertSame('Looks good', $response->getData()['comment']['body']);
	}

	public function testDestroyReturnsSuccess(): void {
		$this->commentService->expects(self::once())->method('deleteComment')->with(2);

		$response = $this->controller(['id' => '2'])->destroy();

		self::assertTrue($response->getData()['success']);
	}
}
