<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Enum\StepType;

/**
 * Evaluates the process-flow state of a run snapshot.
 *
 * The flow model is intentionally section-based and purely derived: it never
 * persists extra state, so a template change can never affect a running
 * execution (snapshot isolation). Each section resolves to one of:
 *
 * - `inapplicable` — its condition is false, so the branch is not taken;
 * - `blocked` — a prerequisite section is unresolved, or its controlling
 *   decision step has not been answered yet;
 * - `resolved` — every step is completed or skipped;
 * - `active` — at least one step is in progress;
 * - `available` — actionable but not started.
 *
 * Templates without dependencies or conditions keep the linear behaviour: every
 * section is available/resolved exactly as before.
 *
 * Conditions
 * ----------
 * A section may declare zero or more conditions. Every condition of a section
 * belongs to a single gate and is combined with a logical AND: the section is
 * applicable only when all of its conditions hold. Alternative (OR) branches
 * are represented explicitly by sibling sections carrying mutually exclusive
 * conditions — never by mixing conditions inside one section. A condition that
 * cannot be decided yet (its controlling step is unanswered) keeps the section
 * blocked; a decided-false condition makes the section inapplicable. The
 * decision is independent of the section's position: every section, including
 * the final one, is evaluated with the same algorithm.
 *
 * @phpstan-type SectionReason array{
 *     type: 'condition_false'|'condition_pending'|'dependency',
 *     title: string,
 *     sectionId?: int,
 *     stepId?: int,
 *     operator?: string,
 *     expected?: mixed
 * }
 */
class FlowService {
	public const STATE_BLOCKED = 'blocked';
	public const STATE_AVAILABLE = 'available';
	public const STATE_ACTIVE = 'active';
	public const STATE_INAPPLICABLE = 'inapplicable';
	public const STATE_RESOLVED = 'resolved';

	/**
	 * Operators supported for each step type. This is the single source of truth
	 * shared by template validation and run-snapshot evaluation, so authored and
	 * evaluated conditions can never diverge.
	 *
	 * @return array<string, list<string>>
	 */
	public static function operatorsByType(): array {
		$comparison = ['equals', 'not_equals'];
		$ordered = ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'];

		return [
			StepType::Check->value => ['is_true', 'is_false'],
			StepType::Confirmation->value => ['is_true', 'is_false'],
			StepType::Number->value => $ordered,
			StepType::Select->value => $comparison,
			StepType::Text->value => $comparison,
			StepType::Date->value => $comparison,
			StepType::User->value => $comparison,
		];
	}

	/**
	 * @return list<string>
	 */
	public static function operatorsForType(StepType $type): array {
		return self::operatorsByType()[$type->value] ?? [];
	}

	public static function isOperatorAllowed(StepType $type, string $operator): bool {
		return in_array($operator, self::operatorsForType($type), true);
	}

	/**
	 * @return list<string>
	 */
	public static function states(): array {
		return [
			self::STATE_BLOCKED,
			self::STATE_AVAILABLE,
			self::STATE_ACTIVE,
			self::STATE_INAPPLICABLE,
			self::STATE_RESOLVED,
		];
	}

