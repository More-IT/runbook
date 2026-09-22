/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend rendering tests for the flow-state pattern and the template rule
 * summary (issue #26). They run on the Node built-in test runner (no extra
 * dependency) and use the real `@nextcloud/l10n` translator with the shipped
 * catalogs, so the exact copy for English, Portuguese (Portugal) and Portuguese
 * (Brazil) is asserted.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { translate } from '@nextcloud/l10n'

import { reasonFragment, sectionStateLabel } from '../../src/utils/sectionReason.ts'
import {
	conditionClause,
	conditionOperatorLabel,
	ruleSummary,
	sectionStatusExplanation,
	sectionStatusLabel,
	sectionStatusTone,
} from '../../src/utils/sectionFlow.ts'

const here = dirname(fileURLToPath(import.meta.url))
const root = join(here, '..', '..')

const catalogs = {}
for (const locale of ['en', 'pt_PT', 'pt_BR']) {
	catalogs[locale] = JSON.parse(readFileSync(join(root, 'l10n', `${locale}.json`), 'utf8')).translations
}

/**
 * Register a locale catalog with the Nextcloud l10n registry.
 *
 * @param {string} locale Locale identifier.
 */
function useLocale(locale) {
	globalThis._oc_l10n_registry_translations ??= {}
	globalThis._oc_l10n_registry_plural_functions ??= {}
	globalThis._oc_l10n_registry_translations.runbook = catalogs[locale]
	globalThis._oc_l10n_registry_plural_functions.runbook = (n) => (n === 1 ? 0 : 1)
}

/**
 * @param {string} state Section state.
 * @param {object} overrides Explanation input overrides.
 * @param {string} locale Locale identifier.
 */
function explanation(state, overrides, locale) {
	useLocale(locale)
	return sectionStatusExplanation(translate, {
		state,
		hasSteps: true,
		completed: 0,
		skipped: 0,
		reasons: [],
		blockedBy: [],
		...overrides,
	})
}

const falseCondition = (title) => ({ type: 'condition_false', title, operator: 'is_true' })
const dependency = (title) => ({ type: 'dependency', title, sectionId: 1 })
const pendingCondition = (title) => ({ type: 'condition_pending', title, stepId: 1 })

test('inapplicable explanation separates the leading copy from each reason', () => {
	assert.deepEqual(
		explanation('inapplicable', { reasons: [falseCondition('Result')] }, 'en'),
		['This section is outside the current path.', 'The condition on “Result” was not satisfied'],
	)
	assert.deepEqual(
		explanation('inapplicable', { reasons: [falseCondition('Result')] }, 'pt_PT'),
		['Esta secção está fora do caminho atual.', 'A condição sobre “Result” não foi satisfeita'],
	)
	assert.deepEqual(
		explanation('inapplicable', { reasons: [falseCondition('Result')] }, 'pt_BR'),
		['Esta secção está fora do caminho atual.', 'A condição sobre “Result” não foi satisfeita'],
	)
})

test('multiple failed conditions render as separate lines, never one sentence', () => {
	const lines = explanation('inapplicable', { reasons: [falseCondition('A'), falseCondition('B')] }, 'en')
	assert.equal(lines.length, 3)
	assert.deepEqual(lines.slice(1), [
		'The condition on “A” was not satisfied',
		'The condition on “B” was not satisfied',
	])
})

test('blocked explanation uses dependency and pending-answer lines', () => {
	assert.deepEqual(
		explanation('blocked', { reasons: [dependency('Testing'), pendingCondition('Approved')] }, 'en'),
		['Waiting for “Testing” to be completed', 'Waiting for an answer to “Approved”'],
	)
	assert.deepEqual(
		explanation('blocked', { reasons: [dependency('Testing')] }, 'pt_PT'),
		['À espera de que “Testing” seja concluída'],
	)
})

test('blocked explanation falls back to blockedBy titles when no structured reason is present', () => {
	assert.deepEqual(
		explanation('blocked', { blockedBy: ['Testing'] }, 'en'),
		['Waiting for “Testing” to be completed'],
	)
})

