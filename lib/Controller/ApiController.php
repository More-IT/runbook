<?php

declare(strict_types=1);

namespace OCA\Runbook\Controller;

use OCA\Runbook\Service\ValidationException;
use OCP\AppFramework\Controller;

/**
 * Small base class for the Runbook JSON controllers.
 *
 * It centralises typed extraction of route and body parameters so that every
 * controller validates its input server-side.
 */
abstract class ApiController extends Controller {
	/**
	 * Collect the requested fields from the request parameters.
	 *
	 * @param list<string> $fields
	 * @return array<string, mixed>
	 */
	protected function body(array $fields): array {
		$params = $this->request->getParams();
		/** @var array<string, mixed> $params */
		return array_intersect_key($params, array_flip($fields));
	}

	/**
	 * Return request parameters with the broad Nextcloud request type narrowed
	 * to the scalar values accepted by the public JSON routes.
	 *
	 * @return array<string, mixed>
	 */
	protected function requestParams(): array {
		/** @var array<string, mixed> $params */
		$params = $this->request->getParams();
		return $params;
	}

	/**
	 * Read a request parameter through a helper so public controller methods can
	 * expose typed parameters to the OpenAPI extractor while retaining the
	 * legacy unit-test and controller invocation path.
	 *
	 * @param null|string $default
	 *
	 * @psalm-param ''|'all'|null $default
	 * @return string|int|null
	 */
	protected function param(string $name, ?string $default = null): string|int|null {
		return $this->normaliseScalar($this->request->getParam($name, $default));
	}

	/**
	 * Read a required, non-negative integer route parameter.
	 */
	protected function requireId(string $name): int {
		$value = $this->requireInt($name);
		if ($value < 0) {
			throw new ValidationException('invalid_id');
		}

		return $value;
	}

	/**
	 * Read an optional, non-negative integer request parameter.
	 */
	protected function optionalInt(string $name, ?int $default = null): ?int {
		$value = $this->normaliseScalar($this->request->getParam($name));
		if ($value === null) {
			return $default;
		}
		if (is_int($value)) {
			return $value;
		}
		if (preg_match('/^[0-9]+$/', $value) === 1) {
			return (int)$value;
		}

		throw new ValidationException('invalid_field');
	}

	/**
	 * Read a required integer request parameter.
	 */
	protected function requireInt(string $name): int {
		$value = $this->normaliseScalar($this->request->getParam($name));
		if (is_int($value)) {
			return $value;
		}
		if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
			return (int)$value;
		}

		throw new ValidationException('invalid_field');
	}

	private function normaliseScalar(mixed $value): string|int|null {
		if ($value === null || is_string($value) || is_int($value)) {
			return $value;
		}

		throw new ValidationException('invalid_field');
	}
}
