<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Enum\StepType;
use OCP\IUserManager;

/**
 * Validates and normalizes a step response according to its step type.
 *
 * Validation is always performed server-side; never trust a response sent by
 * the frontend.
 */
class StepResponseValidator {
	private const MAX_TEXT_LENGTH = 10000;
	private const MAX_USER_ID_LENGTH = 64;

	public function __construct(
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * @param array<string, mixed> $config
	 * @return bool|int|float|string Normalized response value.
	 */
	public function validate(StepType $type, mixed $raw, array $config): bool|int|float|string {
		return match ($type) {
			StepType::Check, StepType::Confirmation => $this->validateBoolean($raw),
			StepType::Text => $this->validateText($raw),
			StepType::Number => $this->validateNumber($raw),
			StepType::Select => $this->validateSelect($raw, $config),
			StepType::Date => $this->validateDate($raw),
			StepType::User => $this->validateUser($raw),
			StepType::File => throw new ValidationException('file_upload_not_supported'),
		};
	}

	private function validateBoolean(mixed $raw): bool {
		if (!is_bool($raw)) {
			throw new ValidationException('invalid_boolean_response');
		}

		return $raw;
	}

	private function validateText(mixed $raw): string {
		if (!is_string($raw)) {
			throw new ValidationException('invalid_text_response');
		}
		if (mb_strlen($raw) > self::MAX_TEXT_LENGTH) {
			throw new ValidationException('response_too_long');
		}

		return $raw;
	}

	private function validateNumber(mixed $raw): int|float {
		if (is_int($raw) || is_float($raw)) {
			return $raw;
		}
		if (is_string($raw)) {
			$trimmed = trim($raw);
			if ($trimmed !== '' && is_numeric($trimmed)) {
				return $trimmed + 0;
			}
		}

		throw new ValidationException('invalid_number_response');
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private function validateSelect(mixed $raw, array $config): string {
		if (!is_string($raw)) {
			throw new ValidationException('invalid_select_response');
		}

		$options = $config['options'] ?? null;
		if (!is_array($options) || $options === []) {
			throw new ValidationException('invalid_select_option');
		}

		$allowed = [];
		foreach ($options as $option) {
			if (is_string($option)) {
				$allowed[] = $option;
			}
		}

		if (!in_array($raw, $allowed, true)) {
			throw new ValidationException('invalid_select_option');
		}

		return $raw;
	}

	private function validateDate(mixed $raw): string {
		if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
			throw new ValidationException('invalid_date_response');
		}

		[$year, $month, $day] = array_map('intval', explode('-', $raw));
		if (!checkdate($month, $day, $year)) {
			throw new ValidationException('invalid_date_response');
		}

		return $raw;
	}

	private function validateUser(mixed $raw): string {
		if (!is_string($raw)) {
			throw new ValidationException('invalid_user_response');
		}

		$uid = trim($raw);
		if ($uid === '' || mb_strlen($uid) > self::MAX_USER_ID_LENGTH) {
			throw new ValidationException('invalid_user_response');
		}
		if (!$this->userManager->userExists($uid)) {
			throw new ValidationException('invalid_user_response');
		}

		return $uid;
	}
}
