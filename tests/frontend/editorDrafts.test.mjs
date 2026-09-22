/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behavioural tests for the template editor's draft/discard model (issue #28).
 *
 * These drive the exact state transitions TemplateEditorView uses for step
 * drafts, scoped discards, save success/failure and the read-only mode, so the
 * confirmation logic is tested behaviourally rather than by source text. They
 * are not mounted-component tests (no component test framework in the repo).
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
	applyStepSaveResult,
	discardDrafts,
	hasBlockingDrafts,
	intentScope,
	isStepDraftDirty,
	normalizeStepConfig,
} from '../../src/utils/editorDrafts.ts'

const here = dirname(fileURLToPath(import.meta.url))
const root = join(here, '..', '..')

/**
 * @param {object} overrides Draft flags.
 */
function drafts(overrides = {}) {
	return { metadataDirty: false, sectionDirty: false, stepDirty: false, ...overrides }
}

/**
 * @param {object} overrides Step overrides.
 */
function savedStep(overrides = {}) {
	return {
		id: 11,
		sectionId: 1,
		uuid: 'u11',
		title: 'Check the result',
		description: 'Look closely',
		type: 'TEXT',
		required: false,
		position: 0,
		config: {},
		defaultAssignee: null,
		dueOffset: null,
		...overrides,
	}
}

test('switching sections warns only about mounted section/step drafts', () => {
	// A metadata-only change must not trigger a discard prompt when switching.
	assert.equal(hasBlockingDrafts(drafts({ metadataDirty: true }), intentScope('switch')), false)
	// A section or step draft would be lost, so it must.
	assert.equal(hasBlockingDrafts(drafts({ sectionDirty: true }), intentScope('switch')), true)
	assert.equal(hasBlockingDrafts(drafts({ stepDirty: true }), intentScope('switch')), true)
})

test('lifecycle actions consider every visible edit', () => {
	assert.equal(hasBlockingDrafts(drafts({ metadataDirty: true }), intentScope('publish')), true)
	assert.equal(hasBlockingDrafts(drafts({ sectionDirty: true }), intentScope('publish')), true)
	assert.equal(hasBlockingDrafts(drafts({ stepDirty: true }), intentScope('publish')), true)
	assert.equal(hasBlockingDrafts(drafts(), intentScope('publish')), false)

	for (const type of ['run', 'archive', 'delete', 'close']) {
		assert.equal(hasBlockingDrafts(drafts({ metadataDirty: true }), intentScope(type)), true)
	}
})

test('opening another step while a step draft exists requires a discard decision', () => {
	// Step draft present, opening a different step is step-scoped.
	assert.equal(hasBlockingDrafts(drafts({ stepDirty: true }), intentScope('step')), true)
	// A section draft or metadata draft must not block opening another step.
	assert.equal(hasBlockingDrafts(drafts({ sectionDirty: true }), intentScope('step')), false)
	assert.equal(hasBlockingDrafts(drafts({ metadataDirty: true }), intentScope('step')), false)
})

test('opening another step discards only the step draft', () => {
	const outcome = discardDrafts(drafts({ metadataDirty: true, sectionDirty: true, stepDirty: true }), 'step')

	assert.deepEqual(outcome.state, { metadataDirty: true, sectionDirty: true, stepDirty: false })
	assert.equal(outcome.resetSectionDraft, false, 'the mounted section form must keep its draft')
	assert.equal(outcome.resetMetadataDraft, false)
	assert.equal(outcome.closeStepEditor, true)
})

test('combined section and step drafts behave correctly on a step switch', () => {
	const state = drafts({ sectionDirty: true, stepDirty: true })
	assert.equal(hasBlockingDrafts(state, intentScope('step')), true)

	// Confirm: only the step draft is cleared; the section draft survives.
	const confirmed = discardDrafts(state, intentScope('step'))
	assert.equal(confirmed.state.sectionDirty, true)
	assert.equal(confirmed.state.stepDirty, false)
	assert.equal(hasBlockingDrafts(confirmed.state, intentScope('step')), false)

	// Cancel: nothing changes, so both drafts are preserved and a later switch
	// still warns.
	assert.deepEqual(state, { metadataDirty: false, sectionDirty: true, stepDirty: true })
})

test('section-scoped discard clears section and step drafts but keeps metadata', () => {
	const outcome = discardDrafts(drafts({ metadataDirty: true, sectionDirty: true, stepDirty: true }), 'section')

	assert.deepEqual(outcome.state, { metadataDirty: true, sectionDirty: false, stepDirty: false })
	assert.equal(outcome.resetSectionDraft, true)
	assert.equal(outcome.closeStepEditor, true)
	assert.equal(outcome.resetMetadataDraft, false)
	// After discarding, a second prompt cannot fire for the cleared drafts.
	assert.equal(hasBlockingDrafts(outcome.state, 'section'), false)
})

test('full discard clears every named draft', () => {
	const outcome = discardDrafts(drafts({ metadataDirty: true, sectionDirty: true, stepDirty: true }), 'all')

	assert.deepEqual(outcome.state, { metadataDirty: false, sectionDirty: false, stepDirty: false })
	assert.equal(outcome.resetMetadataDraft, true)
	assert.equal(outcome.resetSectionDraft, true)
	assert.equal(outcome.closeStepEditor, true)
	assert.equal(hasBlockingDrafts(outcome.state, 'all'), false)
})

test('a successful step save closes the editor and clears the draft', () => {
	assert.deepEqual(applyStepSaveResult(11, true, null), { editingStepId: null, error: null })
})

