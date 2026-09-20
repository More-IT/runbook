<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * Base class for domain errors raised by the Runbook services.
 *
 * The reason is a machine readable code. It is intentionally not a
 * user-facing string; the API middleware translates the error type and the
 * frontend may map the reason to a specific message.
 */
abstract class ServiceException extends \Exception {
	public function __construct(
		private readonly string $reason,
	) {
		parent::__construct($reason);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
