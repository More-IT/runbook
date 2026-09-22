/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regression tests for the app-shell navigation guard lifecycle, the History
 * API strategy and template-load ordering (issue #29 review).
 *
 * They exercise the real reducer transitions App.vue and TemplateEditorView use
 * (request/confirm/cancel, history method selection, load sequencing), not toy
 * callbacks. No browser/DOM is available, so History API calls themselves and
 * mounted Vue event wiring are not executed — see the limitation notes.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { beginLoad, isStaleLoad } from '../../src/utils/runNavigation.ts'
import {
	cancelNavigation,
	confirmNavigation,
	requestNavigation,
} from '../../src/utils/navigationController.ts'
import { shouldGuardNavigation } from '../../src/utils/navigationGuard.ts'

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

const target = (section, templateId = null, runId = null, stepId = null) => ({ section, templateId, runId, stepId })
const editor = (templateId, hasDrafts, pending = null) => ({ editorTemplateId: templateId, hasDrafts, pending })

test('a request that leaves a dirty editor is deferred, not applied', () => {
	const outcome = requestNavigation(editor(7, true), target('runs'))

	assert.equal(outcome.apply, null)
	assert.deepEqual(outcome.state.pending, target('runs'))
})

test('a request that does not leave the editor, or has no drafts, applies immediately', () => {
	assert.deepEqual(requestNavigation(editor(7, true), target('templates', 7)).apply, target('templates', 7))
	assert.deepEqual(requestNavigation(editor(7, false), target('runs')).apply, target('runs'))
	assert.deepEqual(requestNavigation(editor(null, true), target('runs')).apply, target('runs'))
})

test('confirming consumes the pending target exactly once (no second dialog, no loop)', () => {
	const requested = requestNavigation(editor(7, true), target('runs'))
	const first = confirmNavigation(requested.state)

	assert.equal(first.discard, true)
	assert.deepEqual(first.navigate, target('runs'))
	assert.equal(first.state.pending, null)

	// A second confirmation (e.g. a stray event) is a no-op.
	const second = confirmNavigation(first.state)
	assert.equal(second.navigate, null)
	assert.equal(second.discard, false)
})

test('cancelling keeps the editor and restores the URL', () => {
	const requested = requestNavigation(editor(7, true), target('runs'))
	const cancelled = cancelNavigation(requested.state)

	assert.equal(cancelled.navigate, null)
	assert.equal(cancelled.discard, false)
	assert.equal(cancelled.restore, true)
	assert.equal(cancelled.state.pending, null)

	// Cancelling with nothing pending does not touch the URL.
	assert.equal(cancelNavigation(editor(7, true)).restore, false)
})

test('the single-decision fix: reading drafts synchronously avoids a double prompt', () => {
	// Editor "Back": it discards synchronously, then the app guard runs.
	let hasDrafts = true
	const discard = () => { hasDrafts = false }
	discard()
	const outcome = requestNavigation(editor(7, hasDrafts), target('templates'))
	assert.deepEqual(outcome.apply, target('templates'))
	assert.equal(outcome.state.pending, null)
})

test('repeated Back/Forward attempts keep one pending decision and never duplicate dialogs', () => {
	// First Back while dirty: deferred.
	let state = requestNavigation(editor(7, true), target('runs')).state
	assert.deepEqual(state.pending, target('runs'))
	// Forward then Back again (another popstate) overwrites the same pending target.
	state = requestNavigation(state, target('templates')).state
	assert.deepEqual(state.pending, target('templates'))
	// Cancel: clear and restore.
	state = cancelNavigation(state).state
	assert.equal(state.pending, null)
	// A later confirm with nothing pending is a no-op.
	assert.equal(confirmNavigation(state).navigate, null)
})

test('deep links and sidebar navigation after a confirmed discard are not re-guarded', () => {
	const requested = requestNavigation(editor(7, true), target('runs', null, 12, 34))
	const confirmed = confirmNavigation(requested.state)
	assert.deepEqual(confirmed.navigate, target('runs', null, 12, 34))
	// Drafts were discarded, so the next navigation applies directly.
	assert.deepEqual(requestNavigation(editor(7, false), target('my-work')).apply, target('my-work'))
})

test('rapid A -> B -> C template loads commit only the newest response', () => {
	let latest = 0
	const a = beginLoad(latest)
	latest = a.latest
	const b = beginLoad(latest)
	latest = b.latest
	const c = beginLoad(latest)
	latest = c.latest

	// Responses arrive out of order: C, then B, then A.
	const committed = []
	if (!isStaleLoad(c.sequence, latest)) committed.push('C')
	if (!isStaleLoad(b.sequence, latest)) committed.push('B')
	if (!isStaleLoad(a.sequence, latest)) committed.push('A')

	assert.deepEqual(committed, ['C'])
	assert.equal(isStaleLoad(a.sequence, latest), true)
	assert.equal(isStaleLoad(b.sequence, latest), true)
	assert.equal(isStaleLoad(c.sequence, latest), false)
})

test('the guard is still applied for sidebar, template switch and run links', () => {
	for (const t of [target('runs'), target('templates', 8), target('runs', null, 3, 9), target('archived')]) {
		assert.equal(shouldGuardNavigation({ templateId: 7, hasDrafts: true }, t), true)
	}
	assert.equal(shouldGuardNavigation({ templateId: 7, hasDrafts: false }, target('runs')), false)
})

test('wiring: App.vue uses the reducer/history helpers and the editor exposes sync drafts', () => {
	const app = readFileSync(join(root, 'src', 'App.vue'), 'utf8')
	assert.ok(app.includes('requestHistory('), 'App must request navigation through the history controller')
	assert.ok(app.includes("addEventListener('popstate'"), 'App must react to browser Back/Forward via popstate')
	assert.ok(app.includes('editorRef.value?.hasDrafts()'), 'App must read editor drafts synchronously')

	const editor = readFileSync(join(root, 'src', 'views', 'TemplateEditorView.vue'), 'utf8')
	assert.ok(editor.includes('defineExpose({ discardAllDrafts, hasDrafts })'), 'editor must expose sync drafts + discard')
	assert.ok(editor.includes('if (sequence !== loadSequence)'), 'editor load must be sequence-guarded')
})