test('a failed step save keeps the editor and draft with an error', () => {
	assert.deepEqual(applyStepSaveResult(11, false, 'Could not save'), { editingStepId: 11, error: 'Could not save' })
})

test('step draft changes are detected across type-specific fields', () => {
	const selectStep = savedStep({ type: 'SELECT', config: { options: ['dev', 'prod'] } })
	assert.equal(isStepDraftDirty({ title: 'Check the result', description: 'Look closely', type: 'SELECT', required: false, config: { options: ['dev', 'prod'] } }, selectStep), false)
	assert.equal(isStepDraftDirty({ title: 'Check the result', description: 'Look closely', type: 'SELECT', required: false, config: { options: ['dev'] } }, selectStep), true)

	const numberStep = savedStep({ type: 'NUMBER', config: { unit: 'kg' } })
	assert.equal(isStepDraftDirty({ title: 'Check the result', description: 'Look closely', type: 'NUMBER', required: false, config: { unit: 'kg' } }, numberStep), false)
	assert.equal(isStepDraftDirty({ title: 'Check the result', description: 'Look closely', type: 'NUMBER', required: false, config: { unit: 'lb' } }, numberStep), true)

	// Whitespace-only differences in options/unit are not changes.
	assert.equal(isStepDraftDirty({ title: 'Check the result', description: 'Look closely', type: 'NUMBER', required: false, config: { unit: '  kg  ' } }, numberStep), false)
})

test('step draft changes cover title, required, assignee and due offset', () => {
	const step = savedStep({ required: true, defaultAssignee: 'principals/users/alice', dueOffset: '60' })
	assert.equal(isStepDraftDirty({ title: 'Renamed' }, step), true)
	assert.equal(isStepDraftDirty({ title: 'Check the result', required: false }, step), true)
	assert.equal(isStepDraftDirty({ title: 'Check the result', defaultAssignee: null }, step), true)
	assert.equal(isStepDraftDirty({ title: 'Check the result', dueOffset: null }, step), true)
	assert.equal(isStepDraftDirty({ title: 'Check the result', description: 'Look closely', required: true, defaultAssignee: 'principals/users/alice', dueOffset: '60' }, step), false)
})

test('normalizeStepConfig ignores unrelated fields', () => {
	assert.deepEqual(normalizeStepConfig({ options: [' a ', '', 'b'] }, 'SELECT'), { options: ['a', 'b'] })
	assert.deepEqual(normalizeStepConfig({ unit: ' kg ' }, 'NUMBER'), { unit: 'kg' })
	assert.deepEqual(normalizeStepConfig({ options: ['a'] }, 'TEXT'), {})
})

test('a realistic discard sequence never prompts twice', () => {
	// Edit a step, try to switch section: prompt; confirm discard.
	let state = drafts({ stepDirty: true })
	assert.equal(hasBlockingDrafts(state, intentScope('switch')), true)
	const confirmed = discardDrafts(state, intentScope('switch'))
	state = confirmed.state
	// The switch continues; the new section has no drafts.
	assert.equal(hasBlockingDrafts(state, intentScope('switch')), false)

	// Edit metadata and a section, then publish: prompt; confirm clears all.
	state = drafts({ metadataDirty: true, sectionDirty: true })
	assert.equal(hasBlockingDrafts(state, intentScope('publish')), true)
	state = discardDrafts(state, intentScope('publish')).state
	assert.equal(hasBlockingDrafts(state, intentScope('publish')), false)
})

test('the step editor exposes no toggle chevron and threads read-only state', () => {
	const source = readFileSync(join(root, 'src', 'components', 'StepEditor.vue'), 'utf8')

	assert.ok(!source.includes('runbook-step__toggle'), 'the misleading expansion control must be gone')
	assert.ok(!source.includes('aria-expanded'), 'no dangling aria-expanded without content')
	assert.ok(source.includes('canEdit'), 'read-only state must be threaded into the step editor')
	assert.ok(source.includes("t('runbook', 'Edit step')"), 'a single clear edit action must be present')
})

test('the step dirty event chain is wired StepEditor -> SectionEditor -> page', () => {
	// Regression guard: remove any forwarding link and this fails. There is no
	// mounted-component harness in the repository, so the wiring is asserted on
	// the SFC templates (see the test limitation note in the report).
	const stepEditor = readFileSync(join(root, 'src', 'components', 'StepEditor.vue'), 'utf8')
	const sectionEditor = readFileSync(join(root, 'src', 'components', 'SectionEditor.vue'), 'utf8')
	const view = readFileSync(join(root, 'src', 'views', 'TemplateEditorView.vue'), 'utf8')

	assert.ok(stepEditor.includes("emit('update:dirty'"), 'StepEditor must emit update:dirty')
	assert.ok(sectionEditor.includes('stepDirty: [dirty: boolean]'), 'SectionEditor must declare a stepDirty emit')
	assert.ok(sectionEditor.includes('@update:dirty="emit(\'stepDirty\', $event)"'), 'SectionEditor must forward StepEditor update:dirty')
	assert.ok(view.includes('@stepDirty="stepDirty = $event"'), 'the page must consume SectionEditor stepDirty')
	// The page flag must stay distinct from the section-draft flag.
	assert.ok(view.includes('stepDirty = ref(false)'), 'the page must keep a distinct stepDirty flag')
	assert.ok(view.includes('@update:dirty="sectionDirty = $event"'), 'the page must keep a distinct sectionDirty flag')
})
