/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the template authoring helpers (issue #28): section
 * outline, focus/selection rules, condition draft validation, unsaved-draft
 * comparison and rule-summary presentation.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { translate } from '@nextcloud/l10n'

import { ruleSummary } from '../../src/utils/sectionFlow.ts'
import {
	conditionStepOptions,
	defaultSelectedSectionId,
	descriptionPreview,
	effectiveEditorPermissions,
	hasSection,
	incompleteConditionRows,
	isConditionRowIncomplete,
	isSectionDraftDirty,
	neighborSectionId,
	savedConditions,
	sectionOutline,
} from '../../src/utils/templateAuthoring.ts'

const here = dirname(fileURLToPath(import.meta.url))
const root = join(here, '..', '..')

const catalogs = {}
for (const locale of ['en', 'pt_PT']) {
	catalogs[locale] = JSON.parse(readFileSync(join(root, 'l10n', `${locale}.json`), 'utf8')).translations
}

/**
 * @param {string} locale Locale identifier.
 */
function useLocale(locale) {
	globalThis._oc_l10n_registry_translations ??= {}
	globalThis._oc_l10n_registry_plural_functions ??= {}
	globalThis._oc_l10n_registry_translations.runbook = catalogs[locale]
	globalThis._oc_l10n_registry_plural_functions.runbook = (n) => (n === 1 ? 0 : 1)
}

/**
 * @param {number} id Step id.
 * @param {string} type Step type.
 * @param {object} config Step config.
 */
function step(id, type = 'CHECK', config = {}) {
	return { id, sectionId: 0, uuid: `u${id}`, title: `Step ${id}`, description: '', type, required: false, position: id, config, defaultAssignee: null, dueOffset: null }
}

/**
 * @param {number} id Section id.
 * @param {string} title Section title.
 * @param {Array<object>} steps Section steps.
 * @param {object} overrides Section overrides.
 */
function section(id, title, steps = [], overrides = {}) {
	return {
		section: { id, templateId: 1, title, description: '', notes: '', position: id, dependsOn: [], condition: null, conditions: [], ...overrides },
		steps,
	}
}

test('section outline numbers sections and reports step counts', () => {
	const outline = sectionOutline([
		section(1, 'Start', [step(11), step(12)]),
		section(2, 'Verify', []),
		section(3, 'Ship', [step(31)]),
	])

	assert.deepEqual(outline, [
		{ id: 1, position: 1, title: 'Start', stepCount: 2, hasSteps: true },
		{ id: 2, position: 2, title: 'Verify', stepCount: 0, hasSteps: false },
		{ id: 3, position: 3, title: 'Ship', stepCount: 1, hasSteps: true },
	])
})

test('outline scales to many sections without positional logic', () => {
	const sections = []
	for (let index = 1; index <= 25; index += 1) {
		sections.push(section(index, `Section ${index}`, index % 2 === 0 ? [] : [step(index * 10)]))
	}
	const outline = sectionOutline(sections)

	assert.equal(outline.length, 25)
	assert.equal(outline[0].position, 1)
	assert.equal(outline[24].position, 25)
	assert.equal(outline[24].stepCount, 1)
	assert.equal(outline[1].hasSteps, false)
})

test('default selection is the first section, or null when empty', () => {
	assert.equal(defaultSelectedSectionId([section(5, 'A'), section(6, 'B')]), 5)
	assert.equal(defaultSelectedSectionId([]), null)
})

test('deleting a section selects its next neighbour, then the previous one', () => {
	const sections = [section(1, 'A'), section(2, 'B'), section(3, 'C')]
	assert.equal(neighborSectionId(sections, 2), 3)
	assert.equal(neighborSectionId(sections, 3), 2)
	assert.equal(neighborSectionId([section(1, 'Only')], 1), null)
})

test('hasSection validates a focus target', () => {
	const sections = [section(1, 'A')]
	assert.equal(hasSection(sections, 1), true)
	assert.equal(hasSection(sections, 2), false)
	assert.equal(hasSection(sections, null), false)
})

test('condition step options exclude the section itself and disambiguate by parent', () => {
	const sections = [
		section(1, 'Config', [step(11, 'SELECT', { options: ['a', 'b'] })]),
		section(2, 'Verify', [step(21, 'NUMBER')]),
		section(3, 'Outcome', [step(31)]),
	]
	const options = conditionStepOptions(sections, 3)

	assert.deepEqual(options.map((option) => option.id), [11, 21])
	assert.equal(options[0].label, 'Config · Step 11')
	assert.equal(options[0].options.length, 2)
	assert.equal(options[1].label, 'Verify · Step 21')
})

test('incomplete condition rows are detected, never silently dropped', () => {
	const options = conditionStepOptions([section(1, 'Config', [step(11, 'SELECT', { options: ['a'] })]), section(2, 'Edit', [])], 2)
	const byId = new Map(options.map((option) => [option.id, option]))

	assert.equal(isConditionRowIncomplete({ stepId: null, operator: 'equals', value: '' }, undefined), true)
	assert.equal(isConditionRowIncomplete({ stepId: 11, operator: 'equals', value: '' }, byId.get(11)), true)
	assert.equal(isConditionRowIncomplete({ stepId: 11, operator: 'equals', value: 'a' }, byId.get(11)), false)

	const rows = [
		{ stepId: 11, operator: 'equals', value: '' },
		{ stepId: 11, operator: 'equals', value: 'a' },
	]
	assert.deepEqual(incompleteConditionRows(rows, options), [0])
})

test('boolean condition rows need no comparison value', () => {
	const options = conditionStepOptions([section(1, 'Config', [step(11, 'CHECK')]), section(2, 'Edit', [])], 2)
	const byId = new Map(options.map((option) => [option.id, option]))
	assert.equal(isConditionRowIncomplete({ stepId: 11, operator: 'is_true', value: '' }, byId.get(11)), false)
	assert.deepEqual(incompleteConditionRows([{ stepId: 11, operator: 'is_true', value: '' }], options), [])
})

test('draft-vs-saved comparison drives unsaved-change protection', () => {
	const saved = section(1, 'Start', [step(11)], { dependsOn: [2, 3], conditions: [{ stepId: 11, operator: 'is_true' }] }).section
	const base = { title: 'Start', description: '', notes: '', dependsOn: [2, 3], conditions: [{ stepId: 11, operator: 'is_true' }] }

	assert.equal(isSectionDraftDirty(base, saved), false)
	assert.equal(isSectionDraftDirty({ ...base, dependsOn: [3, 2] }, saved), false, 'dependency order must not count as a change')
	assert.equal(isSectionDraftDirty({ ...base, title: 'Start here' }, saved), true)
	assert.equal(isSectionDraftDirty({ ...base, conditions: [{ stepId: 11, operator: 'is_false' }] }, saved), true)
})

test('saved conditions fall back to the legacy single-condition shape', () => {
	assert.deepEqual(savedConditions(section(1, 'A', [], { condition: { stepId: 9, operator: 'is_true' } }).section), [{ stepId: 9, operator: 'is_true' }])
	assert.deepEqual(savedConditions(section(1, 'A', [], { conditions: [{ stepId: 8, operator: 'equals', value: 'x' }] }).section), [{ stepId: 8, operator: 'equals', value: 'x' }])
})

test('description preview truncates long text', () => {
	assert.equal(descriptionPreview('  Short  '), 'Short')
	const long = 'a'.repeat(200)
	assert.equal(descriptionPreview(long).length, 141)
	assert.ok(descriptionPreview(long).endsWith('…'))
})

test('rule summary presents dependencies and AND conditions without implying OR', () => {
	useLocale('en')
	const summary = ruleSummary(translate, {
		dependsOnTitles: ['Testing'],
		conditions: [{ stepTitle: 'Result', operator: 'equals', value: '3' }],
	})
	assert.equal(summary, 'Opens after “Testing” is complete AND if “Result” equals “3”')
	assert.doesNotMatch(summary, /\bOR\b/)

	useLocale('pt_PT')
	assert.equal(ruleSummary(translate, { dependsOnTitles: [], conditions: [] }), 'Abre automaticamente')
})

test('zero-step and selection wording is present in the catalogs', () => {
	for (const key of ['No steps', '{count} steps', 'Editing', 'Select a section to edit', 'Discard unsaved changes', 'You have unsaved changes. Discard them?']) {
		assert.ok(catalogs.en[key], `en missing ${key}`)
		assert.ok(catalogs.pt_PT[key], `pt_PT missing ${key}`)
	}
})

test('the editor uses the section outline and grouped rule authoring', () => {
	const editor = readFileSync(join(root, 'src', 'views', 'TemplateEditorView.vue'), 'utf8')
	assert.ok(editor.includes('<SectionOutline'), 'the editor must render the section outline')
	assert.ok(editor.includes('conditionStepOptions('), 'the editor must derive disambiguated condition options')
	assert.ok(editor.includes('@update:dirty="sectionDirty = $event"'), 'unsaved changes must be tracked')

	const sectionEditor = readFileSync(join(root, 'src', 'components', 'SectionEditor.vue'), 'utf8')
	assert.ok(sectionEditor.includes("t('runbook', 'Basics')"), 'the editor must group fields into Basics')
	assert.ok(sectionEditor.includes("t('runbook', 'Opening rules')"), 'the editor must group Opening rules')
	assert.ok(sectionEditor.includes('incompleteConditionRows'), 'the editor must block incomplete condition rows')
})

test('effective permissions make an archived template immediately read-only', () => {
	const server = { role: 'OWNER', canView: true, canExecute: true, canEdit: true, canManageAcl: true, canDelete: true }

	const archived = effectiveEditorPermissions(server, 'ARCHIVED')
	assert.equal(archived.canEdit, false)
	assert.equal(archived.canPublish, false)
	assert.equal(archived.canArchive, false)
	assert.equal(archived.canStartRun, false)
	assert.equal(archived.canDelete, true, 'delete stays governed by server authorization')

	const draft = effectiveEditorPermissions(server, 'DRAFT')
	assert.equal(draft.canEdit, true)
	assert.equal(draft.canPublish, true)
	assert.equal(draft.canArchive, true)
	assert.equal(draft.canStartRun, false)

	const published = effectiveEditorPermissions(server, 'PUBLISHED')
	assert.equal(published.canPublish, false)
	assert.equal(published.canArchive, true)
	assert.equal(published.canStartRun, true)
})

test('viewer permissions expose no editing actions', () => {
	const viewer = { role: 'VIEWER', canView: true, canExecute: false, canEdit: false, canManageAcl: false, canDelete: false }
	const result = effectiveEditorPermissions(viewer, 'DRAFT')

	assert.equal(result.canEdit, false)
	assert.equal(result.canPublish, false)
	assert.equal(result.canArchive, false)
	assert.equal(result.canStartRun, false)
	assert.equal(result.canDelete, false)
})
