<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Supported template step types.
 *
 * M1 only stores and edits the step definitions; runtime execution is
 * implemented in a later milestone.
 */
enum StepType: string {
	case Check = 'CHECK';
	case Confirmation = 'CONFIRMATION';
	case Text = 'TEXT';
	case Number = 'NUMBER';
	case Select = 'SELECT';
	case Date = 'DATE';
	case User = 'USER';
	case File = 'FILE';

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