test('resolved explanation reports completed and skipped steps', () => {
	assert.deepEqual(
		explanation('resolved', { completed: 3, skipped: 1 }, 'en'),
		['All steps are resolved. Completed: 3, Skipped: 1.'],
	)
	assert.deepEqual(
		explanation('resolved', { completed: 3, skipped: 1 }, 'pt_PT'),
		['Todos os passos estão resolvidos. Concluídos: 3, Ignorados: 1.'],
	)
})

test('Portuguese resolved copy is grammatically correct for 0, 1 and many', () => {
	for (const [completed, skipped] of [[0, 0], [1, 0], [0, 1], [3, 1]]) {
		const [line] = explanation('resolved', { completed, skipped }, 'pt_PT')
		assert.equal(line, `Todos os passos estão resolvidos. Concluídos: ${completed}, Ignorados: ${skipped}.`)
		assert.doesNotMatch(line, /\b1 concluídos\b/)
		assert.doesNotMatch(line, /\b1 ignorados\b/)
	}
})

test('a resolved section without steps gets the information-only copy', () => {
	assert.deepEqual(
		explanation('resolved', { hasSteps: false, completed: 0, skipped: 0 }, 'en'),
		['This section contains information but has no steps to complete.'],
	)
	assert.deepEqual(
		explanation('resolved', { hasSteps: false, completed: 0, skipped: 0 }, 'pt_PT'),
		['Esta secção contém informação, mas não tem passos para concluir.'],
	)
})

test('zero-step sections keep their real state and explain the missing steps', () => {
	// blocked: the state stays blocked and every waiting reason is retained.
	assert.deepEqual(
		explanation('blocked', { hasSteps: false, reasons: [dependency('Testing')] }, 'en'),
		['Waiting for “Testing” to be completed', 'This section contains information but has no steps to complete.'],
	)

	// inapplicable: every condition-failure reason is retained.
	assert.deepEqual(
		explanation('inapplicable', { hasSteps: false, reasons: [falseCondition('Result')] }, 'pt_PT'),
		[
			'Esta secção está fora do caminho atual.',
			'A condição sobre “Result” não foi satisfeita',
			'Esta secção contém informação, mas não tem passos para concluir.',
		],
	)

	// The primary label is always the backend state, never a "No steps" badge.
	useLocale('en')
	assert.equal(sectionStatusLabel(translate, 'blocked'), 'Blocked')
	assert.equal(sectionStatusLabel(translate, 'inapplicable'), 'Not applicable')
	assert.equal(sectionStatusLabel(translate, 'resolved'), 'Resolved')
})

test('available and active explanations are translated', () => {
	assert.deepEqual(explanation('available', {}, 'en'), ['You can start this section.'])
	assert.deepEqual(explanation('active', {}, 'pt_PT'), ['Continue com os passos desta secção.'])
})

test('section status label and tone follow the backend state', () => {
	useLocale('en')
	assert.equal(sectionStatusLabel(translate, 'resolved'), 'Resolved')
	assert.equal(sectionStatusLabel(translate, 'blocked'), 'Blocked')
	assert.equal(sectionStatusTone('blocked'), 'warning')
	assert.equal(sectionStatusTone('inapplicable'), 'muted')
	assert.equal(sectionStatusTone('resolved'), 'success')
	assert.equal(sectionStatusTone('available'), 'info')
	assert.equal(sectionStatusTone('active'), 'info')
	assert.equal(sectionStatusTone('something-else'), 'neutral')

	useLocale('pt_PT')
	assert.equal(sectionStatusLabel(translate, 'blocked'), 'Bloqueada')
	assert.equal(sectionStatusLabel(translate, 'resolved'), 'Resolvida')
})

