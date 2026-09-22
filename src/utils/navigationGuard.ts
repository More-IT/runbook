/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure navigation-target helpers for the app shell (issue #29).
 *
 * They centralise hash parsing and the decision of whether an in-app
 * navigation must be guarded because the template editor has unsaved drafts,
 * so sidebar clicks, hash changes and browser Back/Forward all share one rule.
 */

export interface NavTarget {
	section: string
	templateId: number | null
	runId: number | null
	stepId: number | null
}

export interface NavState {
	section: string
	templateId: number | null
	runId: number | null
	stepId: number | null
}

/**
 * Parse a location hash into a navigation target, or null when it is unknown.
 *
 * @param hash Location hash including the leading `#`.
 * @param knownSections Known sidebar section ids.
 */
export function parseHashTarget(hash: string, knownSections: string[]): NavTarget | null {
	const parts = hash
		.replace(/^#\/?/, '')
		.split('/')
		.filter((part) => part !== '')

	if (parts.length === 0) {
		return null
	}

	if (parts[0] === 'run' && parts.length > 1) {
		const runId = Number(parts[1])
		if (!Number.isInteger(runId) || runId <= 0) {
			return null
		}
		let stepId: number | null = null
		if (parts[2] === 'step' && parts.length > 3) {
			const candidate = Number(parts[3])
			if (Number.isInteger(candidate) && candidate > 0) {
				stepId = candidate
			}
		}

		return { section: 'runs', templateId: null, runId, stepId }
	}

	if (parts[0] === 'template' && parts.length > 1) {
		const templateId = Number(parts[1])
		if (!Number.isInteger(templateId) || templateId <= 0) {
			return null
		}

		return { section: 'templates', templateId, runId: null, stepId: null }
	}

	if (knownSections.includes(parts[0])) {
		return { section: parts[0], templateId: null, runId: null, stepId: null }
	}

	return null
}

/**
 * Hash for a navigation target.
 *
 * @param target Navigation target.
 */
export function hashForTarget(target: NavTarget): string {
	if (target.runId !== null) {
		return target.stepId === null ? `#/run/${target.runId}` : `#/run/${target.runId}/step/${target.stepId}`
	}
	if (target.templateId !== null) {
		return `#/template/${target.templateId}`
	}

	return `#${target.section}`
}

/**
 * Whether the app already shows the given target.
 *
 * @param target Navigation target.
 * @param state Current navigation state.
 */
export function isCurrentTarget(target: NavTarget, state: NavState): boolean {
	return target.section === state.section
		&& target.templateId === state.templateId
		&& target.runId === state.runId
		&& (target.runId === null || target.stepId === state.stepId)
}

export interface GuardInput {
	/** Template currently being edited, or null. */
	templateId: number | null
	/** Whether the editor has any unsaved draft (metadata, section or step). */
	hasDrafts: boolean
}

/**
 * Whether a navigation must be guarded because it would leave the current
 * template editor with unsaved drafts. Staying on the same template, or any
 * navigation while not editing, is never guarded.
 *
 * @param input Editor guard input.
 * @param target Intended navigation target.
 */
export function shouldGuardNavigation(input: GuardInput, target: NavTarget): boolean {
	if (input.templateId === null || !input.hasDrafts) {
		return false
	}

	return target.templateId !== input.templateId
}
