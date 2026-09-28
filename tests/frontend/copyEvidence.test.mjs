/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend helpers and wiring for attaching a copy of an existing Files item
 * (issue #53). There is no DOM/browser harness in this repository, so the pure
 * helpers are exercised directly and the component/service wiring is checked by
 * reading the source.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { buildCopyEvidencePayload, copyEvidenceHint, normalizeSourcePath } from '../../src/utils/copyEvidence.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

test('normalizeSourcePath accepts a string or single-element array and rejects empty values', () => {
	assert.equal(normalizeSourcePath('/Documents/report.txt'), '/Documents/report.txt')
	assert.equal(normalizeSourcePath('  /Documents/report.txt  '), '/Documents/report.txt')
	assert.equal(normalizeSourcePath(['/Documents/report.txt']), '/Documents/report.txt')
	assert.equal(normalizeSourcePath(''), null)
	assert.equal(normalizeSourcePath('   '), null)
	assert.equal(normalizeSourcePath([]), null)
	assert.equal(normalizeSourcePath(42), null)
	assert.equal(normalizeSourcePath(undefined), null)
})

test('buildCopyEvidencePayload trims the path and refuses an empty one', () => {
	assert.deepEqual(buildCopyEvidencePayload(' /a/b.txt '), { sourcePath: '/a/b.txt' })
	assert.throws(() => buildCopyEvidencePayload(''), /copy_source_path_required/)
})

test('the copy hint explains that the original is left in place', () => {
	const t = (_app, text) => text
	assert.match(copyEvidenceHint(t), /copy is stored in the run folder/)
	assert.match(copyEvidenceHint(t), /original stays where it is/)
})

test('the step card exposes a files picker copy action (#53)', () => {
	const card = readFileSync(join(root, 'src', 'components', 'RunStepCard.vue'), 'utf8')
	assert.ok(card.includes('getFilePickerBuilder'), 'the copy action uses the Nextcloud Files picker')
	assert.ok(card.includes('FilePickerClosed'), 'cancelling the picker must be a no-op')
	assert.ok(card.includes('allowDirectories(false)'), 'only files may be copied')
	assert.ok(card.includes("emit('copyEvidence', path)"), 'the selected path is emitted to the view')
	assert.ok(card.includes('normalizeSourcePath'), 'the picker result is normalized before emitting')
	assert.ok(card.includes('copyEvidenceHint(t)'), 'the copy hint must be rendered')
	assert.ok(card.includes("t('runbook', 'Attach a copy from Files')"), 'the action must be labelled')
	assert.ok(card.includes('role="alert"'), 'a picker error must be announced accessibly')
})

test('the run view forwards the copy to the API (#53)', () => {
	const view = readFileSync(join(root, 'src', 'views', 'RunDetailView.vue'), 'utf8')
	assert.ok(view.includes("import { buildCopyEvidencePayload } from '../utils/copyEvidence.ts'"))
	assert.ok(view.includes('buildCopyEvidencePayload(sourcePath)'), 'the view builds the request payload')
	assert.ok(view.includes('@copyEvidence="(path) => copyEvidence(step.id, path)"'), 'the card event is wired')
	assert.ok(view.includes('api.copyAttachment'), 'the copy is sent through the API client')
	assert.ok(view.includes('void mutate('), 'copy errors go through the shared error/busy handler')

	const service = readFileSync(join(root, 'src', 'services', 'runs.ts'), 'utf8')
	assert.ok(service.includes('/run-steps/${stepId}/attachments/copy'), 'the copy endpoint is called')
	assert.ok(service.includes('CopyEvidencePayload'), 'the request body type is used')
})

test('copy failure reasons are mapped to clear messages (#53)', () => {
	const apiError = readFileSync(join(root, 'src', 'utils', 'apiError.ts'), 'utf8')
	for (const reason of [
		'attachment_source_required',
		'attachment_source_invalid',
		'attachment_source_missing',
		'attachment_source_no_access',
		'attachment_source_ambiguous',
	]) {
		assert.ok(apiError.includes(reason), `the reason ${reason} must be handled`)
	}
})
