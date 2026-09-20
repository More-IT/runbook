<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Lifecycle status of a single run step.
 */
enum RunStepStatus: string {
	case Pending = 'PENDING';
	case InProgress = 'IN_PROGRESS';
	case Completed = 'COMPLETED';
	case Skipped = 'SKIPPED';

	/**
	 * Whether the status counts as resolved for run completion.
	 */
	public function isResolved(): bool {
		return $this === self::Completed || $this === self::Skipped;
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
