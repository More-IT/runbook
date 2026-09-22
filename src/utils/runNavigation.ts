/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure navigation and load-sequencing state for the execution page (issue #27).
 *
 * The navigation state is scoped to a single run. A deep link is a one-shot
 * navigation *intent*: it is applied once, when the matching run's detail is
 * loaded, and then cleared. Reloading the same run after a mutation must not
 * reselect the deep-linked section — the user's later section choice is
 * preserved. Detail from another run is never reconciled and never consumes the
 * intent.
 */

import type { RunDetail } from '../models/run.ts'

import { defaultSectionId, sectionOfStep } from './runExecution.ts'

export interface RunNavigationState {
	/** Run this navigation state belongs to. */
	runId: number | null
	selectedSectionId: number | null
	pendingFocusStepId: number | null
}

export interface RunNavigationLoad {
	state: RunNavigationState
	focusStepId: number | null
}

/**
 * Initial (empty) navigation state.
 */
export function emptyNavigation(): RunNavigationState {
	return { runId: null, selectedSectionId: null, pendingFocusStepId: null }
}

/**
 * Record a navigation intent for a run without selecting anything yet.
 *
 * If the state belongs to another run it is replaced, so an intent for run B can
 * never be consumed by a still-loaded run A.
 *
 * @param state Current navigation state.
 * @param runId Run the intent belongs to.
 * @param stepId Step to reveal once the matching run is loaded.
 */
export function requestFocus(state: RunNavigationState, runId: number, stepId: number | null): RunNavigationState {
	if (state.runId !== runId) {
		return { runId, selectedSectionId: null, pendingFocusStepId: stepId }
	}

	return { runId, selectedSectionId: state.selectedSectionId, pendingFocusStepId: stepId }
}

/**
 * Select a section explicitly (user or “Go to step”).
 *
 * @param state Current navigation state.
 * @param sectionId Run section identifier.
 */
export function selectSection(state: RunNavigationState, sectionId: number): RunNavigationState {
	return { runId: state.runId, selectedSectionId: sectionId, pendingFocusStepId: state.pendingFocusStepId }
}

/**
 * Reconcile the navigation state with a freshly loaded run detail.
 *
 * Detail whose `run.id` does not match the state's run is ignored entirely: it
 * neither changes the selection nor consumes the pending intent. For the
 * matching run, the current selection is kept when it still exists (otherwise
 * the default section is used), and a pending focus intent is applied once if
 * its step exists. A pending intent is only cleared once the matching run has
 * loaded.
 *
 * @param state Current navigation state.
 * @param detail Loaded run detail.
 */
export function applyLoad(state: RunNavigationState, detail: RunDetail): RunNavigationLoad {
	if (state.runId === null || state.runId !== detail.run.id) {
		return { state, focusStepId: null }
	}

	let selectedSectionId = state.selectedSectionId
	if (selectedSectionId === null || !detail.sections.some((entry) => entry.section.id === selectedSectionId)) {
		selectedSectionId = defaultSectionId(detail)
	}

	let focusStepId: number | null = null
	if (state.pendingFocusStepId !== null) {
		const entry = sectionOfStep(detail, state.pendingFocusStepId)
		if (entry !== null) {
			selectedSectionId = entry.section.id
			focusStepId = state.pendingFocusStepId
		}
	}

	return {
		state: { runId: state.runId, selectedSectionId, pendingFocusStepId: null },
		focusStepId,
	}
}

export interface RunLoadSequence {
	sequence: number
	latest: number
}

/**
 * Start a new load and obtain its sequence number.
 *
 * @param latest Latest started sequence.
 */
export function beginLoad(latest: number): RunLoadSequence {
	const sequence = latest + 1

	return { sequence, latest: sequence }
}

/**
 * Whether a started load is still the newest one and may commit its result.
 *
 * @param sequence Sequence of the finished load.
 * @param latest Latest started sequence.
 */
export function isStaleLoad(sequence: number, latest: number): boolean {
	return sequence !== latest
}
