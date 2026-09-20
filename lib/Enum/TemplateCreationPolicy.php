<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Administration policy that controls who may create templates.
 */
enum TemplateCreationPolicy: string {
	case Everyone = 'everyone';
	case SelectedGroups = 'selected_groups';
	case Admins = 'admins';

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
