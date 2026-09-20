<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Comment;
use OCA\Runbook\Service\CommentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for run and step comments.
 *
 * @phpstan-import-type CommentData from Comment
 *
 * @phpstan-type CommentItemData array{
 *     comment: CommentData,
 *     authorDisplayName: string,
 *     mentions: list<array{uid: string, displayName: string}>
 * }
 */
class CommentController extends ApiController {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CommentService $commentService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{comments: list<CommentItemData>}, array{}>
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$comments = array_map(
			fn (array $item): array => $this->serialize($item),
			$this->commentService->listComments($this->requireId('id')),
		);

		return new JSONResponse(['comments' => $comments]);
	}

	/**
	 * @return JSONResponse<Http::STATUS_CREATED, CommentItemData, array{}>
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$item = $this->commentService->createComment(
			$this->requireId('id'),
			$this->body(['body', 'stepId']),
		);

		return new JSONResponse($this->serialize($item), Http::STATUS_CREATED);
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, CommentItemData, array{}>
	 */
	#[NoAdminRequired]
	public function update(): JSONResponse {
		$item = $this->commentService->updateComment(
			$this->requireId('id'),
			$this->body(['body']),
		);

		return new JSONResponse($this->serialize($item));
	}

	/**
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 */
	#[NoAdminRequired]
	public function destroy(): JSONResponse {
		$this->commentService->deleteComment($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * @param array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>} $item
	 * @return CommentItemData
	 */
	private function serialize(array $item): array {
		return [
			'comment' => $item['comment']->toArray(),
			'authorDisplayName' => $item['authorDisplayName'],
			'mentions' => $item['mentions'],
		];
	}
}
