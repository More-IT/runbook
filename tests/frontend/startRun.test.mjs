/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the run-time destination override (issue #49).
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
	buildStartRunPayload,
	convertDueDateInput,
	normalizeRunDestinationPath,
	startRunDestinationStatusText,
} from '../../src/utils/startRun.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

/** Translator double that returns the source string. */
function t(_app, text) {
	return text
}

test('an unset destination is omitted from the payload', () => {
	const payload = buildStartRunPayload({ title: 'Deploy', description: '', dueDate: '', destinationPath: null })

	assert.equal(Object.prototype.hasOwnProperty.call(payload, 'destinationPath'), false)
	assert.equal(payload.dueAt, null)
})

test('a whitespace-only destination is treated as inherited and omitted', () => {
	const payload = buildStartRunPayload({ title: 'Deploy', description: '', dueDate: '', destinationPath: '   ' })

	assert.equal(Object.prototype.hasOwnProperty.call(payload, 'destinationPath'), false)
})

test('an explicit destination is included and trimmed', () => {
	const payload = buildStartRunPayload({ title: 'Deploy', description: '', dueDate: '', destinationPath: '  /Shared/Reports  ' })

	assert.equal(payload.destinationPath, '/Shared/Reports')
})

test('due date conversion matches the API contract', () => {
	assert.equal(convertDueDateInput(''), null)
	assert.equal(convertDueDateInput('1970-01-01'), 0)
	assert.equal(convertDueDateInput('2026-03-13'), Math.floor(Date.UTC(2026, 2, 13) / 1000))
})

test('destination path normalisation', () => {
	assert.equal(normalizeRunDestinationPath(null), null)
	assert.equal(normalizeRunDestinationPath(''), null)
	assert.equal(normalizeRunDestinationPath('  '), null)
	assert.equal(normalizeRunDestinationPath(' /A '), '/A')
})

test('status text distinguishes an explicit choice from the inherited destination', () => {
	const inherited = startRunDestinationStatusText(t, null)
	const explicit = startRunDestinationStatusText(t, '/Shared')

	assert.notEqual(inherited, explicit)
	assert.match(inherited, /template or administrator/)
	assert.match(explicit, /selected folder/)
})

test('status text never contains internal identity-like data', () => {
	const explicit = startRunDestinationStatusText(t, '/Shared')
	assert.equal(explicit.includes('storage'), false)
	assert.equal(explicit.includes('fileId'), false)
})

test('the dialog prevents dismissal while a start request is in flight', () => {
	const dialog = readFileSync(join(root, 'src', 'components', 'StartRunDialog.vue'), 'utf8')

	assert.ok(dialog.includes(':noClose="busy"'), 'the NcDialog close control/Escape must be disabled while busy')
	assert.ok(dialog.includes(':closeOnClickOutside="!busy"'), 'the backdrop must not dismiss the dialog while busy')
	assert.ok(dialog.includes('@closing="submission.requestClose()"'), 'dialog dismissal goes through the guarded controller')
	assert.ok(dialog.includes('@click="submission.requestClose()"'), 'Cancel goes through the guarded controller')
	assert.ok(dialog.includes(':disabled="busy"'), 'Cancel is disabled while busy')
	assert.ok(dialog.includes('@click="submission.submit()"'), 'Start run is wired to the guarded controller')
})
