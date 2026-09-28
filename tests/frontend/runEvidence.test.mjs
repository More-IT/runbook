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

import { attachmentsForStep, canCompleteStep, canRemoveEvidence, degradedEvidenceNotice, evidenceStateText, hasDegradedEvidence, missingRequiredFileEvidence, usableEvidenceCount } from '../../src/utils/runEvidence.ts'

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
	assert.ok(detail.includes('attachmentsForStep as attachmentsForStepHelper'), 'the view must use the shared filter')
	assert.ok(detail.includes('attachmentsForStepHelper(attachments.value, stepId)'))
	assert.ok(detail.includes(':attachments="attachmentsForStep(step.id)"'))
})

test('only present evidence counts as usable', () => {
	assert.equal(usableEvidenceCount([{ fileState: 'present' }, { fileState: 'missing' }]), 1)
	assert.equal(usableEvidenceCount([{ fileState: 'missing' }, { fileState: 'out_of_scope' }, { fileState: 'unavailable' }]), 0)
	// An unset state (legacy AppData / older payload) counts as present.
	assert.equal(usableEvidenceCount([{}, { fileState: 'present' }]), 2)
})

test('a required FILE step with only degraded evidence stays incomplete', () => {
	const attachments = [{ fileState: 'missing' }, { fileState: 'out_of_scope' }]
	assert.equal(canCompleteStep('FILE', true, usableEvidenceCount(attachments)), false)
	assert.equal(missingRequiredFileEvidence('FILE', true, usableEvidenceCount(attachments)), true)

	// Adding a present replacement enables completion again.
	const withReplacement = [...attachments, { fileState: 'present' }]
	assert.equal(canCompleteStep('FILE', true, usableEvidenceCount(withReplacement)), true)
})

test('degraded evidence is detected for a step or run', () => {
	assert.equal(hasDegradedEvidence([{ fileState: 'present' }]), false)
	assert.equal(hasDegradedEvidence([{ fileState: 'present' }, { fileState: 'unavailable' }]), true)
	assert.equal(hasDegradedEvidence([{}]), false, 'an unset state is not degraded')
})

test('evidence state text explains each degraded state and is null when present', () => {
	const t = (_app, text) => text
	assert.equal(evidenceStateText(t, 'present'), null)
	assert.equal(evidenceStateText(t, undefined), null)
	assert.match(evidenceStateText(t, 'missing'), /missing/)
	assert.match(evidenceStateText(t, 'out_of_scope'), /run folder/)
	assert.match(evidenceStateText(t, 'unavailable'), /unavailable/)
})

test('the UI surfaces the reconciled evidence state (#52)', () => {
	const card = readFileSync(join(root, 'src', 'components', 'RunStepCard.vue'), 'utf8')
	assert.ok(card.includes('usableEvidenceCount(props.attachments)'), 'completion must use usable evidence')
	assert.ok(card.includes('evidenceStateText'), 'degraded evidence must be explained per attachment')
	assert.ok(
		card.includes('canRemoveEvidence(props.step.type, props.step.required, props.step.status, usableEvidence.value, attachment.fileState)'),
		'delete availability must use the present count and the attachment state',
	)

	const panel = readFileSync(join(root, 'src', 'components', 'RunEvidencePanel.vue'), 'utf8')
	assert.ok(panel.includes('evidenceStateText'), 'the evidence panel must explain degraded files')

	const detail = readFileSync(join(root, 'src', 'views', 'RunDetailView.vue'), 'utf8')
	assert.ok(detail.includes('detail.evidenceDegraded'), 'the run view must show the degraded notice')
	assert.ok(
		detail.includes('degradedEvidenceNotice(t, attachments, detail.managedFolderState)'),
		'the degraded notice must consider the reconciled managed-folder state',
	)
})

test('the degraded notice is truthful about whether an upload can succeed', () => {
	const t = (_app, text) => text
	const missing = [{ fileState: 'missing' }]

	// Missing evidence file with the folder available: a replacement is possible.
	assert.match(degradedEvidenceNotice(t, missing, 'available'), /Upload replacements/)
	// Missing/unavailable managed folder: uploads would fail closed, so never
	// promise a replacement.
	assert.match(degradedEvidenceNotice(t, missing, 'missing'), /cannot be uploaded/)
	assert.match(degradedEvidenceNotice(t, missing, 'missing'), /repair/)
	assert.doesNotMatch(degradedEvidenceNotice(t, missing, 'missing'), /Upload replacements/)
	assert.match(degradedEvidenceNotice(t, missing, 'unavailable'), /cannot be uploaded/)
	assert.doesNotMatch(degradedEvidenceNotice(t, missing, 'unavailable'), /Upload replacements/)
	// Legacy runs (no Files destination) keep the plain replacement wording.
	assert.match(degradedEvidenceNotice(t, missing, 'not_applicable'), /Upload replacements/)

	// Out-of-scope / unavailable files explain the actual next step instead of
	// promising an upload.
	assert.match(degradedEvidenceNotice(t, [{ fileState: 'out_of_scope' }], 'available'), /moved outside/)
	assert.match(degradedEvidenceNotice(t, [{ fileState: 'unavailable' }], 'available'), /reappear|replacement/)
})

test('a non-present attachment may be removed even when it is the last row', () => {
	// The server refuses to delete the last *present* attachment of a completed
	// required FILE step, but a missing/out-of-scope/unavailable row is not
	// evidence, so its metadata may be removed.
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 1, 'missing'), true)
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 1, 'out_of_scope'), true)
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 1, 'unavailable'), true)

	// A present last attachment stays protected.
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 1, 'present'), false)
	assert.equal(canRemoveEvidence('FILE', true, 'COMPLETED', 2, 'present'), true)
})
