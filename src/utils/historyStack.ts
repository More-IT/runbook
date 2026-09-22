/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Per-entry history metadata for the app shell (issue #29 review fix).
 *
 * Each app entry stores its **physical position** (the browser's 0-based index
 * in the current history stack) in `history.state` under a namespaced key. A
 * physical position is the correct value for `history.go(delta)`; a monotonic
 * app id is not, because a later navigation truncates forward entries and makes
 * the two diverge.
 *
 * Invariant: `pushState`/`replaceState` never remove entries *before* the current
 * one, so a surviving entry's physical position never changes while it exists.
 * `history.go(editorPosition - destinationPosition)` is therefore a correct step
 * count in the current stack.
 *
 * The Nextcloud shell may own `history.state`. We only merge into a plain object;
 * for any other shape (primitive, array) we preserve the value untouched and
 * report that metadata could not be stored, so the entry is treated as unknown
 * rather than silently overwriting shell state.
 */

export const RUNBOOK_HISTORY_KEY = 'runbookNav'

/** A planned history entry write (push/replace) for App.vue to perform. */
export interface PushPlan {
	hash: string
	/** Physical position of the new entry, or null when it is unknown. */
	position: number | null
}

/**
 * Whether a value is a plain record we may safely copy and add a key to.
 * Arrays, `Date`, `Map` and class instances are intentionally excluded.
 *
 * @param value Candidate value.
 */
export function isPlainRecord(value: unknown): value is Record<string, unknown> {
	if (value === null || typeof value !== 'object' || Array.isArray(value)) {
		return false
	}
	const prototype = Object.getPrototypeOf(value)

	return prototype === Object.prototype || prototype === null
}

/**
 * Read the physical position stored on a history state, or null when absent or
 * the state cannot carry our namespaced metadata.
 *
 * @param historyState `history.state` (any value).
 */
export function readAppPosition(historyState: unknown): number | null {
	if (!isPlainRecord(historyState)) {
		return null
	}
	const meta = historyState[RUNBOOK_HISTORY_KEY]
	if (!isPlainRecord(meta)) {
		return null
	}
	const position = meta.position

	return typeof position === 'number' && Number.isInteger(position) && position >= 0 ? position : null
}

export interface MergeResult {
	/** State to write (existing value preserved when it cannot be merged). */
	state: unknown
	/** Whether the app metadata was actually stored. */
	stored: boolean
}

/**
 * Merge the app position into the existing history state.
 *
 * - `null`/`undefined`: a fresh namespaced object is used.
 * - plain record: a copy with the namespaced key added (shell state preserved).
 * - anything else (primitive, array, `Date`, `Map`, class instance): the value is
 *   returned exactly as-is and `stored` is false, because keyed metadata cannot
 *   be attached without changing its type or value.
 *
 * @param historyState Existing `history.state`.
 * @param position Physical position for the entry.
 */
export function mergeAppPosition(historyState: unknown, position: number): MergeResult {
	if (historyState === null || historyState === undefined) {
		return { state: { [RUNBOOK_HISTORY_KEY]: { position } }, stored: true }
	}

	if (isPlainRecord(historyState)) {
		return { state: { ...historyState, [RUNBOOK_HISTORY_KEY]: { position } }, stored: true }
	}

	// Primitive, array or non-plain object: preserve the value exactly.
	return { state: historyState, stored: false }
}

/**
 * State to use for a new entry when no app position can be stored: a plain
 * record is copied with our key removed (so it does not inherit another
 * entry's position); any other value is preserved exactly.
 *
 * @param historyState Existing `history.state`.
 */
export function withoutAppPosition(historyState: unknown): unknown {
	if (!isPlainRecord(historyState)) {
		return historyState
	}
	const copy = { ...historyState }
	delete copy[RUNBOOK_HISTORY_KEY]

	return copy
}
