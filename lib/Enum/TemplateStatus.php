<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Lifecycle status of a runbook template.
 */
enum TemplateStatus: string {
	case Draft = 'DRAFT';
	case Published = 'PUBLISHED';
	case Archived = 'ARCHIVED';

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
