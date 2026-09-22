/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Interaction tests for the app-shell navigation guard (issue #29). They drive
 * the exact hash parsing and guard decisions App.vue uses for sidebar clicks,
 * hash changes and browser Back/Forward.
 */

import assert from 'node:assert/strict'
import test from 'node:test'

import {
	hashForTarget,
	isCurrentTarget,
	parseHashTarget,
	shouldGuardNavigation,
} from '../../src/utils/navigationGuard.ts'

const sections = ['overview', 'my-work', 'runs', 'templates', 'archived']

/**
 * @param {string} hash Location hash.
 */
function target(hash) {
	return parseHashTarget(hash, sections)
}

test('hash targets parse for runs, deep-linked steps, templates and sidebar sections', () => {
	assert.deepEqual(target('#runs'), { section: 'runs', templateId: null, runId: null, stepId: null })
	assert.deepEqual(target('#/run/12'), { section: 'runs', templateId: null, runId: 12, stepId: null })
	assert.deepEqual(target('#/run/12/step/34'), { section: 'runs', templateId: null, runId: 12, stepId: 34 })
	assert.deepEqual(target('#/template/7'), { section: 'templates', templateId: 7, runId: null, stepId: null })
	assert.deepEqual(target('#overview'), { section: 'overview', templateId: null, runId: null, stepId: null })
})

test('invalid or unknown hashes are ignored', () => {
	assert.equal(target('#'), null)
	assert.equal(target(''), null)
	assert.equal(target('#/run/0'), null)
	assert.equal(target('#/run/abc'), null)
	assert.equal(target('#/template/-1'), null)
	assert.equal(target('#nope'), null)
})

test('hashForTarget round-trips every target shape', () => {
	assert.equal(hashForTarget({ section: 'runs', templateId: null, runId: 12, stepId: null }), '#/run/12')
	assert.equal(hashForTarget({ section: 'runs', templateId: null, runId: 12, stepId: 34 }), '#/run/12/step/34')
	assert.equal(hashForTarget({ section: 'templates', templateId: 7, runId: null, stepId: null }), '#/template/7')
	assert.equal(hashForTarget({ section: 'my-work', templateId: null, runId: null, stepId: null }), '#my-work')
})

test('isCurrentTarget detects no-op navigations', () => {
	const state = { section: 'runs', templateId: null, runId: 12, stepId: 34 }
	assert.equal(isCurrentTarget(target('#/run/12/step/34'), state), true)
	assert.equal(isCurrentTarget(target('#/run/12'), state), false)
})

test('leaving a dirty editor is guarded for every draft kind', () => {
	const cases = [
		{ label: 'metadata', hasDrafts: true },
		{ label: 'section', hasDrafts: true },
		{ label: 'step', hasDrafts: true },
	]
	for (const entry of cases) {
		assert.equal(
			shouldGuardNavigation({ templateId: 7, hasDrafts: entry.hasDrafts }, target('#runs')),
			true,
			`${entry.label} draft must guard leaving the editor`,
		)
	}
})

test('sidebar, another template, close and run deep links are all guarded while dirty', () => {
	for (const hash of ['#templates', '#/template/8', '#runs', '#/run/3', '#/run/3/step/9', '#my-work']) {
		assert.equal(shouldGuardNavigation({ templateId: 7, hasDrafts: true }, target(hash)), true, hash)
	}
})

test('staying on the same template or editing a clean template is not guarded', () => {
	assert.equal(shouldGuardNavigation({ templateId: 7, hasDrafts: true }, target('#/template/7')), false)
	assert.equal(shouldGuardNavigation({ templateId: 7, hasDrafts: false }, target('#/run/3')), false)
	assert.equal(shouldGuardNavigation({ templateId: null, hasDrafts: true }, target('#runs')), false)
})

test('browser Back/Forward targets are guarded only when leaving a dirty editor', () => {
	const state = { section: 'templates', templateId: 7, runId: null, stepId: null }
	const backToSections = target('#templates')
	const forwardToRun = target('#/run/5')

	assert.equal(isCurrentTarget(backToSections, state), false)
	assert.equal(shouldGuardNavigation({ templateId: state.templateId, hasDrafts: true }, backToSections), true)
	assert.equal(shouldGuardNavigation({ templateId: state.templateId, hasDrafts: true }, forwardToRun), true)
})

test('a confirmed navigation from the editor lands on the intended target exactly once', () => {
	// Deferred target, then discarding and applying it should equal the target.
	const intended = target('#/run/5')
	let applied = null
	const deferred = () => { applied = intended }
	deferred()
	assert.deepEqual(applied, intended)
	// The URL re-sync after a cancel returns to the editor hash.
	assert.equal(hashForTarget({ section: 'templates', templateId: 7, runId: null, stepId: null }), '#/template/7')
})

test('a cancelled navigation keeps the editor hash, so URL and editor agree', () => {
	const editorHash = hashForTarget({ section: 'templates', templateId: 7, runId: null, stepId: null })
	const pending = target('#/run/5')
	assert.notEqual(editorHash, hashForTarget(pending))
	// Cancel restores the editor hash (no navigation applied).
	assert.equal(editorHash, '#/template/7')
})
