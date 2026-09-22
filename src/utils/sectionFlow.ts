/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure presentation helpers for the flow-state pattern and the template
 * authoring rule summary (issue #26). They only translate and format data the
 * backend already provides — they never derive flow decisions.
 */

import type { translate } from '@nextcloud/l10n'
import type { RunStep, SectionReason } from '../models/run.ts'
import type { ConditionOperator } from '../models/template.ts'

import { reasonFragment, sectionStateLabel } from './sectionReason.ts'

type Translate = typeof translate

export type SectionStatusTone = 'neutral' | 'info' | 'success' | 'warning' | 'muted'

/**
 * Human readable label for a condition operator.
 *
 * @param t Translator.
 * @param operator Condition operator.
 */
export function conditionOperatorLabel(t: Translate, operator: ConditionOperator): string {
	switch (operator) {
		case 'is_true':
			return t('runbook', 'is yes')
		case 'is_false':
			return t('runbook', 'is no')
		case 'equals':
			return t('runbook', 'equals')
		case 'not_equals':
			return t('runbook', 'does not equal')
		case 'greater_than':
			return t('runbook', 'is greater than')
		case 'less_than':
			return t('runbook', 'is less than')
		case 'greater_or_equal':
			return t('runbook', 'is greater or equal')
		case 'less_or_equal':
			return t('runbook', 'is less or equal')
	}
}

export interface ConditionClause {
	stepTitle: string
	operator: ConditionOperator
	value?: string | number | boolean
}

/**
 * Human readable clause for a single condition, e.g. `“Result” equals “3”`.
 *
 * @param t Translator.
 * @param condition Condition clause.
 */
export function conditionClause(t: Translate, condition: ConditionClause): string {
	const operator = conditionOperatorLabel(t, condition.operator)
	if (condition.operator === 'is_true' || condition.operator === 'is_false') {
		return t('runbook', '“{step}” {operator}', { step: condition.stepTitle, operator }, { escape: false, sanitize: false })
	}

	return t('runbook', '“{step}” {operator} “{value}”', {
		step: condition.stepTitle,
		operator,
		value: String(condition.value ?? ''),
	}, { escape: false, sanitize: false })
}

export interface RuleSummaryInput {
	dependsOnTitles: string[]
	conditions: ConditionClause[]
}

/**
 * Human readable summary of a section's authoring rules, e.g.
 * `Opens after “Testing” is complete AND if “Result” equals “3”`.
 *
 * Conditions inside one section are combined with AND; alternative paths come
 * from separate conditional sections, so an OR operator is intentionally not
 * represented.
 *
 * @param t Translator.
 * @param input Section rule input.
 */
export function ruleSummary(t: Translate, input: RuleSummaryInput): string {
	const parts: string[] = []

	if (input.dependsOnTitles.length > 0) {
		const list = input.dependsOnTitles.map((title) => `“${title}”`).join(', ')
		const completion = input.dependsOnTitles.length === 1
			? t('runbook', 'is complete')
			: t('runbook', 'are complete')
		parts.push(`${t('runbook', 'Opens after {sections}', { sections: list }, { escape: false, sanitize: false })} ${completion}`)
	}

	if (input.conditions.length > 0) {
		const clauses = input.conditions
			.map((condition) => t('runbook', 'if {condition}', { condition: conditionClause(t, condition) }, { escape: false, sanitize: false }))
		parts.push(clauses.join(` ${t('runbook', 'AND')} `))
	}

	if (parts.length === 0) {
		return t('runbook', 'Opens immediately')
	}

	return parts.join(` ${t('runbook', 'AND')} `)
}

/**
 * Badge tone for a section state. Tone is supplementary; the label is always
 * rendered so no state relies on colour alone. The tone always follows the
 * backend state, including for zero-step sections.
 *
 * @param state Section state.
 */
export function sectionStatusTone(state: string): SectionStatusTone {
	switch (state) {
		case 'blocked':
			return 'warning'
		case 'inapplicable':
			return 'muted'
		case 'resolved':
			return 'success'
		case 'active':
			return 'info'
		case 'available':
			return 'info'
		default:
			return 'neutral'
	}
}

/**
 * Short status label for a section. The backend state is always the primary
 * label — a zero-step section keeps its real state (Resolved, Blocked,
 * Not applicable) and is explained separately.
 *
 * @param t Translator.
 * @param state Section state.
 */
export function sectionStatusLabel(t: Translate, state: string): string {
	return sectionStateLabel(t, state)
}

export interface SectionStatusInput {
	state: string
	hasSteps: boolean
	completed: number
	skipped: number
	reasons: SectionReason[]
	blockedBy: string[]
}

/**
 * Explanation lines for a section state. Every item is a separate line; a
 * concatenated sentence is never produced. Wording is limited to the data the
 * API provides (state, structured reasons, blocked titles, step counts).
 *
 * A zero-step section is explained separately while keeping its real state:
 * `resolved` shows the information-only notice, while `blocked` and
 * `inapplicable` keep every waiting / condition-failure reason and add the
 * notice.
 *
 * @param t Translator.
 * @param input Section status input.
 */
export function sectionStatusExplanation(t: Translate, input: SectionStatusInput): string[] {
	const noSteps = t('runbook', 'This section contains information but has no steps to complete.')
	const lines: string[] = []

	switch (input.state) {
		case 'available':
			lines.push(t('runbook', 'You can start this section.'))
			break
		case 'active':
			lines.push(t('runbook', 'Continue with the steps in this section.'))
			break
		case 'inapplicable':
			lines.push(t('runbook', 'This section is outside the current path.'))
			for (const reason of input.reasons) {
				if (reason.type === 'condition_false') {
					lines.push(reasonFragment(t, reason))
				}
			}
			break
		case 'blocked':
			for (const reason of input.reasons) {
				if (reason.type === 'dependency' || reason.type === 'condition_pending') {
					lines.push(reasonFragment(t, reason))
				}
			}
			if (lines.length === 0) {
				for (const title of input.blockedBy) {
					lines.push(reasonFragment(t, { type: 'dependency', title }))
				}
			}
			break
		case 'resolved':
			if (input.hasSteps) {
				lines.push(t('runbook', 'All steps are resolved. Completed: {completed}, Skipped: {skipped}.', {
					completed: String(input.completed),
					skipped: String(input.skipped),
				}))
			}
			break
		default:
			break
	}

	if (!input.hasSteps) {
		lines.push(noSteps)
	}

	return lines
}

/**
 * Completed and skipped step counts for a section. Kept next to the
 * explanation helpers so the run detail view and the pattern stay consistent.
 *
 * @param steps Section steps.
 */
export function stepCounts(steps: RunStep[]): { completed: number, skipped: number } {
	let completed = 0
	let skipped = 0
	for (const step of steps) {
		if (step.status === 'COMPLETED') {
			completed += 1
		} else if (step.status === 'SKIPPED') {
			skipped += 1
		}
	}

	return { completed, skipped }
}
