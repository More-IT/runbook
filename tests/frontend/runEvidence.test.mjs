/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the required FILE-step evidence state.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { attachmentsForStep, canCompleteStep, canRemoveEvidence, missingRequiredFileEvidence } from '../../src/utils/runEvidence.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

test('a required FILE step can only complete once evidence is present', () => {
	assert.equal(canCompleteStep('FILE', true, 0), false)
	assert.equal(canCompleteStep('FILE', true, 1), true)
	assert.equal(canCompleteStep('FILE', true, 2), true)
})

test('an optional FILE step can complete without evidence', () => {
	assert.equal(canCompleteStep('FILE', false, 0), true)
})

test('non-FILE steps are never gated on evidence', () => {
	for (const type of ['CHECK', 'CONFIRMATION', 'TEXT', 'NUMBER', 'SELECT', 'DATE', 'USER']) {
		assert.equal(canCompleteStep(type, true, 0), true)
	}
})

test('the missing-evidence explanation only applies to a required FILE step', () => {
	assert.equal(missingRequiredFileEvidence('FILE', true, 0), true)
	assert.equal(missingRequiredFileEvidence('FILE', true, 1), false)
	assert.equal(missingRequiredFileEvidence('FILE', false, 0), false)
	assert.equal(missingRequiredFileEvidence('TEXT', true, 0), false)
})

test('removing the last attachment of a completed required FILE step is blocked', () => {
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 1), false)
	// A replacement (two attachments) may be removed down to the last one.
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 2), true)
})

test('removal stays allowed for optional, pending and non-FILE steps', () => {
	assert.equal(canRemoveEvidence('FILE', false, 'COMPLETED', 1), true)
	assert.equal(canRemoveEvidence('FILE', true, 'PENDING', 1), true)
	assert.equal(canRemoveEvidence('CHECK', true, 'COMPLETED', 1), true)
})

test('the complete button transitions from disabled to enabled after a successful upload', () => {
	// Before uploading: no evidence loaded for the step.
	assert.equal(canCompleteStep('FILE', true, 0), false)
	assert.equal(missingRequiredFileEvidence('FILE', true, 0), true)

	// After the upload succeeds, RunDetailView reloads the attachments; the step
	// now receives one attachment and completion becomes available.
	assert.equal(canCompleteStep('FILE', true, 1), true)
	assert.equal(missingRequiredFileEvidence('FILE', true, 1), false)
})

test('RunStepCard uses the shared evidence helpers', () => {
	const card = readFileSync(join(root, 'src', 'components', 'RunStepCard.vue'), 'utf8')
	assert.ok(card.includes('canCompleteStep(props.step.type'), 'completion must use the shared helper')
	assert.ok(card.includes('missingRequiredFileEvidence(props.step.type'), 'the explanation must use the shared helper')
	assert.ok(card.includes('canRemoveEvidence(props.step.type'), 'deletion must use the shared helper')
	assert.ok(card.includes("t('runbook', 'Attach at least one file before completing this step.')"))
})

test('a required FILE step with an existing attachment is completable (props chain)', () => {
	const attachments = [
		{ id: 1, stepId: 42, filename: 'Divisões.png' },
		{ id: 2, stepId: 43, filename: 'other.txt' },
	]

	// RunDetailView filters the run attachments to the step before passing them
	// to RunStepCard.
	const forStep = attachmentsForStep(attachments, 42)
	assert.equal(forStep.length, 1)
	assert.equal(forStep[0].filename, 'Divisões.png')

	// RunStepCard then enables Complete for a required FILE step with evidence.
	assert.equal(canCompleteStep('FILE', true, forStep.length), true)
	assert.equal(missingRequiredFileEvidence('FILE', true, forStep.length), false)

	// A step without evidence stays disabled.
	assert.equal(attachmentsForStep(attachments, 99).length, 0)
	assert.equal(canCompleteStep('FILE', true, attachmentsForStep(attachments, 99).length), false)
})

test('attachmentsForStep ignores attachments of other steps and runs', () => {
	const attachments = [
		{ id: 1, runId: 7, stepId: 42 },
		{ id: 2, runId: 7, stepId: 43 },
		{ id: 3, runId: 8, stepId: 42 },
	]
	assert.deepEqual(attachmentsForStep(attachments, 42).map((a) => a.id), [1, 3])
})

test('RunDetailView and RunStepCard are wired through the shared helper', () => {
	const detail = readFileSync(join(root, 'src', 'views', 'RunDetailView.vue'), 'utf8')
	assert.ok(detail.includes("attachmentsForStep as attachmentsForStepHelper"), 'the view must use the shared filter')
	assert.ok(detail.includes('attachmentsForStepHelper(attachments.value, stepId)'))
	assert.ok(detail.includes(':attachments="attachmentsForStep(step.id)"'))
})
