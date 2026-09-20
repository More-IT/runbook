<?php

declare(strict_types=1);

namespace OCA\Runbook\Middleware;

use Exception;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IL10N;

/**
 * Maps domain exceptions raised by the services to JSON error responses.
 *
 * Messages are translated with the app l10n service so that no user-facing
 * text is hardcoded in the response payloads.
 */
class ExceptionMiddleware extends Middleware {
	public function __construct(
		private readonly IL10N $l,
	) {
	}

	/**
	 * @return Response<Http::STATUS_*, array<string, mixed>>
	 */
	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($exception instanceof ValidationException) {
			return $this->error(Http::STATUS_BAD_REQUEST, 'validation_error', $exception->getReason());
		}
		if ($exception instanceof ForbiddenException) {
			return $this->error(Http::STATUS_FORBIDDEN, 'forbidden', $exception->getReason());
		}
		if ($exception instanceof NotFoundException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'not_found', $exception->getReason());
		}
		if ($exception instanceof ConflictException) {
			return $this->error(Http::STATUS_CONFLICT, 'conflict', $exception->getReason());
		}
		if ($exception instanceof DoesNotExistException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'not_found', 'resource_not_found');
		}

		throw $exception;
	}

	/**
	 * @param Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT $status
	 *
	 * @return JSONResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_FORBIDDEN|Http::STATUS_NOT_FOUND|Http::STATUS_CONFLICT, array{error: string, reason: string, message: string}, array{}>
	 */
	private function error(int $status, string $error, string $reason): JSONResponse {
		$message = match ($error) {
			'validation_error' => $this->l->t('The submitted data is invalid.'),
			'forbidden' => $this->l->t('You are not allowed to perform this action.'),
			'not_found' => $this->l->t('The requested resource was not found.'),
			'conflict' => $this->l->t('The operation is not allowed in the current state.'),
			default => $this->l->t('An unexpected error occurred.'),
		};

		return new JSONResponse([
			'error' => $error,
			'reason' => $reason,
			'message' => $message,
		], $status);
	}
}
