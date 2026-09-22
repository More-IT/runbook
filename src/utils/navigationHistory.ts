/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure navigation-history controller for the app shell (issue #29 review fix).
 *
 * Entries are identified by their **physical position** in the browser stack
 * (see `historyStack.ts`), not by a monotonic app id. Cancel therefore uses
 * `history.go(editorPosition - destinationPosition)`, which is a valid step count
 * even after a branch truncated forward entries, because surviving entries never
 * change position.
 *
 * Foreign entries (no stored position, or a state shape we cannot merge into)
 * are never guessed: Cancel re-anchors the editor URL with a new entry instead.
 */

import type { PushPlan } from './historyStack.ts'
import type { NavTarget } from './navigationGuard.ts'

import { cancelNavigation, confirmNavigation } from './navigationController.ts'
import { hashForTarget, isCurrentTarget, shouldGuardNavigation } from './navigationGuard.ts'

export interface EditorDraftInput {
	/** Template currently being edited, or null. */
	templateId: number | null
	/** Whether the editor currently has unsaved drafts. */
	dirty: boolean
}

export interface PendingDecision {
	target: NavTarget
	destinationHash: string
	/** Physical position of the destination, or null for a foreign entry. */
	destinationPosition: number | null
	source: 'pop' | 'request'
}

export interface NavHistoryState {
	/** Physical position of the current browser entry, or null if unknown. */
	currentPosition: number | null
	/** Physical position of the editor entry we are currently rendering. */
	editorPosition: number | null
	/** Deferred decision behind the unsaved-changes prompt. */
	pending: PendingDecision | null
}

export interface PopInput {
	hash: string
	/** Physical position of the destination, or null for a foreign entry. */
	position: number | null
	/** Parsed target for the destination hash. */
	target: NavTarget
	/** Target the app is currently rendering. */
	renderedTarget: NavTarget
}

export interface HistoryTransition {
	state: NavHistoryState
	/** Target to render, or null when nothing changes. */
	render: NavTarget | null
	/** Push a new entry and render its target (programmatic navigation/confirm). */
	push: PushPlan | null
	/** Push a new entry for the current editor URL only (foreign-destination cancel). */
	anchor: PushPlan | null
	/** Relative movement for `history.go` (0 when none). */
	jump: number
	/** Whether the editor drafts must be discarded. */
	discard: boolean
	/** Whether a pending decision was cleared automatically. */
	clearPending: boolean
}

/**
 * A no-op transition for the given state.
 *
 * @param state Current navigation-history state.
 */
function idle(state: NavHistoryState): HistoryTransition {
	return {
		state,
		render: null,
		push: null,
		anchor: null,
		jump: 0,
		discard: false,
		clearPending: false,
	}
}

/**
 * Create the initial navigation-history state.
 */
export function createNavHistory(): NavHistoryState {
	return { currentPosition: null, editorPosition: null, pending: null }
}

/**
 * Record the physical position of the entry loaded at mount.
 *
 * @param state Current state.
 * @param position Physical position of the current entry.
 */
export function setCurrentPosition(state: NavHistoryState, position: number | null): NavHistoryState {
	return { ...state, currentPosition: position }
}

/**
 * Record whether the current entry is the editor entry.
 *
 * @param state Current state.
 * @param target Rendered target.
 */
export function markCurrentEntry(state: NavHistoryState, target: NavTarget): NavHistoryState {
	return { ...state, editorPosition: target.templateId !== null ? state.currentPosition : null }
}

/**
 * Programmatic navigation (sidebar, template switch, run links, close).
 *
 * @param state Current state.
 * @param target Intended target.
 * @param editor Editor draft input.
 * @param targetHash Hash of the intended target.
 * @param basePosition Physical position of the current entry.
 */
export function requestHistory(
	state: NavHistoryState,
	target: NavTarget,
	editor: EditorDraftInput,
	targetHash: string,
	basePosition: number | null,
): HistoryTransition {
	if (shouldGuardNavigation({ templateId: editor.templateId, hasDrafts: editor.dirty }, target)) {
		return idle({
			...state,
			pending: { target, destinationHash: targetHash, destinationPosition: null, source: 'request' },
		})
	}

	const position = basePosition === null ? null : basePosition + 1

	return {
		state: { currentPosition: position, editorPosition: target.templateId !== null ? position : null, pending: null },
		render: target,
		push: { hash: targetHash, position },
		anchor: null,
		jump: 0,
		discard: false,
		clearPending: false,
	}
}

/**
 * Browser Back/Forward (`popstate`).
 *
 * @param state Current state.
 * @param pop Popstate input (hash, physical position, targets).
 * @param editor Editor draft input.
 */
export function handlePop(state: NavHistoryState, pop: PopInput, editor: EditorDraftInput): HistoryTransition {
	const current: NavHistoryState = { ...state, currentPosition: pop.position }
	const returningToRendered = isCurrentTarget(pop.target, pop.renderedTarget)
		|| pop.hash === hashForTarget(pop.renderedTarget)

	// Returning to the entry we are still rendering (e.g. Back then Forward while
	// the dialog is open): keep the editor and drafts, close the dialog.
	if (returningToRendered) {
		const cleared = state.pending !== null
		return {
			...idle({ ...current, pending: null, editorPosition: pop.position ?? state.editorPosition }),
			clearPending: cleared,
		}
	}

	const target = pop.target

	// A dirty editor always guards leaving it, even when its physical position is
	// unknown (unknown must never mean “safe to leave”).
	if (editor.templateId !== null && editor.dirty) {
		return idle({
			...current,
			pending: {
				target,
				destinationHash: pop.hash,
				destinationPosition: pop.position,
				source: 'pop',
			},
		})
	}

	return {
		...idle({ ...current, pending: null, editorPosition: target.templateId !== null ? pop.position : null }),
		render: target,
	}
}

/**
 * Confirm the pending navigation. A pop destination is already the current URL;
 * a programmatic destination is pushed before rendering.
 *
 * @param state Current state.
 * @param editor Editor draft input.
 * @param basePosition Physical position of the current entry.
 */
export function confirmHistory(state: NavHistoryState, editor: EditorDraftInput, basePosition: number | null): HistoryTransition {
	const outcome = confirmNavigation({
		editorTemplateId: editor.templateId,
		hasDrafts: editor.dirty,
		pending: state.pending?.target ?? null,
	})
	if (outcome.navigate === null) {
		return idle({ ...state, pending: null })
	}

	const target = outcome.navigate
	if (state.pending !== null && state.pending.source === 'request') {
		const position = basePosition === null ? null : basePosition + 1
		return {
			state: { currentPosition: position, editorPosition: target.templateId !== null ? position : null, pending: null },
			render: target,
			push: { hash: state.pending.destinationHash, position },
			anchor: null,
			jump: 0,
			discard: outcome.discard,
			clearPending: true,
		}
	}

	return {
		state: {
			...state,
			pending: null,
			editorPosition: target.templateId !== null ? state.currentPosition : null,
		},
		render: target,
		push: null,
		anchor: null,
		jump: 0,
		discard: outcome.discard,
		clearPending: true,
	}
}

/**
 * Cancel the pending navigation: keep the editor and drafts.
 *
 * A tracked destination (both positions known) moves the browser with the exact
 * physical distance. Otherwise the URL is re-anchored with a new editor entry,
 * which never issues a guessed `history.go`.
 *
 * @param state Current state.
 * @param editor Editor draft input.
 * @param editorHash Hash of the editor entry currently rendered.
 * @param basePosition Physical position of the current entry.
 */
export function cancelHistory(
	state: NavHistoryState,
	editor: EditorDraftInput,
	editorHash: string,
	basePosition: number | null,
): HistoryTransition {
	const outcome = cancelNavigation({
		editorTemplateId: editor.templateId,
		hasDrafts: editor.dirty,
		pending: state.pending?.target ?? null,
	})
	if (!outcome.restore) {
		return idle({ ...state, pending: null })
	}

	if (state.pending !== null
		&& state.pending.source === 'pop'
		&& state.pending.destinationPosition !== null
		&& state.editorPosition !== null) {
		return {
			...idle({ ...state, pending: null }),
			jump: state.editorPosition - state.pending.destinationPosition,
			clearPending: true,
		}
	}

	// Foreign or unbranchable destination: re-anchor the editor with a new entry.
	const position = basePosition === null ? null : basePosition + 1
	return {
		state: { currentPosition: position, editorPosition: position, pending: null },
		render: null,
		push: null,
		anchor: { hash: editorHash, position },
		jump: 0,
		discard: false,
		clearPending: true,
	}
}
