/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Browser-history regression harness for the app shell (issue #29 review).
 *
 * A simulated browser owns its own `entries`, `index`, `url` and `state` and may
 * be mounted at any index (including with forward entries still available). The
 * app keeps a separate controller state and must read its position from
 * `history.state` — the harness never copies the app's assumed position into the
 * browser, so a wrong assumption is caught.
 *
 * This models history semantics; a real-browser integration test is not
 * available in this repository and is not claimed.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
	mergeAppPosition,
	readAppPosition,
	withoutAppPosition,
} from '../../src/utils/historyStack.ts'
import {
	cancelHistory,
	confirmHistory,
	createNavHistory,
	handlePop,
	markCurrentEntry,
	requestHistory,
	setCurrentPosition,
} from '../../src/utils/navigationHistory.ts'
import { hashForTarget, parseHashTarget } from '../../src/utils/navigationGuard.ts'

const sections = ['overview', 'my-work', 'runs', 'templates', 'archived']
const parse = (hash) => parseHashTarget(hash, sections) ?? { section: 'templates', templateId: null, runId: null, stepId: null }

const TEMPLATES = parse('#templates')
const EDITOR = parse('#/template/7')
const RUN5 = parse('#/run/5')
const RUN6 = parse('#/run/6')
const STEP_LINK = parse('#/run/12/step/34')

/** App metadata state for a physical position. */
const at = (position) => mergeAppPosition(null, position).state

/**
 * Simulated browser with an independent history stack.
 *
 * @param {string} initialHash Initial location hash.
 * @param {unknown} initialState Initial `history.state`.
 */
function browser(initialHash, initialState = null) {
	const b = {
		entries: [{ hash: initialHash, state: initialState }],
		index: 0,
		get url() { return b.entries[b.index].hash },
		get state() { return b.entries[b.index].state },
		pushState(hash, state) {
			b.entries = b.entries.slice(0, b.index + 1).concat([{ hash, state }])
			b.index = b.entries.length - 1
		},
		replaceState(hash, state) { b.entries[b.index] = { hash, state } },
		go(delta) { b.index += delta },
		hashes() { return b.entries.map((entry) => entry.hash) },
	}
	return b
}

/**
 * App harness mirroring App.vue's imperative effects on top of the controller.
 *
 * @param {object} b Simulated browser.
 */
function app(b) {
	const a = {
		nav: createNavHistory(),
		rendered: null,
		dirty: false,
		editor() { return { templateId: a.rendered?.templateId ?? null, dirty: a.dirty } },
		base() { return a.nav.currentPosition },
		writePlan(plan) {
			if (plan.position === null) {
				b.pushState(plan.hash, withoutAppPosition(b.state))

				return false
			}
			const merged = mergeAppPosition(b.state, plan.position)
			b.pushState(plan.hash, merged.state)

			return merged.stored
		},
		apply(transition) {
			a.nav = transition.state
			if (transition.push && !a.writePlan(transition.push)) {
				a.nav = { ...a.nav, currentPosition: null, editorPosition: null }
			}
			if (transition.anchor && !a.writePlan(transition.anchor)) {
				a.nav = { ...a.nav, currentPosition: null, editorPosition: null }
			}
			if (transition.render) { a.rendered = transition.render }
			if (transition.discard) { a.dirty = false }
			if (transition.jump) { b.go(transition.jump) }
		},
		mount() {
			a.rendered = parse(b.url)
			const stored = readAppPosition(b.state)
			const merged = stored === null ? { state: b.state, stored: false } : mergeAppPosition(b.state, stored)
			b.replaceState(b.url, merged.state)
			a.nav = markCurrentEntry(setCurrentPosition(createNavHistory(), merged.stored ? stored : null), a.rendered)
		},
		navigate(target) { a.apply(requestHistory(a.nav, target, a.editor(), hashForTarget(target), a.base())) },
		popstate() {
			a.apply(handlePop(a.nav, { hash: b.url, position: readAppPosition(b.state), target: parse(b.url), renderedTarget: a.rendered }, a.editor()))
		},
		confirm() { a.apply(confirmHistory(a.nav, a.editor(), a.base())) },
		cancel() { a.apply(cancelHistory(a.nav, a.editor(), hashForTarget(a.rendered), a.base())) },
		pending() { return a.nav.pending },
	}
	return a
}

