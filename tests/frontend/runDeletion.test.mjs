/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend wiring and copy for permanently deleting a run (issue #54).
 *
 * There is no DOM/browser harness in this repository, so the pure error mapper
 * is exercised directly and the service/view wiring is checked by reading the
 * source. A fail-closed deletion must surface the server's specific reason, not
 * the generic fallback, and must never leak internal details.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { apiErrorMessage } from '../../src/utils/apiError.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

test('the delete action calls the run DELETE endpoint', () => {
	const service = readFileSync(join(root, 'src', 'services', 'runs.ts'), 'utf8')
	assert.ok(service.includes('axios.delete(endpoint(`/runs/${id}`))'), 'the run is deleted by id')
})

test('the run view routes delete failures through the shared error mapper', () => {
	const view = readFileSync(join(root, 'src', 'views', 'RunDetailView.vue'), 'utf8')
	assert.ok(view.includes('await api.deleteRun(props.runId)'), 'the view deletes through the API client')
	assert.ok(view.includes('error.value = apiErrorMessage(caught)'), 'delete failures use the shared mapper')
})

test('a blocked deletion shows the specific, safe server message (#54)', () => {
	const message = apiErrorMessage({ response: { data: { reason: 'run_delete_blocked' } } })

	assert.notEqual(message, 'Something went wrong. Please try again.', 'the generic fallback must not be used')
	assert.match(message, /evidence files cannot be removed/)
	assert.doesNotMatch(message, /[\\/]/, 'no Files paths may be exposed')
	assert.doesNotMatch(message, /\d/, 'no internal identifiers may be exposed')
})

test('an unmapped failure still falls back to a safe generic message', () => {
	const message = apiErrorMessage({ response: { data: { reason: 'not_a_known_reason' } } })

	assert.equal(message, 'Something went wrong. Please try again.')
})
