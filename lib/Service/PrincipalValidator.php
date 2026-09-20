<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Validates user and group principals through Nextcloud public APIs.
 */
class PrincipalValidator {
	private const MAX_PRINCIPAL_ID_LENGTH = 255;

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * Validate a principal type and identifier pair.
	 *
	 * @return array{type: PrincipalType, id: string}
	 */
	public function validate(mixed $type, mixed $id): array {
		$principalType = $this->validateType($type);
		$principalId = $this->validateId($principalType, $id);

		return ['type' => $principalType, 'id' => $principalId];
	}

	private function validateType(mixed $type): PrincipalType {
		if (!is_string($type)) {
			throw new ValidationException('invalid_principal_type');
		}

		$principalType = PrincipalType::tryFrom($type);
		if ($principalType === null) {
			throw new ValidationException('invalid_principal_type');
		}

		return $principalType;
	}

	private function validateId(PrincipalType $type, mixed $id): string {
		if (!is_string($id)) {
			throw new ValidationException('invalid_principal_id');
		}

		$principalId = trim($id);
		if ($principalId === '') {
			throw new ValidationException('empty_principal_id');
		}
		if (mb_strlen($principalId) > self::MAX_PRINCIPAL_ID_LENGTH) {
			throw new ValidationException('invalid_principal_id');
		}

		if ($type === PrincipalType::User) {
			if (!$this->userManager->userExists($principalId)) {
				throw new ValidationException('unknown_user');
			}
		} elseif (!$this->groupManager->groupExists($principalId)) {
			throw new ValidationException('unknown_group');
		}

		return $principalId;
	}

	/**
	 * Parse a Nextcloud principal string such as "principals/users/alice" or
	 * "principals/groups/engineering".
	 *
	 * @return array{type: PrincipalType, id: string}|null Null when the value
	 *                                                     does not use the supported principal format.
	 */
	public function parsePrincipalString(?string $value): ?array {
		if ($value === null) {
			return null;
		}

		$value = trim($value);
		if (preg_match('#^principals/users/(.+)$#', $value, $matches) === 1) {
			return ['type' => PrincipalType::User, 'id' => trim($matches[1])];
		}
		if (preg_match('#^principals/groups/(.+)$#', $value, $matches) === 1) {
			return ['type' => PrincipalType::Group, 'id' => trim($matches[1])];
		}

		return null;
	}

	/**
	 * Whether a parsed principal still exists in Nextcloud.
	 */
	public function exists(PrincipalType $type, string $id): bool {
		if ($type === PrincipalType::User) {
			return $this->userManager->userExists($id);
		}

		return $this->groupManager->groupExists($id);
	}
}