test('tricky titles render verbatim without HTML entities or raw tokens', () => {
	const titles = ['Aspas "retas"', 'Apostrophe O\'Brien', 'Ampersand & Co', '<b>HTML</b> & "text"']
	for (const locale of ['en', 'pt_PT', 'pt_BR']) {
		useLocale(locale)
		for (const title of titles) {
			const rendered = reasonFragment(translate, falseCondition(title))
			assert.ok(rendered.includes(title), `${locale} must include the raw title: ${rendered}`)
			assert.doesNotMatch(rendered, /&quot;|&amp;|&lt;|&gt;|&#39;|&apos;/, `${locale} entity leak: ${rendered}`)
			assert.doesNotMatch(rendered, /\{(step|section|value|sections|operator|condition)\}/, `${locale} token leak: ${rendered}`)
		}
	}
})

test('rule summary reads as a sentence in English and Portuguese', () => {
	useLocale('en')
	assert.equal(ruleSummary(translate, {
		dependsOnTitles: ['Testing'],
		conditions: [{ stepTitle: 'Result', operator: 'equals', value: '3' }],
	}), 'Opens after “Testing” is complete AND if “Result” equals “3”')

	useLocale('pt_PT')
	assert.equal(ruleSummary(translate, {
		dependsOnTitles: ['Testing'],
		conditions: [{ stepTitle: 'Result', operator: 'equals', value: '3' }],
	}), 'Abre depois de “Testing” estar concluída E se “Result” é igual a “3”')

	useLocale('pt_BR')
	assert.equal(ruleSummary(translate, {
		dependsOnTitles: ['Testing'],
		conditions: [{ stepTitle: 'Result', operator: 'equals', value: '3' }],
	}), 'Abre depois de “Testing” estar concluída E se “Result” é igual a “3”')
})

test('rule summary combines conditions with AND and never implies OR', () => {
	useLocale('en')
	const summary = ruleSummary(translate, {
		dependsOnTitles: ['A', 'B'],
		conditions: [
			{ stepTitle: 'Approved', operator: 'is_true' },
			{ stepTitle: 'Amount', operator: 'greater_than', value: 5 },
		],
	})
	assert.equal(summary, 'Opens after “A”, “B” are complete AND if “Approved” is yes AND if “Amount” is greater than “5”')
	assert.doesNotMatch(summary, /\bOR\b/)
})

test('rule summary reports an unconditional section', () => {
	useLocale('en')
	assert.equal(ruleSummary(translate, { dependsOnTitles: [], conditions: [] }), 'Opens immediately')
	useLocale('pt_PT')
	assert.equal(ruleSummary(translate, { dependsOnTitles: [], conditions: [] }), 'Abre automaticamente')
})

test('condition clause and operator labels are translated', () => {
	useLocale('en')
	assert.equal(conditionOperatorLabel(translate, 'equals'), 'equals')
	assert.equal(conditionClause(translate, { stepTitle: 'Approved', operator: 'is_false' }), '“Approved” is no')

	useLocale('pt_PT')
	assert.equal(conditionOperatorLabel(translate, 'equals'), 'é igual a')
	assert.equal(conditionClause(translate, { stepTitle: 'Approved', operator: 'is_false' }), '“Approved” é não')
})

test('the run view and template editor use the shared foundation', () => {
	const runView = readFileSync(join(root, 'src', 'views', 'RunDetailView.vue'), 'utf8')
	assert.ok(runView.includes('<SectionStatus'), 'the run view must render the shared SectionStatus component')

	const runViewReturnIndex = runView.indexOf('v-if="canReturnSection(selectedEntry)"')
	const runViewCollapseIndex = runView.indexOf('v-show="!isCollapsed(selectedEntry)"')
	assert.ok(runViewReturnIndex !== -1 && runViewCollapseIndex !== -1)
	assert.ok(runViewReturnIndex < runViewCollapseIndex, 'the reopen action must stay outside the collapsed wrapper')

	const sectionEditor = readFileSync(join(root, 'src', 'components', 'SectionEditor.vue'), 'utf8')
	assert.ok(sectionEditor.includes('ruleSummary(t'), 'the editor must show the human-readable rule summary')
	assert.ok(sectionEditor.includes('runbook-section-editor__rule'), 'the rule summary must have its own styled element')
})

test('state labels stay translated in both Portuguese catalogs', () => {
	useLocale('pt_PT')
	assert.equal(sectionStateLabel(translate, 'inapplicable'), 'Não aplicável')
	useLocale('pt_BR')
	assert.equal(sectionStateLabel(translate, 'inapplicable'), 'Não aplicável')
})
