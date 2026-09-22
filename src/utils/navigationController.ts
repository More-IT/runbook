/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure lifecycle reducer for the app-shell navigation guard (issue #29).
 *
 * It models the exact transitions App.vue performs so the "single decision"
 * behaviour can be tested without a browser: a guarded request is deferred, a
 * confirm consumes the pending target once (and discards drafts), and a cancel
 * clears the pending target while leaving the editor in place.
 */

import type { NavTarget } from './navigationGuard.ts'

import { shouldGuardNavigation } from './navigationGuard.ts'

export interface NavGuardState {
	/** Template currently being edited, or null. */
	editorTemplateId: number | null
	/** Whether the editor currently has unsaved drafts (read synchronously). */
	hasDrafts: boolean
	/** Target deferred until the user decides. */
	pending: NavTarget | null
}

export interface RequestOutcome {
	state: NavGuardState
	/** Target to apply immediately, or null when deferred. */
	apply: NavTarget | null
}

/**
 * Request a navigation: apply it immediately, or defer it behind the guard.
 *
 * @param state Current guard state.
 * @param target Intended target.
 */
export function requestNavigation(state: NavGuardState, target: NavTarget): RequestOutcome {
	if (shouldGuardNavigation({ templateId: state.editorTemplateId, hasDrafts: state.hasDrafts }, target)) {
		return { state: { ...state, pending: target }, apply: null }
	}

	return { state: { ...state, pending: null }, apply: target }
}

export interface DecisionOutcome {
	state: NavGuardState
	/** Target to navigate to, or null when there is nothing pending. */
	navigate: NavTarget | null
	/** Whether the editor drafts must be discarded. */
	discard: boolean
	/** Whether the URL must be restored to the editor. */
	restore: boolean
}

/**
 * Confirm the pending navigation. The pending target is consumed exactly once,
 * so a later confirmation cannot re-trigger navigation or a second dialog.
 *
 * @param state Current guard state.
 */
export function confirmNavigation(state: NavGuardState): DecisionOutcome {
	if (state.pending === null) {
		return { state, navigate: null, discard: false, restore: false }
	}

	return { state: { ...state, pending: null }, navigate: state.pending, discard: true, restore: false }
}

/**
 * Cancel the pending navigation: keep the editor (and its drafts) and restore
 * the URL to the editor route.
 *
 * @param state Current guard state.
 */
export function cancelNavigation(state: NavGuardState): DecisionOutcome {
	return {
		state: { ...state, pending: null },
		navigate: null,
		discard: false,
		restore: state.pending !== null,
	}
}
