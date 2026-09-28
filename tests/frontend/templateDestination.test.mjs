/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the optional per-template destination folder (issue #48).
 */

import assert from 'node:assert/strict'
import test from 'node:test'

import {
	EMPTY_TEMPLATE_DESTINATION,
	selectedPathFromPicker,
	templateDestinationPath,
	templateDestinationStatus,
	templateDestinationStatusText,
} from '../../src/utils/templateDestination.ts'

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

test('an unconfigured template destination is unset', () => {
	assert.equal(templateDestinationStatus(EMPTY_TEMPLATE_DESTINATION), 'unset')
	assert.equal(templateDestinationPath(EMPTY_TEMPLATE_DESTINATION), null)
})

test('a complete template destination is configured', () => {
	const value = state({ configured: true, valid: true, path: '/Shared/Reports', configuredBy: 'alice' })
	assert.equal(templateDestinationStatus(value), 'configured')
	assert.equal(templateDestinationPath(value), '/Shared/Reports')
})

test('a configured but incomplete template destination is invalid', () => {
	assert.equal(templateDestinationStatus(state({ configured: true, valid: false })), 'invalid')
})

test('an empty display path is treated as absent', () => {
	assert.equal(templateDestinationPath(state({ configured: true, valid: true, path: '' })), null)
})

test('status text differs per state and mentions the fall-through when unset', () => {
	const unset = templateDestinationStatusText(t, EMPTY_TEMPLATE_DESTINATION)
	const configured = templateDestinationStatusText(t, state({ configured: true, valid: true, path: '/x', configuredBy: 'a' }))
	const invalid = templateDestinationStatusText(t, state({ configured: true, valid: false }))

	assert.notEqual(unset, configured)
	assert.notEqual(configured, invalid)
	assert.notEqual(unset, invalid)
	assert.match(unset, /global destination/)
})

test('picker result normalisation is shared with the administration picker', () => {
	assert.equal(selectedPathFromPicker('/Shared/Reports'), '/Shared/Reports')
	assert.equal(selectedPathFromPicker(['/Shared/Reports']), '/Shared/Reports')
	assert.equal(selectedPathFromPicker(''), null)
	assert.equal(selectedPathFromPicker(undefined), null)
})