	/**
	 * Compute the state of every section of a run snapshot.
	 *
	 * @param list<RunSection> $sections
	 * @param list<RunStep> $steps
	 *
	 * @return array{
	 *     states: array<int, string>,
	 *     blockedBy: array<int, list<string>>,
	 *     reasons: array<int, list<SectionReason>>,
	 *     inapplicableStepIds: list<int>,
	 *     blockedStepIds: list<int>
	 * }
	 */
	public function evaluate(array $sections, array $steps): array {
		$sectionsById = [];
		foreach ($sections as $section) {
			$sectionsById[$section->getId()] = $section;
		}

		$stepsById = [];
		$stepsBySection = [];
		foreach ($steps as $step) {
			$stepsById[$step->getId()] = $step;
			$stepsBySection[$step->getRunSectionId()][] = $step;
		}

		/** @var array<int, string> $states */
		$states = [];
		/** @var array<int, list<string>> $blockedBy */
		$blockedBy = [];
		/** @var array<int, list<SectionReason>> $reasons */
		$reasons = [];

		// Process sections in dependency order. A section is evaluated once all
		// of its known prerequisites have been evaluated; the loop is bounded by
		// the number of sections.
		$remaining = count($sections);
		while ($remaining > 0) {
			$progressed = false;
			foreach ($sections as $section) {
				$id = $section->getId();
				if (isset($states[$id])) {
					continue;
				}

				$deps = $section->getDependsOnIds();
				$depsEvaluated = true;
				foreach ($deps as $depId) {
					if (isset($sectionsById[$depId]) && !isset($states[$depId])) {
						$depsEvaluated = false;
						break;
					}
				}
				if (!$depsEvaluated) {
					continue;
				}

				$unsatisfied = [];
				foreach ($deps as $depId) {
					if (!isset($sectionsById[$depId])) {
						continue;
					}
					$depState = $states[$depId];
					if ($depState !== self::STATE_RESOLVED && $depState !== self::STATE_INAPPLICABLE) {
						$unsatisfied[] = [
							'type' => 'dependency',
							'sectionId' => $depId,
							'title' => $sectionsById[$depId]->getTitle(),
						];
					}
				}

				[$state, $sectionReasons] = $this->sectionState($section, $stepsBySection[$id] ?? [], $stepsById, $unsatisfied);
				$states[$id] = $state;
				if ($sectionReasons !== []) {
					$reasons[$id] = $sectionReasons;
				}
				if ($state === self::STATE_BLOCKED) {
					$titles = [];
					foreach ($sectionReasons as $sectionReason) {
						if ($sectionReason['title'] !== '') {
							$titles[] = $sectionReason['title'];
						}
					}
					if ($titles !== []) {
						$blockedBy[$id] = $titles;
					}
				}
				$progressed = true;
				$remaining--;
			}

			if (!$progressed) {
				// Defensive: an unexpected cycle. Mark the rest as blocked.
				foreach ($sections as $section) {
					if (!isset($states[$section->getId()])) {
						$states[$section->getId()] = self::STATE_BLOCKED;
						$remaining--;
					}
				}
			}
		}

		$inapplicableStepIds = [];
		$blockedStepIds = [];
		foreach ($steps as $step) {
			$state = $states[$step->getRunSectionId()] ?? self::STATE_AVAILABLE;
			if ($state === self::STATE_INAPPLICABLE) {
				$inapplicableStepIds[] = $step->getId();
			} elseif ($state === self::STATE_BLOCKED) {
				$blockedStepIds[] = $step->getId();
			}
		}

		return [
			'states' => $states,
			'blockedBy' => $blockedBy,
			'reasons' => $reasons,
			'inapplicableStepIds' => $inapplicableStepIds,
			'blockedStepIds' => $blockedStepIds,
		];
	}

	/**
	 * @param list<RunStep> $sectionSteps
	 * @param array<int, RunStep> $stepsById
	 * @param list<SectionReason> $unsatisfiedDependencies
	 *
	 * @return array{0: string, 1: list<SectionReason>}
	 */
	private function sectionState(RunSection $section, array $sectionSteps, array $stepsById, array $unsatisfiedDependencies): array {
		$conditions = $section->getConditions();
		if ($conditions !== []) {
			[$decision, $conditionReasons] = $this->evaluateConditions($conditions, $stepsById);
			if ($decision === false) {
				return [self::STATE_INAPPLICABLE, $conditionReasons];
			}
			if ($decision === null) {
				return [self::STATE_BLOCKED, $conditionReasons];
			}
		}

		if ($unsatisfiedDependencies !== []) {
			return [self::STATE_BLOCKED, $unsatisfiedDependencies];
		}

		if ($sectionSteps === []) {
			return [self::STATE_RESOLVED, []];
		}

		$hasInProgress = false;
		$allResolved = true;
		foreach ($sectionSteps as $step) {
			$status = RunStepStatus::tryFrom($step->getStatus());
			if ($status === RunStepStatus::InProgress) {
				$hasInProgress = true;
			}
			if ($status !== RunStepStatus::Completed && $status !== RunStepStatus::Skipped) {
				$allResolved = false;
			}
		}

		if ($allResolved) {
			return [self::STATE_RESOLVED, []];
		}
		if ($hasInProgress) {
			return [self::STATE_ACTIVE, []];
		}

		return [self::STATE_AVAILABLE, []];
	}

