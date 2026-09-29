<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Db\Comment;
use OCA\Runbook\ResponseDefinitions;
use OCA\Runbook\Service\CommentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * JSON endpoints for run and step comments.
 *
 * @psalm-import-type RunbookCommentData from ResponseDefinitions
 *
 * @psalm-type RunbookCommentItemData array{
 *     comment: RunbookCommentData,
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
	 * List comments for a run.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{comments: list<RunbookCommentItemData>}, array{}>
	 *
	 * 200: Comments returned
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/runs/{id}/comments')]
	public function index(): JSONResponse {
		$comments = array_map(
			fn (array $item): array => $this->serialize($item),
			$this->commentService->listComments($this->requireId('id')),
		);

		return new JSONResponse(['comments' => $comments]);
	}

	/**
	 * Create a comment.
	 *
	 * @return JSONResponse<Http::STATUS_CREATED, RunbookCommentItemData, array{}>
	 *
	 * 201: Comment created
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'POST', url: '/api/v1/runs/{id}/comments')]
	public function create(): JSONResponse {
		$item = $this->commentService->createComment(
			$this->requireId('id'),
			$this->body(['body', 'stepId']),
		);

		return new JSONResponse($this->serialize($item), Http::STATUS_CREATED);
	}

	/**
	 * Update a comment.
	 *
	 * @return JSONResponse<Http::STATUS_OK, RunbookCommentItemData, array{}>
	 *
	 * 200: Comment updated
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'PATCH', url: '/api/v1/comments/{id}')]
	public function update(): JSONResponse {
		$item = $this->commentService->updateComment(
			$this->requireId('id'),
			$this->body(['body']),
		);

		return new JSONResponse($this->serialize($item));
	}

	/**
	 * Delete a comment.
	 *
	 * @return JSONResponse<Http::STATUS_OK, array{success: bool}, array{}>
	 *
	 * 200: Comment deleted
	 */
	#[NoAdminRequired]
	#[OpenAPI]
	#[FrontpageRoute(verb: 'DELETE', url: '/api/v1/comments/{id}')]
	public function destroy(): JSONResponse {
		$this->commentService->deleteComment($this->requireId('id'));

		return new JSONResponse(['success' => true]);
	}

	/**
	 * @param array{comment: Comment, authorDisplayName: string, mentions: list<array{uid: string, displayName: string}>} $item
	 * @return RunbookCommentItemData
	 */
	private function serialize(array $item): array {
		return [
			'comment' => $item['comment']->toArray(),
			'authorDisplayName' => $item['authorDisplayName'],
			'mentions' => $item['mentions'],
		];
	}
}
