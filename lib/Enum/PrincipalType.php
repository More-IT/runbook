<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Kind of principal an access control entry applies to.
 *
 * Runbook only stores the principal type and identifier; Nextcloud remains the
 * source of truth for users, groups and their memberships.
 */
enum PrincipalType: string {
	case User = 'USER';
	case Group = 'GROUP';

	public function isUser(): bool {
		return $this === self::User;
	}

	public function isGroup(): bool {
		return $this === self::Group;
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
