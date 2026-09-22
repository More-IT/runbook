<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\FlowService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end matrix for the flow engine's dependency and condition semantics.
 *
 * Every case asserts the derived section state (and, where relevant, the safe
 * structured reason) so the backend semantics stay pinned down independently of
 * the frontend.
 */
class FlowMatrixTest extends TestCase {
	private FlowService $flow;

	protected function setUp(): void {
		$this->flow = new FlowService();
	}

	/**
	 * @param list<int> $dependsOn
	 * @param list<array<string, mixed>> $conditions
	 */
	private function section(int $id, string $title, array $dependsOn = [], array $conditions = []): RunSection {
		$section = new RunSection();
		$section->setId($id);
		$section->setRunId(1);
		$section->setTitle($title);
		$section->setDependsOnIds($dependsOn);
		$section->setConditions($conditions);

		return $section;
	}

	private function step(int $id, int $sectionId, string $type, string $status, bool|int|float|string|null $response = null): RunStep {
		$step = new RunStep();
		$step->setId($id);
		$step->setRunSectionId($sectionId);
		$step->setTitle('Step ' . $id);
		$step->setType($type);
		$step->setStatus($status);
		$step->setResponseValue($response);

		return $step;
	}

	private function completed(int $id, int $sectionId, string $type, bool|int|float|string|null $response): RunStep {
		return $this->step($id, $sectionId, $type, RunStepStatus::Completed->value, $response);
	}

	private function pending(int $id, int $sectionId, string $type = 'TEXT'): RunStep {
		return $this->step($id, $sectionId, $type, RunStepStatus::Pending->value);
	}

	// --- 1. One dependency -------------------------------------------------