/** Simulate Back: browser first, then popstate. */
function back(b, a) { b.go(-1); a.popstate() }
/** Simulate Forward: browser first, then popstate. */
function forward(b, a) { b.go(1); a.popstate() }

/**
 * Browser with templates(0) -> editor(1) -> run5(2), mounted on the editor while
 * Forward to run 5 remains available. `editorMetadata` controls whether the
 * editor entry carries Runbook position metadata.
 *
 * @param {boolean} editorMetadata Whether the editor entry stores a position.
 */
function midStackAtEditor(editorMetadata = true) {
	const b = browser('#templates', at(0))
	b.pushState('#/template/7', editorMetadata ? at(1) : null)
	b.pushState('#/run/5', at(2))
	b.go(-1) // Back to the editor at physical position 1, run 5 still ahead

	return b
}

test('mid-stack mount: Forward from a reloaded dirty editor gives one decision and keeps the draft', () => {
	const b = midStackAtEditor(true)
	const a = app(b)
	a.mount()
	assert.equal(b.index, 1)
	assert.equal(a.nav.currentPosition, 1, 'stored position is retained, not history.length - 1')
	assert.equal(a.dirty, false)

	a.dirty = true
	forward(b, a) // Forward to run 5
	assert.equal(b.url, '#/run/5')
	assert.equal(b.index, 2)
	assert.notEqual(a.pending(), null, 'exactly one discard decision')
	assert.equal(a.rendered.templateId, 7, 'editor still rendered until decided')
	assert.equal(a.dirty, true)

	a.confirm()
	assert.equal(b.url, '#/run/5')
	assert.equal(a.rendered.runId, 5)
	assert.equal(a.dirty, false)

	// Cancel variant restores the exact editor position.
	const b2 = midStackAtEditor(true)
	const a2 = app(b2)
	a2.mount()
	a2.dirty = true
	forward(b2, a2)
	a2.cancel()
	a2.popstate()
	assert.equal(b2.url, '#/template/7')
	assert.equal(b2.index, 1)
	assert.equal(a2.pending(), null)
	assert.equal(a2.dirty, true)
})

test('mid-stack mount without metadata: the editor stays unknown and still guards', () => {
	const b = midStackAtEditor(false)
	const a = app(b)
	a.mount()
	assert.equal(a.nav.currentPosition, null, 'no metadata -> unknown, never inferred')

	a.dirty = true
	forward(b, a)
	assert.notEqual(a.pending(), null, 'unknown position must still guard the draft')
	assert.equal(a.pending().destinationPosition, 2)

	a.cancel()
	assert.equal(b.url, '#/template/7')
	assert.equal(a.rendered.templateId, 7)
	assert.equal(a.dirty, true)
	assert.equal(a.pending(), null)
})

test('Cancel and Confirm from known and unknown mid-stack entries stay consistent', () => {
	// Known: confirm renders run 5 at index 2 without a history write.
	const bk = midStackAtEditor(true)
	const ak = app(bk)
	ak.mount()
	ak.dirty = true
	forward(bk, ak)
	ak.confirm()
	assert.equal(bk.url, '#/run/5')
	assert.equal(bk.index, 2)
	assert.equal(ak.dirty, false)

	// Unknown: cancel re-anchors the editor URL safely.
	const bu = midStackAtEditor(false)
	const au = app(bu)
	au.mount()
	au.dirty = true
	forward(bu, au)
	au.cancel()
	assert.equal(bu.url, '#/template/7')
	assert.equal(bu.index, 3, 'a new editor entry anchors the URL (documented trade-off)')
	assert.deepEqual(bu.hashes(), ['#templates', '#/template/7', '#/run/5', '#/template/7'])
	assert.equal(au.rendered.templateId, 7)
	assert.equal(au.dirty, true)
})

test('branching after a mid-stack mount truncates forward entries and Cancel is exact', () => {
	const b = midStackAtEditor(true)
	const a = app(b)
	a.mount() // editor at index 1, known position 1
	a.navigate(RUN6) // truncates run 5, appends run 6
	assert.deepEqual(b.hashes(), ['#templates', '#/template/7', '#/run/6'])
	assert.equal(b.index, 2)

	back(b, a) // editor
	a.dirty = true
	forward(b, a) // run 6
	assert.notEqual(a.pending(), null)
	a.cancel()
	a.popstate()
	assert.equal(b.url, '#/template/7')
	assert.equal(b.index, 1)
	assert.equal(a.dirty, true)
})

