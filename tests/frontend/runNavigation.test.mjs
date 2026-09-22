/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Interaction-level regression tests for execution-page navigation and the
 * load lifecycle (issue #27).
 *
 * No component test framework is available in this repository, so these tests
 * drive the exact state transitions RunDetailView uses (requestFocus,
 * applyLoad, selectSection, beginLoad/isStaleLoad) in the same order as its
 * watchers and load sequence. They are not browser/DOM tests.
 */

import assert from 'node:assert/strict'
import test from 'node:test'

import {
	applyLoad,
	beginLoad,
	emptyNavigation,
	isStaleLoad,
	requestFocus,
	selectSection,
} from '../../src/utils/runNavigation.ts'

/**
 * @param {number} id Step id.
 * @param {string} status Step status.
 */
function step(id, status) {
	return { id, runSectionId: 0, status, title: `Step ${id}` }
}

/**
 * @param {number} id Section id.
 * @param {string} state Section state.
 * @param {Array<object>} steps Section steps.
 */
function section(id, state, steps) {
	return { section: { id, title: `Section ${id}`, position: id }, steps, state, blockedBy: [], reason: [] }
}

/**
 * @param {number} id Run id.
 * @param {Array<object>} sections Sections with steps.
 * @param {string} status Run status.
 */
function detail(id, sections, status = 'ACTIVE') {
	return {
		run: { id, status },
		sections,
		progress: { total: sections.reduce((total, entry) => total + entry.steps.length, 0), completed: 0, skipped: 0, pending: 0, percentage: 0, canComplete: false },
		permissions: { executableStepIds: [], uid: 'alice', role: 'OWNER', canManage: true, canModify: true, canCancel: true, canReopen: false, canManageAssignments: true, canComment: true, canDelete: true },
	}
}

const runA = () => detail(1, [section(1, 'available', [step(11, 'PENDING')])])
const runB = () => detail(2, [
	section(21, 'active', [step(211, 'IN_PROGRESS'), step(212, 'PENDING')]),
	section(22, 'available', [step(221, 'PENDING')]),
])

test('run A to run B with a deep link to a step in B never consumes the intent against A', () => {
	// Run A is loaded and a section is selected.
	let state = applyLoad(requestFocus(emptyNavigation(), 1, null), runA()).state
	assert.equal(state.runId, 1)

	// Navigate to run B with a deep link to step 221 (section 22).
	state = requestFocus(state, 2, 221)
	assert.equal(state.runId, 2)
	assert.equal(state.pendingFocusStepId, 221)

	// Run A's detail is still loaded: reconciling must not clear the intent.
	let result = applyLoad(state, runA())
	assert.equal(result.focusStepId, null)
	assert.equal(result.state.pendingFocusStepId, 221)
	assert.equal(result.state.runId, 2)
	state = result.state

	// Run B loads: the intent is now applied once.
	result = applyLoad(state, runB())
	assert.equal(result.state.selectedSectionId, 22)
	assert.equal(result.focusStepId, 221)
	assert.equal(result.state.pendingFocusStepId, null)
})

test('a focus-step prop change while B is loading is honoured when B arrives', () => {
	let state = requestFocus(emptyNavigation(), 2, 221)
	// The prop changes before run B finishes loading.
	state = requestFocus(state, 2, 212)
	assert.equal(state.pendingFocusStepId, 212)

	const result = applyLoad(state, runB())
	assert.equal(result.state.selectedSectionId, 21)
	assert.equal(result.focusStepId, 212)
})

test('out-of-order A and B responses: only the newest load may commit', () => {
	let latest = 0
	const first = beginLoad(latest)
	latest = first.latest
	const second = beginLoad(latest)
	latest = second.latest

	assert.equal(first.sequence, 1)
	assert.equal(second.sequence, 2)

	// A (older) resolves after B started: it is stale and must not be used.
	assert.equal(isStaleLoad(first.sequence, latest), true)
	// B (newest) resolves: it may commit.
	assert.equal(isStaleLoad(second.sequence, latest), false)

	// The stale A detail is ignored and cannot consume B's intent either.
	let state = requestFocus(emptyNavigation(), 2, 221)
	let result = applyLoad(state, runA())
	assert.equal(result.state.pendingFocusStepId, 221)
	assert.equal(result.focusStepId, null)

	result = applyLoad(result.state, runB())
	assert.equal(result.state.selectedSectionId, 22)
	assert.equal(result.focusStepId, 221)
})

test('an invalid step id is discarded only after the matching run has loaded', () => {
	const state = requestFocus(emptyNavigation(), 2, 9999)

	// Still loading another run: the intent is kept.
	let result = applyLoad(state, runA())
	assert.equal(result.state.pendingFocusStepId, 9999)

	// The matching run loaded and the step is genuinely absent: discard safely.
	result = applyLoad(result.state, runB())
	assert.equal(result.state.selectedSectionId, 21)
	assert.equal(result.focusStepId, null)
	assert.equal(result.state.pendingFocusStepId, null)
})

test('a manual section selection survives a mutation reload of the same run', () => {
	let state = applyLoad(requestFocus(emptyNavigation(), 2, 221), runB()).state
	assert.equal(state.selectedSectionId, 22)

	state = selectSection(state, 21)
	assert.equal(state.selectedSectionId, 21)

	const result = applyLoad(state, runB())
	assert.equal(result.state.selectedSectionId, 21)
	assert.equal(result.focusStepId, null)
	assert.equal(result.state.pendingFocusStepId, null)
})

test('selectSection keeps an unfulfilled intent and the run scope', () => {
	const state = selectSection(requestFocus(emptyNavigation(), 2, 221), 21)
	assert.equal(state.runId, 2)
	assert.equal(state.selectedSectionId, 21)
	assert.equal(state.pendingFocusStepId, 221)
})

test('detail from another run never changes selection or consumes the intent', () => {
	const state = requestFocus(emptyNavigation(), 2, 221)
	const result = applyLoad(state, runA())
	assert.deepEqual(result.state, state)
	assert.equal(result.focusStepId, null)
})
