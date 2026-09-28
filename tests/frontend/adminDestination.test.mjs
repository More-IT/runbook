/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the global administration destination setting (issue #47).
 */

import assert from 'node:assert/strict'
import test from 'node:test'

import {
	destinationPath,
	destinationStatus,
	destinationStatusText,
	EMPTY_DESTINATION_STATE,
	selectedPathFromPicker,
} from '../../src/utils/adminDestination.ts'

/** Translator double that returns the source string. */
function t(_app, text) {
	return text
}

function state(overrides = {}) {
	return {
		configured: false,
		valid: false,
		path: null,
		configuredBy: null,
		...overrides,
	}
}

test('an unconfigured destination is classified as unset', () => {
	assert.equal(destinationStatus(EMPTY_DESTINATION_STATE), 'unset')
	assert.equal(destinationPath(EMPTY_DESTINATION_STATE), null)
})

test('a complete stored reference is configured', () => {
	const value = state({ configured: true, valid: true, path: '/Shared/Reports', configuredBy: 'admin' })
	assert.equal(destinationStatus(value), 'configured')
	assert.equal(destinationPath(value), '/Shared/Reports')
})

test('a configured but incomplete reference is invalid, never unset', () => {
	const value = state({ configured: true, valid: false, path: null, configuredBy: 'admin' })
	assert.equal(destinationStatus(value), 'invalid')
})

test('an empty display path is treated as absent', () => {
	assert.equal(destinationPath(state({ configured: true, valid: true, path: '' })), null)
})

test('status text differs per state', () => {
	const unset = destinationStatusText(t, EMPTY_DESTINATION_STATE)
	const configured = destinationStatusText(t, state({ configured: true, valid: true, path: '/x', configuredBy: 'a' }))
	const invalid = destinationStatusText(t, state({ configured: true, valid: false }))

	assert.notEqual(unset, configured)
	assert.notEqual(configured, invalid)
	assert.notEqual(unset, invalid)
})

test('picker result normalisation accepts a single path', () => {
	assert.equal(selectedPathFromPicker('/Shared/Reports'), '/Shared/Reports')
})

test('picker result normalisation accepts an array defensively', () => {
	assert.equal(selectedPathFromPicker(['/Shared/Reports']), '/Shared/Reports')
})

test('picker result normalisation rejects empty and unknown values', () => {
	assert.equal(selectedPathFromPicker(''), null)
	assert.equal(selectedPathFromPicker('   '), null)
	assert.equal(selectedPathFromPicker([]), null)
	assert.equal(selectedPathFromPicker([null]), null)
	assert.equal(selectedPathFromPicker(undefined), null)
	assert.equal(selectedPathFromPicker(42), null)
})
