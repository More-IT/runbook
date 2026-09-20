<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Lifecycle status of a run.
 *
 * Allowed transitions:
 * - ACTIVE -> COMPLETED
 * - ACTIVE -> CANCELLED
 * - COMPLETED -> ACTIVE (explicit reopen only)
 * CANCELLED runs cannot be reopened.
 */
enum RunStatus: string {
	case Active = 'ACTIVE';
	case Completed = 'COMPLETED';
	case Cancelled = 'CANCELLED';

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
