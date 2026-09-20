<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * Raised when an action is not allowed in the current state.
 */
class ConflictException extends ServiceException {
}
