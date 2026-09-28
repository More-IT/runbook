/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * State-transition tests for the Start run dialog submission controller
 * (issue #49). These are not mounted-component tests: the repository has no DOM
 * harness, so the `NcDialog` dismissal wiring is covered by source assertions in
 * `startRun.test.mjs`.
 */

import assert from 'node:assert/strict'
import test from 'node:test'

import { createStartRunSubmission } from '../../src/utils/startRunSubmission.ts'

function deferred() {
	let resolve
	let reject
	const promise = new Promise((res, rej) => {
		resolve = res
		reject = rej
	})
	return { promise, resolve, reject }
}

function harness(overrides = {}) {
	const calls = { start: 0, started: [], close: 0, busy: [], errors: [] }
	const pending = []
	const base = {
		startRun: () => {
			calls.start++
			const gate = deferred()
			pending.push(gate)
			return gate.promise
		},
		onStarted: (id) => calls.started.push(id),
		onClose: () => {
			calls.close++
		},
		describeError: (error) => `E:${error.message}`,
		onBusyChange: (value) => calls.busy.push(value),
		onErrorChange: (value) => calls.errors.push(value),
	}
	return { controller: createStartRunSubmission({ ...base, ...overrides }), calls, pending }
}

test('a successful start emits started once and clears busy', async () => {
	const { controller, calls, pending } = harness()

	const submission = controller.submit()
	assert.equal(controller.isBusy(), true, 'busy while the request is pending')

	pending[0].resolve({ id: 7 })
	await submission

	assert.deepEqual(calls.started, [7], 'started is emitted exactly once')
	assert.equal(controller.isBusy(), false)
	assert.equal(controller.isCompleted(), true)
	assert.deepEqual(calls.busy, [true, false], 'busy only toggles once')
})

test('rapid repeated submits produce exactly one request', async () => {
	const { controller, calls, pending } = harness()

	const first = controller.submit()
	const second = controller.submit()
	const third = controller.submit()

	assert.equal(calls.start, 1, 'only one API request is made')

	pending[0].resolve({ id: 1 })
	await Promise.all([first, second, third])

	assert.equal(calls.start, 1)
	assert.deepEqual(calls.started, [1])
})

test('a completed start never starts another request', async () => {
	const { controller, calls, pending } = harness()

	const submission = controller.submit()
	pending[0].resolve({ id: 3 })
	await submission

	await controller.submit()

	assert.equal(calls.start, 1)
	assert.deepEqual(calls.started, [3])
})

test('a failed start keeps the dialog open, reports the error and allows retry', async () => {
	const { controller, calls, pending } = harness()

	const submission = controller.submit()
	pending[0].reject(new Error('boom'))
	await submission

	assert.equal(controller.isBusy(), false, 'interactivity is restored')
	assert.equal(controller.isCompleted(), false)
	assert.equal(calls.close, 0, 'the dialog is not closed on failure')
	assert.ok(calls.errors.includes('E:boom'), 'the mapped error is surfaced')

	const retry = controller.submit()
	pending[1].resolve({ id: 9 })
	await retry

	assert.equal(calls.start, 2, 'the user can retry')
	assert.deepEqual(calls.started, [9])
})

test('dismissal is ignored while busy and honoured when idle', async () => {
	const { controller, calls, pending } = harness()

	const submission = controller.submit()
	assert.equal(controller.isBusy(), true)
	controller.requestClose()
	controller.requestClose()
	assert.equal(calls.close, 0, 'dismissal while busy is ignored, never faked')

	pending[0].reject(new Error('boom'))
	await submission

	controller.requestClose()
	assert.equal(calls.close, 1, 'dismissal is honoured once idle again')
})

test('form values are preserved across a failed retry', async () => {
	const form = { title: 'Deploy', destinationPath: '/Shared' }
	const seen = []
	const controller = createStartRunSubmission({
		startRun: async () => {
			seen.push({ ...form })
			if (seen.length === 1) {
				throw new Error('boom')
			}
			return { id: 5 }
		},
		onStarted: () => {},
		onClose: () => {},
		describeError: (error) => error.message,
		onBusyChange: () => {},
		onErrorChange: () => {},
	})

	await controller.submit()
	assert.deepEqual(seen[0], { title: 'Deploy', destinationPath: '/Shared' })
	assert.deepEqual(form, { title: 'Deploy', destinationPath: '/Shared' }, 'the controller never mutates the form')

	await controller.submit()
	assert.deepEqual(seen[1], { title: 'Deploy', destinationPath: '/Shared' }, 'retry uses the same values')
})
