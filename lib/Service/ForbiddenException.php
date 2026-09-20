<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

/**
 * Raised when the current user is not allowed to perform an action.
 */
class ForbiddenException extends ServiceException {
}