test('history state compatibility: plain object merged; other shapes preserved', () => {
	const obj = mergeAppPosition({ nc: { sidebar: true } }, 3)
	assert.deepEqual(obj.state, { nc: { sidebar: true }, runbookNav: { position: 3 } })
	assert.equal(obj.stored, true)

	const date = new Date('2024-01-01T00:00:00Z')
	const dateResult = mergeAppPosition(date, 3)
	assert.equal(dateResult.state, date, 'the same Date instance')
	assert.equal(dateResult.stored, false)

	const map = new Map([['a', 1]])
	assert.equal(mergeAppPosition(map, 3).state, map)
	assert.equal(mergeAppPosition(map, 3).stored, false)

	class ShellState { constructor(value) { this.value = value } }
	const instance = new ShellState('x')
	assert.equal(mergeAppPosition(instance, 3).state, instance)
	assert.equal(mergeAppPosition(instance, 3).stored, false)

	const array = [1, 2]
	assert.deepEqual(mergeAppPosition(array, 3).state, [1, 2])
	assert.equal(mergeAppPosition(array, 3).stored, false)
	assert.equal(mergeAppPosition('opaque', 3).state, 'opaque')
	assert.equal(mergeAppPosition('opaque', 3).stored, false)

	assert.equal(readAppPosition(date), null)
	assert.equal(readAppPosition(instance), null)
	assert.equal(readAppPosition([1, 2]), null)
})

test('initial mount preserves a Date state and reports unknown position', () => {
	const date = new Date('2024-01-01T00:00:00Z')
	const b = browser('#templates', date)
	const a = app(b)
	a.mount()
	assert.equal(b.state, date)
	assert.equal(a.nav.currentPosition, null)
})

test('Back -> Cancel -> Back, Back -> Forward-to-editor, duplicate hashes and deep links', () => {
	// Back -> Cancel -> Back.
	const b = browser('#templates', at(0))
	const a = app(b)
	a.mount()
	a.navigate(EDITOR)
	a.dirty = true
	back(b, a)
	a.cancel()
	a.popstate()
	assert.equal(b.url, '#/template/7')
	assert.equal(b.index, 1)
	assert.equal(a.dirty, true)
	back(b, a)
	assert.notEqual(a.pending(), null)

	// Back -> Forward-to-editor clears the dialog.
	const b2 = browser('#templates', at(0))
	const a2 = app(b2)
	a2.mount()
	a2.navigate(EDITOR)
	a2.dirty = true
	back(b2, a2)
	forward(b2, a2)
	assert.equal(a2.pending(), null)
	assert.equal(a2.dirty, true)

	// Duplicate hashes resolved by position.
	const b3 = browser('#templates', at(0))
	const a3 = app(b3)
	a3.mount()
	a3.navigate(EDITOR)
	b3.pushState('#/template/7', at(2))
	a3.nav = setCurrentPosition(a3.nav, 2)
	a3.dirty = true
	back(b3, a3)
	assert.equal(a3.nav.currentPosition, 1)
	assert.equal(a3.pending(), null)

	// Sidebar + one-shot step deep link (single prompt).
	const b4 = browser('#templates', at(0))
	const a4 = app(b4)
	a4.mount()
	a4.navigate(EDITOR)
	a4.dirty = true
	a4.navigate(RUN5)
	assert.notEqual(a4.pending(), null)
	a4.confirm()
	assert.equal(b4.url, '#/run/5')
	a4.dirty = false
	a4.navigate(STEP_LINK)
	assert.equal(b4.url, '#/run/12/step/34')
	assert.equal(a4.rendered.stepId, 34)
})

test('wiring: App.vue reads stored positions and never uses history.length as position', () => {
	const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..')
	const source = readFileSync(join(root, 'src', 'App.vue'), 'utf8')

	assert.ok(source.includes('readAppPosition(window.history.state)'), 'mount must read the stored position')
	assert.ok(source.includes('readAppPosition(event.state)'), 'popstate must read the stored position')
	assert.ok(source.includes('withoutAppPosition('), 'unknown positions must push without metadata')
	assert.ok(!source.includes('history.length - 1'), 'history.length must never be used as a position')
})
