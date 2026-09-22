/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the execution page (issue #27): section navigation,
 * deep-link resolution and display-only progress categories.
 *
 * The progress categories are derived from the run detail's section states and
 * step statuses so they stay disjoint and match `RunService::calculateProgress`
 * exactly, without changing the meaning of the existing progress fields.
 */

import type { translate } from '@nextcloud/l10n'
import type { RunDetail, RunSectionWithSteps, RunStep } from '../models/run.ts'

type Translate = typeof translate

export interface RunProgressDisplay {
	totalSteps: number
	totalSections: number
	completed: number
	skippedByUser: number
	outsidePath: number
	pending: number
	blocked: number
	resolved: number
	percentage: number
	hasWork: boolean
}

/**
 * Step progress of a single section (resolved steps / total steps).
 *
 * @param entry Section with its steps.
 */
export function sectionStepProgress(entry: RunSectionWithSteps): { done: number, total: number } {
	const done = entry.steps.filter((step) => step.status === 'COMPLETED' || step.status === 'SKIPPED').length

	return { done, total: entry.steps.length }
}

/**
 * Navigator progress text for a section. Steps in inapplicable sections are
 * outside the current path (never “0 of 1 completed”), and zero-step sections
 * get an explicit notice.
 *
 * @param t Translator.
 * @param entry Section with its steps.
 */
export function sectionProgressLabel(t: Translate, entry: RunSectionWithSteps): string {
	if (entry.state === 'inapplicable') {
		return t('runbook', 'Outside the current path')
	}

	const progress = sectionStepProgress(entry)
	if (progress.total === 0) {
		return t('runbook', 'No steps')
	}

	return `${progress.done}/${progress.total}`
}

/**
 * Disjoint, display-only progress categories.
 *
 * `skippedByUser` (status SKIPPED) and `outsidePath` (steps in inapplicable
 * sections) are reported separately; their sum equals the backend's merged
 * `progress.skipped`. `blocked` is a subset of `pending`.
 *
 * @param detail Run detail payload.
 */
export function runProgressDisplay(detail: RunDetail): RunProgressDisplay {
	const outsidePathSectionIds = new Set(detail.sections.filter((entry) => entry.state === 'inapplicable').map((entry) => entry.section.id))
	const blockedSectionIds = new Set(detail.sections.filter((entry) => entry.state === 'blocked').map((entry) => entry.section.id))

	let completed = 0
	let skippedByUser = 0
	let outsidePath = 0
	let pending = 0
	let blocked = 0

	for (const entry of detail.sections) {
		const outsidePathSection = outsidePathSectionIds.has(entry.section.id)
		const blockedSection = blockedSectionIds.has(entry.section.id)
		for (const step of entry.steps) {
			if (outsidePathSection) {
				outsidePath += 1
				continue
			}
			if (step.status === 'COMPLETED') {
				completed += 1
			} else if (step.status === 'SKIPPED') {
				skippedByUser += 1
			} else {
				pending += 1
				if (blockedSection) {
					blocked += 1
				}
			}
		}
	}

	return {
		totalSteps: detail.progress.total,
		totalSections: detail.sections.length,
		completed,
		skippedByUser,
		outsidePath,
		pending,
		blocked,
		resolved: completed + skippedByUser + outsidePath,
		percentage: detail.progress.percentage,
		hasWork: detail.progress.total > 0,
	}
}

/**
 * Default focused section: the active one, otherwise the first available
 * section with an executable step, otherwise the first section.
 *
 * @param detail Run detail payload.
 */
export function defaultSectionId(detail: RunDetail): number | null {
	const active = detail.sections.find((entry) => entry.state === 'active')
	if (active !== undefined) {
		return active.section.id
	}

	const executable = new Set(detail.permissions.executableStepIds)
	const available = detail.sections.find((entry) => entry.state === 'available' && entry.steps.some((step) => executable.has(step.id)))
	if (available !== undefined) {
		return available.section.id
	}

	return detail.sections[0]?.section.id ?? null
}

/**
 * Section that contains the given step.
 *
 * @param detail Run detail payload.
 * @param stepId Run step identifier.
 */
export function sectionOfStep(detail: RunDetail, stepId: number): RunSectionWithSteps | null {
	return detail.sections.find((entry) => entry.steps.some((step) => step.id === stepId)) ?? null
}

export interface NextAction {
	step: RunStep
	section: RunSectionWithSteps
}

/**
 * Every step the current user may act on right now (pending or in progress and
 * executable). Multiple parallel steps are returned so the user can choose one;
 * the UI never starts a step on its own.
 *
 * Returns no actions unless the run is ACTIVE: completed or cancelled runs are
 * read-only. The backend's `executableStepIds` remain the source of truth — this
 * is a presentation filter, not an authorization replacement.
 *
 * @param detail Run detail payload.
 */
export function nextActionableSteps(detail: RunDetail): NextAction[] {
	if (detail.run.status !== 'ACTIVE') {
		return []
	}

	const executable = new Set(detail.permissions.executableStepIds)
	const actions: NextAction[] = []
	for (const entry of detail.sections) {
		for (const step of entry.steps) {
			if (executable.has(step.id) && (step.status === 'PENDING' || step.status === 'IN_PROGRESS')) {
				actions.push({ step, section: entry })
			}
		}
	}

	return actions
}

export type NextActionHint
	= | { kind: 'idle' }
		| { kind: 'single', step: RunStep, sectionId: number }
		| { kind: 'options', startable: number, continuable: number }
		| { kind: 'ready' }
		| { kind: 'waiting' }

/**
 * Headline hint for the next permitted action.
 *
 * - `idle`: not active, or a run without work — no next-action message is shown.
 * - `single`: exactly one actionable step (startable or in progress).
 * - `options`: several actionable steps; pending and in-progress are counted
 *   separately so “in progress” is never described as “can be started”.
 * - `ready`: nothing left to act on and the run may be completed.
 * - `waiting`: nothing actionable yet, but the run is not ready to complete.
 *
 * @param detail Run detail payload.
 */
export function nextActionHint(detail: RunDetail): NextActionHint {
	if (detail.run.status !== 'ACTIVE') {
		return { kind: 'idle' }
	}

	const actions = nextActionableSteps(detail)
	if (actions.length === 1 && actions[0] !== undefined) {
		return { kind: 'single', step: actions[0].step, sectionId: actions[0].section.section.id }
	}
	if (actions.length > 1) {
		const startable = actions.filter((action) => action.step.status === 'PENDING').length
		const continuable = actions.filter((action) => action.step.status === 'IN_PROGRESS').length

		return { kind: 'options', startable, continuable }
	}

	if (detail.progress.total === 0) {
		return { kind: 'idle' }
	}
	if (detail.progress.canComplete) {
		return { kind: 'ready' }
	}

	return { kind: 'waiting' }
}
