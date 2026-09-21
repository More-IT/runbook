<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\FlowService;
use PHPUnit\Framework\TestCase;

class FlowServiceTest extends TestCase {
	private FlowService $flow;

	protected function setUp(): void {
		$this->flow = new FlowService();
	}

	/**
	 * @param list<int> $dependsOn
	 * @param array<string, mixed>|null $condition
	 */
	private function section(int $id, string $title, array $dependsOn = [], ?array $condition = null): RunSection {
		$section = new RunSection();
		$section->setId($id);
		$section->setRunId(1);
		$section->setTitle($title);
		$section->setDependsOnIds($dependsOn);
		$section->setCondition($condition);

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

	public function testEmptySectionsAreResolved(): void {
		$result = $this->flow->evaluate([$this->section(1, 'A'), $this->section(2, 'B')], []);

		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][1]);
		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][2]);
	}

	public function testDependencyBlocksUntilPrerequisiteIsResolved(): void {
		$a = $this->section(1, 'A');
		$b = $this->section(2, 'B', [1]);
		$steps = [
			$this->step(10, 1, 'CHECK', RunStepStatus::Pending->value),
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		];

		$result = $this->flow->evaluate([$a, $b], $steps);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][1]);
		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame(['A'], $result['blockedBy'][2]);
		self::assertContains(20, $result['blockedStepIds']);
		self::assertNotContains(10, $result['blockedStepIds']);
	}

	public function testResolvedPrerequisiteUnblocksDependentSection(): void {
		$a = $this->section(1, 'A');
		$b = $this->section(2, 'B', [1]);
		$steps = [
			$this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true),
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		];

		$result = $this->flow->evaluate([$a, $b], $steps);

		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][1]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testInProgressSectionIsActive(): void {
		$a = $this->section(1, 'A');
		$steps = [$this->step(10, 1, 'TEXT', RunStepStatus::InProgress->value)];

		$result = $this->flow->evaluate([$a], $steps);

		self::assertSame(FlowService::STATE_ACTIVE, $result['states'][1]);
	}

	public function testInapplicableSectionResolvesAndDoesNotBlockDependents(): void {
		$a = $this->section(1, 'A');
		$control = $this->step(10, 1, 'CONFIRMATION', RunStepStatus::Completed->value, false);
		$b = $this->section(2, 'B', [], ['stepId' => 10, 'operator' => 'is_true']);
		$c = $this->section(3, 'C', [2]);
		$bStep = $this->step(20, 2, 'TEXT', RunStepStatus::Pending->value);
		$cStep = $this->step(30, 3, 'TEXT', RunStepStatus::Pending->value);

		$result = $this->flow->evaluate([$a, $b, $c], [$control, $bStep, $cStep]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
		self::assertContains(20, $result['inapplicableStepIds']);
	}

	public function testPendingDecisionBlocksWithControllingStep(): void {
		$a = $this->section(1, 'A');
		$control = $this->step(10, 1, 'CONFIRMATION', RunStepStatus::Pending->value);
		$b = $this->section(2, 'B', [], ['stepId' => 10, 'operator' => 'is_true']);

		$result = $this->flow->evaluate([$a, $b], [$control]);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame(['Step 10'], $result['blockedBy'][2]);
	}

	public function testSelectConditionsChooseASingleAlternative(): void {
		$control = $this->step(10, 1, 'SELECT', RunStepStatus::Completed->value, 'blue');
		$blue = $this->section(2, 'Blue', [], ['stepId' => 10, 'operator' => 'equals', 'value' => 'blue']);
		$red = $this->section(3, 'Red', [], ['stepId' => 10, 'operator' => 'equals', 'value' => 'red']);
		$steps = [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
		];

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $blue, $red], $steps);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testNumericConditions(): void {
		$control = $this->step(10, 1, 'NUMBER', RunStepStatus::Completed->value, 80);
		$high = $this->section(2, 'High', [], ['stepId' => 10, 'operator' => 'greater_than', 'value' => 50]);
		$low = $this->section(3, 'Low', [], ['stepId' => 10, 'operator' => 'less_or_equal', 'value' => 50]);
		$steps = [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
		];

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $high, $low], $steps);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testBooleanConditionTrue(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$b = $this->section(2, 'B', [], ['stepId' => 10, 'operator' => 'is_false']);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $b], [$control]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
	}

	public function testSkippedDecisionIsTreatedAsFalse(): void {
		$control = $this->step(10, 1, 'CONFIRMATION', RunStepStatus::Skipped->value);
		$b = $this->section(2, 'B', [], ['stepId' => 10, 'operator' => 'is_true']);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $b], [$control]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
	}

	/**
	 * @param list<int> $dependsOn
	 * @param list<array<string, mixed>> $conditions
	 */
	private function sectionWithConditions(int $id, string $title, array $dependsOn, array $conditions): RunSection {
		$section = new RunSection();
		$section->setId($id);
		$section->setRunId(1);
		$section->setTitle($title);
		$section->setDependsOnIds($dependsOn);
		$section->setConditions($conditions);

		return $section;
	}

	public function testMultipleConditionsFormAnAndGate(): void {
		$choice = $this->step(10, 1, 'SELECT', RunStepStatus::Completed->value, 'blue');
		$amount = $this->step(11, 1, 'NUMBER', RunStepStatus::Completed->value, 80);
		$both = $this->sectionWithConditions(2, 'Both', [], [
			['stepId' => 10, 'operator' => 'equals', 'value' => 'blue'],
			['stepId' => 11, 'operator' => 'greater_than', 'value' => 50],
		]);
		$oneFalse = $this->sectionWithConditions(3, 'OneFalse', [], [
			['stepId' => 10, 'operator' => 'equals', 'value' => 'blue'],
			['stepId' => 11, 'operator' => 'greater_than', 'value' => 90],
		]);
		$steps = [
			$choice,
			$amount,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
		];

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $both, $oneFalse], $steps);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testPendingConditionKeepsTheGateBlockedEvenWhenAnotherMatches(): void {
		$answered = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$open = $this->step(11, 1, 'CHECK', RunStepStatus::Pending->value);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [
			['stepId' => 10, 'operator' => 'is_true'],
			['stepId' => 11, 'operator' => 'is_true'],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$answered, $open]);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame(['Step 11'], $result['blockedBy'][2]);
	}

	public function testConditionsMayReferenceStepsFromDifferentSections(): void {
		$check = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$count = $this->step(20, 2, 'NUMBER', RunStepStatus::Completed->value, 3);
		$final = $this->sectionWithConditions(3, 'Final', [], [
			['stepId' => 10, 'operator' => 'is_true'],
			['stepId' => 20, 'operator' => 'greater_or_equal', 'value' => 3],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $this->section(2, 'Middle'), $final], [
			$check,
			$count,
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testUnansweredResponseDoesNotSatisfyNotEquals(): void {
		$control = $this->step(10, 1, 'SELECT', RunStepStatus::Completed->value, null);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [
			['stepId' => 10, 'operator' => 'not_equals', 'value' => 'red'],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$control]);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
	}

	public function testNotEqualsIsSatisfiedByADifferentValue(): void {
		$control = $this->step(10, 1, 'SELECT', RunStepStatus::Completed->value, 'blue');
		$gated = $this->sectionWithConditions(2, 'Gated', [], [
			['stepId' => 10, 'operator' => 'not_equals', 'value' => 'red'],
		]);
		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$control, $this->step(20, 2, 'TEXT', RunStepStatus::Pending->value)]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testNumericConditionsCoerceStringsAndHandleDecimals(): void {
		$control = $this->step(10, 1, 'NUMBER', RunStepStatus::Completed->value, '2.5');
		$equal = $this->sectionWithConditions(2, 'Equal', [], [
			['stepId' => 10, 'operator' => 'equals', 'value' => 2.5],
		]);
		$less = $this->sectionWithConditions(3, 'Less', [], [
			['stepId' => 10, 'operator' => 'less_than', 'value' => 2.5],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $equal, $less], [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][3]);
	}

	public function testTypeIncompatibleOperatorNeverSatisfiesACondition(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [
			['stepId' => 10, 'operator' => 'greater_than', 'value' => 1],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$control]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
	}

	public function testFinalSectionWithoutConditionsIsAvailable(): void {
		$result = $this->flow->evaluate([$this->section(1, 'Start'), $this->section(2, 'Final')], []);

		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][2]);
		self::assertSame(FlowService::STATE_RESOLVED, $result['states'][1]);
	}

	public function testFinalSectionWithASatisfiedConditionIsAvailable(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$final = $this->sectionWithConditions(2, 'Final', [], [['stepId' => 10, 'operator' => 'is_true']]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $final], [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testFinalSectionWithMultipleSatisfiedConditionsIsAvailable(): void {
		$check = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$amount = $this->step(11, 1, 'NUMBER', RunStepStatus::Completed->value, 10);
		$final = $this->sectionWithConditions(2, 'Final', [], [
			['stepId' => 10, 'operator' => 'is_true'],
			['stepId' => 11, 'operator' => 'less_or_equal', 'value' => 10],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $final], [
			$check,
			$amount,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testFinalSectionWithUnsatisfiedConditionIsNotAvailable(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, false);
		$final = $this->sectionWithConditions(2, 'Final', [], [['stepId' => 10, 'operator' => 'is_true']]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $final], [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
	}

	public function testFinalSectionDependingOnMultipleSectionsBecomesAvailable(): void {
		$first = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$second = $this->step(20, 2, 'CHECK', RunStepStatus::Completed->value, true);
		$final = $this->sectionWithConditions(3, 'Final', [1, 2], [['stepId' => 20, 'operator' => 'is_true']]);

		$result = $this->flow->evaluate([$this->section(1, 'A'), $this->section(2, 'B'), $final], [
			$first,
			$second,
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
	}

	public function testFinalSectionFollowingAnAlternativePath(): void {
		$choice = $this->step(10, 1, 'SELECT', RunStepStatus::Completed->value, 'b');
		$branchA = $this->sectionWithConditions(2, 'Branch A', [], [
			['stepId' => 10, 'operator' => 'equals', 'value' => 'a'],
		]);
		$branchB = $this->sectionWithConditions(3, 'Branch B', [], [
			['stepId' => 10, 'operator' => 'equals', 'value' => 'b'],
		]);
		$final = $this->sectionWithConditions(4, 'Final', [], [['stepId' => 10, 'operator' => 'equals', 'value' => 'b']]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $branchA, $branchB, $final], [
			$choice,
			$this->step(30, 3, 'TEXT', RunStepStatus::Pending->value),
			$this->step(40, 4, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][3]);
		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][4]);
	}

	public function testAvailabilityDoesNotDependOnArrayOrder(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$final = $this->sectionWithConditions(2, 'Final', [1], [['stepId' => 10, 'operator' => 'is_true']]);

		$result = $this->flow->evaluate([$final, $this->section(1, 'Start')], [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testLegacySingleConditionStorageStillEvaluates(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, true);
		$legacy = $this->section(2, 'Legacy', [], ['stepId' => 10, 'operator' => 'is_true']);

		self::assertCount(1, $legacy->getConditions());

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $legacy], [
			$control,
			$this->step(20, 2, 'TEXT', RunStepStatus::Pending->value),
		]);

		self::assertSame(FlowService::STATE_AVAILABLE, $result['states'][2]);
	}

	public function testInapplicableSectionExposesTheFailedConditionReason(): void {
		$control = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, false);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [['stepId' => 10, 'operator' => 'is_true']]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$control]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertSame([[
			'type' => 'condition_false',
			'stepId' => 10,
			'title' => 'Step 10',
			'operator' => 'is_true',
		]], $result['reasons'][2]);
		self::assertArrayNotHasKey(2, $result['blockedBy']);
	}

	public function testInapplicableSectionReportsEveryFailedCondition(): void {
		$first = $this->step(10, 1, 'CHECK', RunStepStatus::Completed->value, false);
		$second = $this->step(11, 1, 'CHECK', RunStepStatus::Completed->value, false);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [
			['stepId' => 10, 'operator' => 'is_true'],
			['stepId' => 11, 'operator' => 'is_true'],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$first, $second]);

		self::assertSame(FlowService::STATE_INAPPLICABLE, $result['states'][2]);
		self::assertCount(2, $result['reasons'][2]);
		self::assertSame('Step 10', $result['reasons'][2][0]['title']);
		self::assertSame('Step 11', $result['reasons'][2][1]['title']);
	}

	public function testReasonExposesOnlyAuthoringMetadataNotTheResponse(): void {
		$control = $this->step(10, 1, 'NUMBER', RunStepStatus::Completed->value, 12.5);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [
			['stepId' => 10, 'operator' => 'greater_than', 'value' => 50.0],
		]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$control]);
		$reason = $result['reasons'][2][0];

		self::assertArrayNotHasKey('response', $reason);
		self::assertArrayNotHasKey('actual', $reason);
		self::assertEquals(50.0, $reason['expected'] ?? null);
		self::assertSame('greater_than', $reason['operator'] ?? null);
	}

	public function testBlockedSectionExplainsPendingCondition(): void {
		$open = $this->step(10, 1, 'CHECK', RunStepStatus::Pending->value);
		$gated = $this->sectionWithConditions(2, 'Gated', [], [['stepId' => 10, 'operator' => 'is_true']]);

		$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [$open]);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame('condition_pending', $result['reasons'][2][0]['type']);
		self::assertSame(['Step 10'], $result['blockedBy'][2]);
	}

	public function testBlockedSectionExplainsUnsatisfiedDependency(): void {
		$result = $this->flow->evaluate([
			$this->section(1, 'Start'),
			$this->section(2, 'Final', [1]),
		], [$this->step(10, 1, 'TEXT', RunStepStatus::Pending->value)]);

		self::assertSame(FlowService::STATE_BLOCKED, $result['states'][2]);
		self::assertSame('dependency', $result['reasons'][2][0]['type']);
		self::assertSame(1, $result['reasons'][2][0]['sectionId'] ?? null);
		self::assertSame('Start', $result['reasons'][2][0]['title']);
		self::assertSame(['Start'], $result['blockedBy'][2]);
	}

	public function testReasonPreservesRawTitlesWithoutHtmlEscaping(): void {
		$titles = ['Aspas "retas"', 'Ampersand & símbolo', '<b>HTML</b> like text'];
		foreach ($titles as $index => $title) {
			$control = new RunStep();
			$control->setId(100 + $index);
			$control->setRunSectionId(1);
			$control->setTitle($title);
			$control->setType('CHECK');
			$control->setStatus(RunStepStatus::Completed->value);
			$control->setResponseValue(false);

			$gated = $this->sectionWithConditions(2, 'Gated', [], [
				['stepId' => 100 + $index, 'operator' => 'is_true'],
			]);

			$result = $this->flow->evaluate([$this->section(1, 'Start'), $gated], [
				$control,
				$this->step(200 + $index, 2, 'TEXT', RunStepStatus::Pending->value),
			]);

			$reason = $result['reasons'][2][0];
			self::assertSame($title, $reason['title']);

			$encoded = json_encode($reason, JSON_THROW_ON_ERROR);
			self::assertIsString($encoded);
			foreach (['&quot;', '&amp;', '&lt;', '&gt;', '&#39;'] as $entity) {
				self::assertStringNotContainsString($entity, $encoded, 'Reason metadata must not be HTML-escaped');
			}
		}
	}
}