	public function testOneDependencyResolved(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'X'), $this->section(2, 'B', [1])],
			[$this->completed(10, 1, 'CHECK', true), $this->pending(20, 2)],
		);

		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][1]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testOneDependencyUnresolved(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'X'), $this->section(2, 'B', [1])],
			[$this->pending(10, 1), $this->pending(20, 2)],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][1]);
		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame(['X'], $result['blockedBy'][2]);
		self::assertSame('dependency', $result['reasons'][2][0]['type']);
	}

	public function testOneDependencyInapplicable(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'X', [], [['stepId' => 10, 'operator' => 'is_true']]),
				$this->section(3, 'B', [2]),
			],
			[$this->completed(10, 1, 'CHECK', false), $this->pending(20, 2), $this->pending(30, 3)],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	// --- 2. Multiple dependencies -----------------------------------------

	public function testMultipleDependenciesAllResolved(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'X'), $this->section(2, 'Y'), $this->section(3, 'B', [1, 2])],
			[$this->completed(10, 1, 'CHECK', true), $this->completed(20, 2, 'CHECK', true), $this->pending(30, 3)],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testMultipleDependenciesOneUnresolved(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'X'), $this->section(2, 'Y'), $this->section(3, 'B', [1, 2])],
			[$this->completed(10, 1, 'CHECK', true), $this->pending(20, 2), $this->pending(30, 3)],
		);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][3]);
		self::assertSame(['Y'], $result['blockedBy'][3]);
	}

	public function testMultipleDependenciesOneInapplicableResolvesTheGate(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'X', [], [['stepId' => 10, 'operator' => 'is_true']]),
				$this->section(3, 'Y'),
				$this->section(4, 'B', [2, 3]),
			],
			[
				$this->completed(10, 1, 'CHECK', false),
				$this->pending(20, 2),
				$this->completed(30, 3, 'CHECK', true),
				$this->pending(40, 4),
			],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][3]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][4]);
	}

	public function testMultipleDependenciesBothInapplicable(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'X', [], [['stepId' => 10, 'operator' => 'is_true']]),
				$this->section(3, 'Y', [], [['stepId' => 11, 'operator' => 'is_true']]),
				$this->section(4, 'B', [2, 3]),
			],
			[
				$this->completed(10, 1, 'CHECK', false),
				$this->completed(11, 1, 'CHECK', false),
				$this->pending(20, 2),
				$this->pending(30, 3),
				$this->pending(40, 4),
			],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][4]);
	}

	// --- 3. Single condition operators ------------------------------------

	public function testConditionEqualsTrueAndFalse(): void {
		$sections = [
			$this->section(1, 'Control'),
			$this->section(2, 'Match', [], [['stepId' => 10, 'operator' => 'equals', 'value' => 'prod']]),
			$this->section(3, 'Other', [], [['stepId' => 10, 'operator' => 'equals', 'value' => 'dev']]),
		];
		$result = $this->flow->evaluate($sections, [
			$this->completed(10, 1, 'SELECT', 'prod'),
			$this->pending(20, 2),
			$this->pending(30, 3),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testConditionNotEqualsTrueAndFalse(): void {
		$sections = [
			$this->section(1, 'Control'),
			$this->section(2, 'Different', [], [['stepId' => 10, 'operator' => 'not_equals', 'value' => 'prod']]),
			$this->section(3, 'Same', [], [['stepId' => 10, 'operator' => 'not_equals', 'value' => 'dev']]),
		];
		$result = $this->flow->evaluate($sections, [
			$this->completed(10, 1, 'SELECT', 'dev'),
			$this->pending(20, 2),
			$this->pending(30, 3),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testNumericComparisonOperatorsAtBoundary(): void {
		$sections = [
			$this->section(1, 'Control'),
			$this->section(2, 'GT', [], [['stepId' => 10, 'operator' => 'greater_than', 'value' => 10]]),
			$this->section(3, 'GTE', [], [['stepId' => 10, 'operator' => 'greater_or_equal', 'value' => 10]]),
			$this->section(4, 'LT', [], [['stepId' => 10, 'operator' => 'less_than', 'value' => 10]]),
			$this->section(5, 'LTE', [], [['stepId' => 10, 'operator' => 'less_or_equal', 'value' => 10]]),
		];
		$result = $this->flow->evaluate($sections, [
			$this->completed(10, 1, 'NUMBER', 10),
			$this->pending(20, 2),
			$this->pending(30, 3),
			$this->pending(40, 4),
			$this->pending(50, 5),
		]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][4]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][5]);
	}

	public function testConditionWithMissingResponseStaysPending(): void {
		foreach ([null, ''] as $response) {
			$result = $this->flow->evaluate(
				[
					$this->section(1, 'Control'),
					$this->section(2, 'Gated', [], [['stepId' => 10, 'operator' => 'not_equals', 'value' => 'prod']]),
				],
				[$this->completed(10, 1, 'SELECT', $response), $this->pending(20, 2)],
			);

			self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
			self::assertSame('condition_pending', $result['reasons'][2][0]['type']);
		}
	}

	public function testConditionWithInvalidResponseTypeIsNotSatisfied(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'Gated', [], [['stepId' => 10, 'operator' => 'greater_than', 'value' => 1]]),
			],
			[$this->completed(10, 1, 'CHECK', true), $this->pending(20, 2)],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
	}

	// --- 4. Multiple conditions -------------------------------------------

	public function testMultipleConditionsAllTrue(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'Gated', [], [
					['stepId' => 10, 'operator' => 'equals', 'value' => 'prod'],
					['stepId' => 11, 'operator' => 'greater_than', 'value' => 5],
				]),
			],
			[$this->completed(10, 1, 'SELECT', 'prod'), $this->completed(11, 1, 'NUMBER', 9), $this->pending(20, 2)],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testMultipleConditionsOneFalse(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'Gated', [], [
					['stepId' => 10, 'operator' => 'equals', 'value' => 'prod'],
					['stepId' => 11, 'operator' => 'greater_than', 'value' => 50],
				]),
			],
			[$this->completed(10, 1, 'SELECT', 'prod'), $this->completed(11, 1, 'NUMBER', 9), $this->pending(20, 2)],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertCount(1, $result['reasons'][2]);
	}

	public function testMultipleConditionsOnePendingAndNoneFalse(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'Gated', [], [
					['stepId' => 10, 'operator' => 'equals', 'value' => 'prod'],
					['stepId' => 11, 'operator' => 'is_true'],
				]),
			],
			[$this->completed(10, 1, 'SELECT', 'prod'), $this->pending(11, 1, 'CHECK'), $this->pending(20, 2)],
		);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame('condition_pending', $result['reasons'][2][0]['type']);
	}

	public function testConditionsReferencingDifferentSections(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'A'),
				$this->section(2, 'B'),
				$this->section(3, 'Gated', [], [
					['stepId' => 10, 'operator' => 'is_true'],
					['stepId' => 20, 'operator' => 'equals', 'value' => 'prod'],
				]),
			],
			[
				$this->completed(10, 1, 'CHECK', true),
				$this->completed(20, 2, 'SELECT', 'prod'),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testConditionsCombinedWithDependencies(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'X'),
				$this->section(2, 'Control'),
				$this->section(3, 'Gated', [1], [['stepId' => 20, 'operator' => 'is_true']]),
			],
			[
				$this->pending(10, 1),
				$this->completed(20, 2, 'CHECK', true),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][3]);
		self::assertSame('dependency', $result['reasons'][3][0]['type']);
	}

	// --- 5. Mixed rules ----------------------------------------------------

	public function testDependenciesAndConditionAllSatisfied(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'X'),
				$this->section(2, 'Y'),
				$this->section(3, 'Control'),
				$this->section(4, 'Gated', [1, 2], [['stepId' => 30, 'operator' => 'is_true']]),
			],
			[
				$this->completed(10, 1, 'CHECK', true),
				$this->completed(20, 2, 'CHECK', true),
				$this->completed(30, 3, 'CHECK', true),
				$this->pending(40, 4),
			],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][4]);
	}

	public function testDependencyInapplicableAndConditionSatisfied(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'X', [], [['stepId' => 10, 'operator' => 'is_true']]),
				$this->section(3, 'Gated', [2], [['stepId' => 10, 'operator' => 'is_false']]),
			],
			[
				$this->completed(10, 1, 'CHECK', false),
				$this->pending(20, 2),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testConditionSourceSectionInapplicableKeepsTheGatePending(): void {
		// The controlling step of an inapplicable section is still evaluated on
		// its own status: a pending controlling step keeps the dependent gate
		// blocked (it never silently resolves).
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Other'),
				$this->section(2, 'Source', [], [['stepId' => 10, 'operator' => 'is_true']]),
				$this->section(3, 'Gated', [], [['stepId' => 20, 'operator' => 'is_true']]),
			],
			[
				$this->completed(10, 1, 'CHECK', false),
				$this->pending(20, 2, 'CHECK'),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][3]);
		self::assertSame('condition_pending', $result['reasons'][3][0]['type']);
	}

	public function testConditionSourceReopenedAndChangedReevaluates(): void {
		$sections = [
			$this->section(1, 'Control'),
			$this->section(2, 'Gated', [], [['stepId' => 10, 'operator' => 'equals', 'value' => 'prod']]),
		];

		$before = $this->flow->evaluate($sections, [
			$this->completed(10, 1, 'SELECT', 'prod'),
			$this->pending(20, 2),
		]);
		self::assertSame(FlowService::STATE_AVAILABLE, $before['states'][2]);

		// The controlling step is reopened and answered differently.
		$after = $this->flow->evaluate($sections, [
			$this->step(10, 1, 'SELECT', RunStepStatus::InProgress->value, 'dev'),
			$this->pending(20, 2),
		]);
		self::assertSame(FlowService::STATE_BLOCKED, $after['states'][2]);

		$resolved = $this->flow->evaluate($sections, [
			$this->completed(10, 1, 'SELECT', 'dev'),
			$this->pending(20, 2),
		]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $resolved['states'][2]);
	}

	// --- 6. Realistic section graph ---------------------------------------

	public function testLinearFlow(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'A'), $this->section(2, 'B', [1]), $this->section(3, 'C', [2])],
			[$this->pending(10, 1), $this->pending(20, 2), $this->pending(30, 3)],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][1]);
		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][3]);
	}

	public function testParallelSectionsAreAvailableTogether(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'A'), $this->section(2, 'B'), $this->section(3, 'C')],
			[$this->pending(10, 1), $this->pending(20, 2), $this->pending(30, 3)],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][1]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testConvergingSections(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'A'),
				$this->section(2, 'B'),
				$this->section(3, 'Join', [1, 2]),
			],
			[
				$this->completed(10, 1, 'CHECK', true),
				$this->completed(20, 2, 'CHECK', true),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testAlternativePathsSelectExactlyOneBranch(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Choice'),
				$this->section(2, 'Prod', [], [['stepId' => 10, 'operator' => 'equals', 'value' => 'prod']]),
				$this->section(3, 'Dev', [], [['stepId' => 10, 'operator' => 'equals', 'value' => 'dev']]),
			],
			[
				$this->completed(10, 1, 'SELECT', 'prod'),
				$this->pending(20, 2),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testFinalSectionBecomesAvailableWhenRulesAreSatisfied(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'A'),
				$this->section(2, 'B'),
				$this->section(3, 'Final', [1, 2], [
					['stepId' => 10, 'operator' => 'is_true'],
					['stepId' => 20, 'operator' => 'equals', 'value' => 'prod'],
				]),
			],
			[
				$this->completed(10, 1, 'CHECK', true),
				$this->completed(20, 2, 'SELECT', 'prod'),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testFinalSectionIsEvaluatedIdenticallyWhenListedFirst(): void {
		$final = $this->section(3, 'Final', [1, 2], [
			['stepId' => 10, 'operator' => 'is_true'],
			['stepId' => 20, 'operator' => 'equals', 'value' => 'prod'],
		]);
		$result = $this->flow->evaluate(
			[$final, $this->section(1, 'A'), $this->section(2, 'B')],
			[
				$this->completed(10, 1, 'CHECK', true),
				$this->completed(20, 2, 'SELECT', 'prod'),
				$this->pending(30, 3),
			],
		);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	// --- 7. State transitions ---------------------------------------------

	public function testBlockedBecomesAvailableWhenThePrerequisiteResolves(): void {
		$sections = [$this->section(1, 'X'), $this->section(2, 'B', [1])];

		$blocked = $this->flow->evaluate($sections, [$this->pending(10, 1), $this->pending(20, 2)]);
		self::assertSame(FlowService::STATE_BLOCKED, $blocked['states'][2]);

		$available = $this->flow->evaluate($sections, [$this->completed(10, 1, 'CHECK', true), $this->pending(20, 2)]);
		self::assertSame(FlowService::STATE_AVAILABLE, $available['states'][2]);
	}

	public function testAvailableBecomesActiveWhenAStepStarts(): void {
		$sections = [$this->section(1, 'A')];

		$available = $this->flow->evaluate($sections, [$this->pending(10, 1)]);
		self::assertSame(FlowService::STATE_AVAILABLE, $available['states'][1]);

		$active = $this->flow->evaluate($sections, [$this->step(10, 1, 'TEXT', RunStepStatus::InProgress->value)]);
		self::assertSame(FlowService::STATE_ACTIVE, $active['states'][1]);
	}

	public function testActiveBecomesResolvedWhenStepsFinish(): void {
		$sections = [$this->section(1, 'A')];

		$active = $this->flow->evaluate($sections, [
			$this->step(10, 1, 'TEXT', RunStepStatus::InProgress->value),
			$this->pending(11, 1),
		]);
		self::assertSame(FlowService::STATE_ACTIVE, $active['states'][1]);

		$resolved = $this->flow->evaluate($sections, [
			$this->completed(10, 1, 'TEXT', 'a'),
			$this->step(11, 1, 'TEXT', RunStepStatus::Skipped->value),
		]);
		self::assertSame(FlowService::STATE_RESOLVED, $resolved['states'][1]);
	}

	public function testResolvedBecomesAvailableWhenAStepIsReopened(): void {
		$sections = [$this->section(1, 'A')];

		$resolved = $this->flow->evaluate($sections, [$this->completed(10, 1, 'TEXT', 'a')]);
		self::assertSame(FlowService::STATE_RESOLVED, $resolved['states'][1]);

		$reopened = $this->flow->evaluate($sections, [$this->step(10, 1, 'TEXT', RunStepStatus::Pending->value, 'a')]);
		self::assertSame(FlowService::STATE_AVAILABLE, $reopened['states'][1]);
	}

	public function testBlockedBecomesInapplicableWhenTheConditionIsFalse(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'X'),
				$this->section(2, 'Control'),
				$this->section(3, 'Gated', [1], [['stepId' => 20, 'operator' => 'is_true']]),
			],
			[
				$this->pending(10, 1),
				$this->completed(20, 2, 'CHECK', false),
				$this->pending(30, 3),
			],
		);

		// A decided-false condition wins over an unsatisfied dependency.
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	// --- 8. Zero-step sections keep their real flow state --------------------

	public function testZeroStepSectionResolvesWhenRulesAreSatisfied(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'X'), $this->section(2, 'Info', [1])],
			[$this->completed(10, 1, 'CHECK', true)],
		);

		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][2]);
		self::assertSame([], $result['reasons'][2] ?? []);
	}

	public function testZeroStepSectionStaysBlockedOnAnUnsatisfiedDependency(): void {
		$result = $this->flow->evaluate(
			[$this->section(1, 'X'), $this->section(2, 'Info', [1])],
			[$this->pending(10, 1)],
		);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame(['X'], $result['blockedBy'][2]);
		self::assertSame('dependency', $result['reasons'][2][0]['type']);
		self::assertSame('X', $result['reasons'][2][0]['title']);
	}

	public function testZeroStepSectionStaysBlockedOnAPendingCondition(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'Info', [], [['stepId' => 10, 'operator' => 'is_true']]),
			],
			[$this->pending(10, 1, 'CHECK')],
		);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame('condition_pending', $result['reasons'][2][0]['type']);
	}

	public function testZeroStepSectionIsInapplicableAndKeepsEveryConditionReason(): void {
		$result = $this->flow->evaluate(
			[
				$this->section(1, 'Control'),
				$this->section(2, 'Info', [], [
					['stepId' => 10, 'operator' => 'is_true'],
					['stepId' => 11, 'operator' => 'is_true'],
				]),
			],
			[$this->completed(10, 1, 'CHECK', false), $this->completed(11, 1, 'CHECK', false)],
		);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertCount(2, $result['reasons'][2]);
		self::assertSame('condition_false', $result['reasons'][2][0]['type']);
		self::assertSame('condition_false', $result['reasons'][2][1]['type']);
	}
}
