/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend rendering tests for section flow states and reasons. They run on the
 * Node built-in test runner (no extra dependency) and use the real
 * `@nextcloud/l10n` translator with the shipped catalogs, so the exact rendered
 * output for English, Portuguese (Portugal) and Portuguese (Brazil) is asserted.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { translate } from '@nextcloud/l10n'

import {
	inapplicableReasonText,
	sectionStateLabel,
	STATUS_REASON_SEPARATOR,
} from '../../src/utils/sectionReason.ts'

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
 * Render the section status/reason line exactly like the run detail view does.
 *
 * @param {string} state Section state.
 * @param {Array<object>} reasons Structured section reasons.
 * @param {string} locale Locale identifier.
 */
function render(state, reasons, locale) {
	useLocale(locale)
	const label = sectionStateLabel(translate, state)
	if (state !== 'inapplicable') {
		return label
	}
	return `${label}${STATUS_REASON_SEPARATOR}${translate('runbook', 'Reason:')} ${inapplicableReasonText(translate, { reason: reasons })}`
}

const falseCondition = (title) => ({ type: 'condition_false', title, operator: 'is_true' })

test('English inapplicable reason renders exactly', () => {
	assert.equal(
		render('inapplicable', [falseCondition('teste')], 'en'),
		'Not applicable — Reason: The condition on “teste” is not satisfied',
	)
})

test('Portuguese (Portugal) inapplicable reason renders exactly', () => {
	assert.equal(
		render('inapplicable', [falseCondition('teste')], 'pt_PT'),
		'Não aplicável — Motivo: A condição sobre “teste” não é satisfeita',
	)
})

test('Portuguese (Brazil) inapplicable reason renders exactly', () => {
	assert.equal(
		render('inapplicable', [falseCondition('teste')], 'pt_BR'),
		'Não aplicável — Motivo: A condição sobre “teste” não é satisfeita',
	)
})

test('multiple failed conditions are separated readably', () => {
	assert.equal(
		render('inapplicable', [falseCondition('a'), falseCondition('b')], 'pt_PT'),
		'Não aplicável — Motivo: A condição sobre “a” não é satisfeita; A condição sobre “b” não é satisfeita',
	)
})

test('state labels are translated', () => {
	const expected = {
		en: { available: 'Available', blocked: 'Blocked', active: 'In progress', inapplicable: 'Not applicable', resolved: 'Resolved' },
		pt_PT: { available: 'Disponível', blocked: 'Bloqueada', active: 'Em curso', inapplicable: 'Não aplicável', resolved: 'Resolvida' },
		pt_BR: { available: 'Disponível', blocked: 'Bloqueada', active: 'Em curso', inapplicable: 'Não aplicável', resolved: 'Resolvida' },
	}
	for (const [locale, states] of Object.entries(expected)) {
		useLocale(locale)
		for (const [state, label] of Object.entries(states)) {
			assert.equal(sectionStateLabel(translate, state), label, `${locale}/${state}`)
		}
	}
})

test('tricky step titles render verbatim without HTML entities', () => {
	const titles = ['Aspas "retas"', 'Apostrophe O\'Brien', 'Ampersand & Co', '<b>HTML</b> & "text"']
	for (const locale of ['en', 'pt_PT', 'pt_BR']) {
		for (const title of titles) {
			const rendered = render('inapplicable', [falseCondition(title)], locale)
			assert.ok(rendered.includes(title), `${locale} must include the raw title: ${rendered}`)
			assert.doesNotMatch(rendered, /&quot;|&amp;|&lt;|&gt;|&#39;|&apos;/, `${locale} must not contain HTML entities: ${rendered}`)
			assert.doesNotMatch(rendered, /\{step\}|\{section\}|\{reasons\}|\{sections\}/, `${locale} must not leak raw tokens: ${rendered}`)
		}
	}
})

test('the component uses the shared reason helper and shows reopen when collapsed', () => {
	const source = readFileSync(join(root, 'src', 'views', 'RunDetailView.vue'), 'utf8')
	assert.ok(source.includes('inapplicableReasonText(t, entry)'), 'reason must be rendered through the shared helper')
	assert.ok(source.includes('sectionStateLabel(t, entry.state)'), 'state label must use the shared helper')
	assert.ok(source.includes("t('runbook', 'Reopen section')"), 'the reopen action must use the Reopen section label')

	const returnIndex = source.indexOf('v-if="canReturnSection(entry)"')
	const collapseIndex = source.indexOf('v-show="!isCollapsed(entry)"')
	assert.ok(returnIndex !== -1 && collapseIndex !== -1, 'both the reopen action and the collapsed wrapper must exist')
	assert.ok(returnIndex < collapseIndex, 'the reopen action must sit outside the collapsed wrapper so it stays visible')
})

test('action button labels are translated in both Portuguese catalogs', () => {
	assert.equal(catalogs.pt_PT['Reopen section'], 'Reabrir secção')
	assert.equal(catalogs.pt_BR['Reopen section'], 'Reabrir secção')
	assert.equal(catalogs.en['Reopen section'], 'Reopen section')
	for (const key of ['Reopen step', 'Reopen run', 'Confirm return', 'Return reason']) {
		assert.ok(catalogs.en[key], `en missing ${key}`)
		assert.ok(catalogs.pt_PT[key], `pt_PT missing ${key}`)
		assert.ok(catalogs.pt_BR[key], `pt_BR missing ${key}`)
	}
})
