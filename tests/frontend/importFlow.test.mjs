/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the asynchronous import submission state machine (#31).
 *
 * `runTemplateImport` and `canCloseImportDialog` are pure and are exercised
 * directly. These are not mounted-component tests: this repository has no DOM
 * test harness, so the Vue/NcDialog wiring is covered by source assertions in
 * `templateImport.test.mjs` rather than by rendering the dialog.
 */

import assert from 'node:assert/strict'
import test from 'node:test'

import { canCloseImportDialog, canStartImport, runTemplateImport } from '../../src/utils/importFlow.ts'

const DOCUMENT = { format: 'runbook-template', schemaVersion: 1 }

/**
 * @param {{ create?: Function, refresh?: Function, open?: Function }} overrides
 */
function deps(overrides = {}) {
	const calls = { create: 0, refresh: 0, open: 0, openedIds: [] }
	const template = { id: 42, title: 'Imported' }
	const base = {
		create: async () => {
			calls.create++
			return template
		},
		refresh: async () => {
			calls.refresh++
		},
		open: (value) => {
			calls.open++
			calls.openedIds.push(value.id)
		},
	}
	return { deps: { ...base, ...overrides }, calls, template }
}

test('the dialog may only be closed when no import is pending', () => {
	assert.equal(canCloseImportDialog(false), true)
	assert.equal(canCloseImportDialog(true), false)
})

test('a new submission only starts when no import is pending', () => {
	assert.equal(canStartImport(false), true)
	assert.equal(canStartImport(true), false)
})

test('a failed creation is reported and nothing else runs', async () => {
	const failure = new Error('boom')
	const { deps: flow, calls } = deps({
		create: async () => {
			calls.create++
			throw failure
		},
	})

	const result = await runTemplateImport(DOCUMENT, flow)

	assert.equal(result.status, 'failed')
	assert.equal(result.error, failure)
	assert.equal(calls.create, 1)
	assert.equal(calls.refresh, 0, 'refresh must not run after a failed creation')
	assert.equal(calls.open, 0, 'the editor must not open after a failed creation')
})

test('a successful creation refreshes and opens the new draft', async () => {
	const { deps: flow, calls, template } = deps()

	const result = await runTemplateImport(DOCUMENT, flow)

	assert.equal(result.status, 'created')
	assert.equal(result.template, template)
	assert.equal(result.refreshed, true)
	assert.equal(result.opened, true)
	assert.equal(calls.create, 1)
	assert.equal(calls.refresh, 1)
	assert.deepEqual(calls.openedIds, [42])
})

test('a refresh failure after creation is not a failed import and preserves the template', async () => {
	const { deps: flow, calls, template } = deps({
		refresh: async () => {
			calls.refresh++
			throw new Error('list unavailable')
		},
	})

	const result = await runTemplateImport(DOCUMENT, flow)

	assert.equal(result.status, 'created', 'the created template must not be reported as failed')
	assert.equal(result.refreshed, false)
	assert.equal(result.opened, false, 'do not navigate away when the list could not be refreshed')
	assert.equal(result.template, template, 'the created template id is preserved for recovery')
	assert.equal(calls.create, 1)
})

test('an open failure after creation is not a failed import and preserves the template', async () => {
	const { deps: flow, calls, template } = deps({
		open: () => {
			calls.open++
			throw new Error('navigation failed')
		},
	})

	const result = await runTemplateImport(DOCUMENT, flow)

	assert.equal(result.status, 'created')
	assert.equal(result.refreshed, true)
	assert.equal(result.opened, false)
	assert.equal(result.template, template)
	assert.equal(calls.create, 1)
	assert.equal(calls.refresh, 1)
})
