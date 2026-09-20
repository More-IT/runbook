<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Access roles a principal can hold on a run.
 *
 * The run owner is always OWNER and is never stored as an ACL row. Assigning a
 * user or group to a run or to a step grants PARTICIPANT level access.
 */
enum RunAclRole: string {
	case Owner = 'OWNER';
	case Participant = 'PARTICIPANT';
	case Viewer = 'VIEWER';

	public function rank(): int {
		return match ($this) {
			self::Viewer => 1,
			self::Participant => 2,
			self::Owner => 3,
		};
	}

	public function canView(): bool {
		return true;
	}

	public function canManage(): bool {
		return $this === self::Owner;
	}

	/**
	 * Whether the role may execute assigned steps.
	 */
	public function canExecuteAssignedSteps(): bool {
		return $this === self::Owner || $this === self::Participant;
	}

	/**
	 * Roles that may be assigned through the run ACL API.
	 *
	 * OWNER is excluded because ownership is represented by the run owner.
	 *
	 * @return list<self>
	 */
	public static function assignable(): array {
		return [self::Participant, self::Viewer];
	}

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
