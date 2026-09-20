<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Access roles a principal can hold on a template.
 *
 * Rank expresses the strength of a role so that overlapping direct and group
 * entries always resolve to the strongest one. OWNER is implicit for the
 * template owner and is never stored as an ACL row.
 */
enum AclRole: string {
	case Viewer = 'VIEWER';
	case Executor = 'EXECUTOR';
	case Editor = 'EDITOR';
	case Owner = 'OWNER';

	public function rank(): int {
		return match ($this) {
			self::Viewer => 1,
			self::Executor => 2,
			self::Editor => 3,
			self::Owner => 4,
		};
	}

	public function canView(): bool {
		return true;
	}

	public function canExecute(): bool {
		return $this->rank() >= self::Executor->rank();
	}

	public function canEdit(): bool {
		return $this->rank() >= self::Editor->rank();
	}

	public function canManageAcl(): bool {
		return $this === self::Owner;
	}

	public function canDelete(): bool {
		return $this === self::Owner;
	}

	/**
	 * Roles that may be assigned through the ACL API.
	 *
	 * OWNER is excluded because ownership is represented by the template owner.
	 *
	 * @return list<self>
	 */
	public static function assignable(): array {
		return [self::Viewer, self::Executor, self::Editor];
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
