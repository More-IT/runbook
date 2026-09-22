/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Frontend tests for the execution-page helpers (issue #27): section
 * navigation, deep-link resolution and the display-only progress categories.
 */

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import { translate } from '@nextcloud/l10n'

import {
	defaultSectionId,
	nextActionHint,
	nextActionableSteps,
	runProgressDisplay,
	sectionOfStep,
	sectionProgressLabel,
	sectionStepProgress,
} from '../../src/utils/runExecution.ts'

const here = dirname(fileURLToPath(import.meta.url))
const root = join(here, '..', '..')

const catalogs = {}
for (const locale of ['en', 'pt_PT']) {
	catalogs[locale] = JSON.parse(readFileSync(join(root, 'l10n', `${locale}.json`), 'utf8')).translations
}

/**
 * @param {string} locale Locale identifier.
 */
function useLocale(locale) {
	globalThis._oc_l10n_registry_translations ??= {}
	globalThis._oc_l10n_registry_plural_functions ??= {}
	globalThis._oc_l10n_registry_translations.runbook = catalogs[locale]
	globalThis._oc_l10n_registry_plural_functions.runbook = (n) => (n === 1 ? 0 : 1)
}

/**
 * @param {number} id Step id.
 * @param {string} status Step status.
 */
function step(id, status) {
	return { id, runSectionId: 0, status, title: `Step ${id}`, type: 'TEXT', required: true, position: id }
}

/**
 * @param {number} id Section id.
 * @param {string} state Backend section state.
 * @param {Array<object>} steps Section steps.
 */
function section(id, state, steps) {
	return { section: { id, title: `Section ${id}`, position: id }, steps, state, blockedBy: [], reason: [] }
}

/**
 * Build a run detail payload whose progress mirrors RunService::calculateProgress.
 *
 * @param {Array<object>} sections Sections with steps.
 * @param {Array<number>} executable Executable step ids.
 * @param {string} status Run status.
 */
function detail(sections, executable = [], status = 'ACTIVE') {
	let completed = 0
	let skipped = 0
	let pending = 0
	let total = 0
	for (const entry of sections) {
		const inapplicable = entry.state === 'inapplicable'
		for (const current of entry.steps) {
			total += 1
			if (inapplicable) {
				skipped += 1
			} else if (current.status === 'COMPLETED') {
				completed += 1
			} else if (current.status === 'SKIPPED') {
				skipped += 1
			} else {
				pending += 1
			}
		}
	}

	return {
		run: { status },
		sections,
		progress: {
			total,
			completed,
			skipped,
			pending,
			percentage: total === 0 ? 100 : Math.floor(((completed + skipped) / total) * 100),
			canComplete: false,
		},
		permissions: {
			uid: 'alice',
			role: 'OWNER',
			canManage: true,
			canModify: true,
			canCancel: true,
			canReopen: false,
			canManageAssignments: true,
			canComment: true,
			canDelete: true,
			executableStepIds: executable,
		},
	}
}

test('section step progress counts resolved steps', () => {
	assert.deepEqual(sectionStepProgress(section(1, 'available', [step(1, 'COMPLETED'), step(2, 'SKIPPED'), step(3, 'PENDING')])), { done: 2, total: 3 })
	assert.deepEqual(sectionStepProgress(section(2, 'resolved', [])), { done: 0, total: 0 })
})

test('progress categories are disjoint and match the backend totals', () => {
	const run = detail([
		section(1, 'resolved', [step(1, 'COMPLETED')]),
		section(2, 'inapplicable', [step(2, 'PENDING')]),
		section(3, 'available', [step(3, 'SKIPPED'), step(4, 'PENDING')]),
	], [4])

	const display = runProgressDisplay(run)

	assert.equal(display.totalSections, 3)
	assert.equal(display.totalSteps, 4)
	assert.equal(display.completed, 1)
	assert.equal(display.skippedByUser, 1)
	assert.equal(display.outsidePath, 1)
	assert.equal(display.pending, 1)
	assert.equal(display.resolved, 3)
	assert.equal(display.percentage, run.progress.percentage)
	// The merged backend `skipped` is split, never relabelled as user-skipped.
	assert.equal(display.skippedByUser + display.outsidePath, run.progress.skipped)
	assert.equal(display.completed, run.progress.completed)
})

test('steps in inapplicable sections are never counted as user-skipped or completed', () => {
	const run = detail([
		section(1, 'inapplicable', [step(1, 'COMPLETED'), step(2, 'SKIPPED')]),
	], [])
	const display = runProgressDisplay(run)

	assert.equal(display.outsidePath, 2)
	assert.equal(display.skippedByUser, 0)
	assert.equal(display.completed, 0)
})

test('blocked steps are a subset of pending work', () => {
	const run = detail([
		section(1, 'blocked', [step(1, 'PENDING'), step(2, 'PENDING')]),
		section(2, 'available', [step(3, 'PENDING')]),
	], [3])
	const display = runProgressDisplay(run)

	assert.equal(display.pending, 3)
	assert.equal(display.blocked, 2)
})

test('a run with no steps reports no work instead of 100% complete', () => {
	const run = detail([section(1, 'resolved', [])])
	const display = runProgressDisplay(run)

	assert.equal(display.totalSteps, 0)
	assert.equal(display.totalSections, 1)
	assert.equal(display.hasWork, false)
})

test('default section prefers active, then available with an executable step, then first', () => {
	const active = detail([section(1, 'resolved', [step(1, 'COMPLETED')]), section(2, 'active', [step(2, 'IN_PROGRESS')])], [2])
	assert.equal(defaultSectionId(active), 2)

	const available = detail([section(1, 'blocked', [step(1, 'PENDING')]), section(2, 'available', [step(2, 'PENDING')])], [2])
	assert.equal(defaultSectionId(available), 2)

	const fallback = detail([section(1, 'blocked', [step(1, 'PENDING')])], [])
	assert.equal(defaultSectionId(fallback), 1)

	assert.equal(defaultSectionId(detail([])), null)
})

test('next actionable steps expose every parallel option without starting one', () => {
	const run = detail([
		section(1, 'available', [step(1, 'PENDING'), step(2, 'IN_PROGRESS'), step(3, 'COMPLETED')]),
		section(2, 'available', [step(4, 'PENDING')]),
	], [1, 2, 4])

	const actions = nextActionableSteps(run)
	assert.deepEqual(actions.map((action) => action.step.id), [1, 2, 4])
	assert.equal(actions[0].section.section.id, 1)
})

test('no actions are exposed when the user cannot execute any step', () => {
	const run = detail([section(1, 'available', [step(1, 'PENDING')])], [])
	assert.deepEqual(nextActionableSteps(run), [])
})

test('sectionOfStep resolves the owning section and scales to many sections', () => {
	const sections = []
	const executable = []
	for (let index = 1; index <= 20; index += 1) {
		const sectionSteps = []
		for (let offset = 1; offset <= 3; offset += 1) {
			const id = index * 10 + offset
			sectionSteps.push(step(id, 'PENDING'))
		}
		sections.push(section(index, index === 1 ? 'available' : 'blocked', sectionSteps))
		executable.push(index * 10 + 1)
	}
	const run = detail(sections, executable)

	assert.equal(run.sections.length, 20)
	assert.equal(run.progress.total, 60)
	assert.equal(sectionOfStep(run, 102)?.section.id, 10)
	assert.equal(sectionOfStep(run, 9999), null)
	assert.equal(nextActionableSteps(run).length, 20)
})

test('next actionable steps are empty for completed and cancelled runs', () => {
	const completed = detail([section(1, 'active', [step(1, 'PENDING')])], [1], 'COMPLETED')
	const cancelled = detail([section(1, 'active', [step(1, 'PENDING')])], [1], 'CANCELLED')
	assert.deepEqual(nextActionableSteps(completed), [])
	assert.deepEqual(nextActionableSteps(cancelled), [])
})

test('next action hint is idle for read-only runs', () => {
	for (const status of ['COMPLETED', 'CANCELLED']) {
		assert.equal(nextActionHint(detail([section(1, 'active', [step(1, 'PENDING')])], [1], status)).kind, 'idle')
	}
})

test('next action hint distinguishes startable from in-progress steps', () => {
	const run = detail([section(1, 'available', [step(1, 'PENDING'), step(2, 'IN_PROGRESS')])], [1, 2])
	const hint = nextActionHint(run)
	assert.equal(hint.kind, 'options')
	assert.equal(hint.startable, 1)
	assert.equal(hint.continuable, 1)
})

test('next action hint points at a single actionable step', () => {
	const run = detail([section(1, 'available', [step(1, 'PENDING'), step(2, 'COMPLETED')])], [1])
	const hint = nextActionHint(run)
	assert.equal(hint.kind, 'single')
	assert.equal(hint.step.id, 1)
})

test('next action hint is ready when the run can be completed', () => {
	const run = detail([section(1, 'resolved', [step(1, 'COMPLETED')])], [])
	run.progress.canComplete = true
	assert.equal(nextActionHint(run).kind, 'ready')
})

test('next action hint waits while work remains and completion is blocked', () => {
	const run = detail([section(1, 'blocked', [step(1, 'PENDING')])], [])
	assert.equal(nextActionHint(run).kind, 'waiting')
})

test('next action hint is idle for an active run without steps', () => {
	const run = detail([section(1, 'resolved', [])], [])
	assert.equal(nextActionHint(run).kind, 'idle')
})

test('navigator progress marks inapplicable and zero-step sections', () => {
	useLocale('en')
	assert.equal(sectionProgressLabel(translate, section(1, 'inapplicable', [step(1, 'PENDING')])), 'Outside the current path')
	assert.equal(sectionProgressLabel(translate, section(2, 'resolved', [])), 'No steps')
	assert.equal(sectionProgressLabel(translate, section(3, 'available', [step(2, 'COMPLETED'), step(3, 'PENDING')])), '1/2')

	useLocale('pt_PT')
	assert.equal(sectionProgressLabel(translate, section(1, 'inapplicable', [step(1, 'PENDING')])), 'Fora do caminho atual')
	assert.equal(sectionProgressLabel(translate, section(2, 'resolved', [])), 'Sem passos')
})
