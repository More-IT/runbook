/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the browser tab title (no `[object Object]`, no duplicates).
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { buildPageTitle } from '../../src/utils/pageTitle.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

test('run, template editor and main view titles are explicit strings', () => {
	assert.equal(buildPageTitle(['Run', 'Runbook']), 'Run - Runbook')
	assert.equal(buildPageTitle(['Template editor', 'Runbook']), 'Template editor - Runbook')
	assert.equal(buildPageTitle(['Templates', 'Runbook']), 'Templates - Runbook')
	assert.equal(buildPageTitle(['My Work', 'Runbook']), 'My Work - Runbook')
})

test('the title never contains [object Object] or duplicated text', () => {
	assert.equal(buildPageTitle(['Run', 'Runbook', 'Runbook']), 'Run - Runbook')
	assert.equal(buildPageTitle(['Run', 'Run']), 'Run')
	assert.equal(buildPageTitle(['[object Object]', 'Runbook']), 'Runbook')
	// A non-string part (e.g. an accidentally passed object prop) is dropped.
	assert.equal(buildPageTitle([{ text: 'Run' }, 'Runbook']), 'Runbook')
	assert.equal(buildPageTitle([undefined, null, 'Runbook']), 'Runbook')
	assert.equal(buildPageTitle([]), '')
})

test('App.vue passes an explicit, localized pageTitle to NcAppContent', () => {
	const app = readFileSync(join(root, 'src', 'App.vue'), 'utf8')
	assert.ok(app.includes("import { buildPageTitle } from './utils/pageTitle.ts'"))
	assert.ok(
		app.includes("buildPageTitle([pageHeading.value, t('runbook', 'Runbook')])"),
		'the tab title must be composed from localized strings',
	)
	assert.ok(app.includes(':pageTitle="pageTitle"'), 'NcAppContent must receive the explicit title')
	assert.ok(app.includes(':pageHeading="pageHeading"'), 'the visible heading must stay unchanged')
})
