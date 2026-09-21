<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\StepType;
use OCA\Runbook\Service\FlowService;
use PHPUnit\Framework\TestCase;

/**
 * Guarantees the frontend operator map (used by the authoring UI) matches the
 * backend map used by template validation and run-snapshot evaluation, so the
 * three layers can never disagree about which operators are legal.
 */
class ConditionOperatorParityTest extends TestCase {
	public function testFrontendOperatorMapMatchesBackend(): void {
		$path = dirname(__DIR__, 3) . '/src/models/template.ts';
		self::assertFileExists($path);
		$source = (string)file_get_contents($path);

		$backend = FlowService::operatorsByType();
		self::assertNotSame([], $backend);

		foreach (StepType::cases() as $type) {
			$operators = $backend[$type->value] ?? [];
			$literal = array_map(static fn (string $operator): string => "'" . $operator . "'", $operators);
			$expected = sprintf('%s: [%s]', $type->value, implode(', ', $literal));
			self::assertStringContainsString(
				$expected,
				$source,
				sprintf('Frontend operator map for %s must match FlowService::operatorsByType()', $type->value),
			);
		}
	}
}