	/**
	 * AND-combine every condition of a single section gate.
	 *
	 * Returns `false` as soon as one condition is decided false, `null` while at
	 * least one condition is undecided (and none is false), otherwise `true`.
	 * The second element explains the outcome: the failed conditions for
	 * `inapplicable`, or the still-unanswered controlling steps for `blocked`.
	 * Every failed / pending condition is reported, so multiple reasons are
	 * supported. Only authoring metadata (step title, operator, expected value)
	 * is exposed — never the user's stored response.
	 *
	 * @param list<array<string, mixed>> $conditions
	 * @param array<int, RunStep> $stepsById
	 * @return array{0: bool|null, 1: list<SectionReason>}
	 */
	private function evaluateConditions(array $conditions, array $stepsById): array {
		$failed = [];
		$pending = [];
		foreach ($conditions as $condition) {
			$evaluated = $this->evaluateCondition($condition, $stepsById);
			if ($evaluated === false) {
				$failed[] = $this->conditionReason('condition_false', $condition, $stepsById);
			} elseif ($evaluated === null) {
				$pending[] = $this->conditionReason('condition_pending', $condition, $stepsById);
			}
		}

		if ($failed !== []) {
			return [false, $failed];
		}
		if ($pending !== []) {
			return [null, $pending];
		}

		return [true, []];
	}

	/**
	 * Build the safe, structured explanation for one condition.
	 *
	 * @param 'condition_false'|'condition_pending' $type
	 * @param array<string, mixed> $condition
	 * @param array<int, RunStep> $stepsById
	 * @return SectionReason
	 */
	private function conditionReason(string $type, array $condition, array $stepsById): array {
		$stepId = (int)($condition['stepId'] ?? 0);
		$reason = [
			'type' => $type,
			'stepId' => $stepId,
			'title' => isset($stepsById[$stepId]) ? $stepsById[$stepId]->getTitle() : '',
			'operator' => (string)($condition['operator'] ?? ''),
		];
		if (array_key_exists('value', $condition)) {
			/** @psalm-suppress MixedAssignment JSON condition values are intentionally untyped. */
			$reason['expected'] = $condition['value'];
		}

		return $reason;
	}

	/**
	 * Evaluate a single section condition against the snapshot responses.
	 *
	 * @param array<string, mixed> $condition
	 * @param array<int, RunStep> $stepsById
	 * @return bool|null true/false when decided, null while waiting for the
	 *                   controlling step to be answered.
	 */
	private function evaluateCondition(array $condition, array $stepsById): ?bool {
		$stepId = (int)($condition['stepId'] ?? 0);
		$operator = (string)($condition['operator'] ?? '');
		$step = $stepsById[$stepId] ?? null;
		if ($step === null) {
			return false;
		}

		$status = RunStepStatus::tryFrom($step->getStatus());
		if ($status === RunStepStatus::Skipped) {
			return false;
		}
		if ($status !== RunStepStatus::Completed) {
			return null;
		}

		$response = $step->getResponseValue();
		// An unanswered response never satisfies — and never refutes — a
		// condition: it keeps the gate pending, so `not_equals` cannot turn true
		// merely because the value is missing.
		if ($response === null || $response === '') {
			return null;
		}

		return $this->compare(StepType::tryFrom($step->getType()), $operator, $condition['value'] ?? null, $response);
	}

	/**
	 * @param StepType|null $type
	 * @param mixed $expected
	 * @param mixed $actual
	 */
	private function compare(?StepType $type, string $operator, mixed $expected, mixed $actual): bool {
		if ($type === null) {
			return false;
		}

		if ($type === StepType::Check || $type === StepType::Confirmation) {
			if (!is_bool($actual)) {
				return false;
			}

			return match ($operator) {
				'is_true' => $actual === true,
				'is_false' => $actual === false,
				default => false,
			};
		}

		if ($type === StepType::Number) {
			if (!is_numeric($expected) || !is_numeric($actual)) {
				return false;
			}
			$expectedNumber = (float)$expected;
			$actualNumber = (float)$actual;

			return match ($operator) {
				'equals' => $this->numbersEqual($actualNumber, $expectedNumber),
				'not_equals' => !$this->numbersEqual($actualNumber, $expectedNumber),
				'greater_than' => $actualNumber > $expectedNumber,
				'less_than' => $actualNumber < $expectedNumber,
				'greater_or_equal' => $actualNumber > $expectedNumber || $this->numbersEqual($actualNumber, $expectedNumber),
				'less_or_equal' => $actualNumber < $expectedNumber || $this->numbersEqual($actualNumber, $expectedNumber),
				default => false,
			};
		}

		// Text-like responses (SELECT, TEXT, DATE, USER).
		if (!is_string($expected) || !is_string($actual)) {
			return false;
		}

		return match ($operator) {
			'equals' => $actual === $expected,
			'not_equals' => $actual !== $expected,
			default => false,
		};
	}

	/**
	 * Tolerant float equality, so decimal values coerced from strings compare
	 * deterministically (e.g. "2.5" vs 2.5, 0.1 + 0.2 vs 0.3).
	 */
	private function numbersEqual(float $left, float $right): bool {
		return abs($left - $right) < 1e-9;
	}
}
