/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure draft/discard state transitions for the template editor (issue #28).
 *
 * They model which drafts a navigation or lifecycle action would actually
 * discard, so confirmations are truthful and scoped, and so confirming a
 * discard clears exactly the drafts it named.
 */

import type { StepConfig, StepPayload, StepType, TemplateStep } from '../models/template.ts'

export type DraftScope = 'step' | 'section' | 'all'

export interface EditorDraftState {
	metadataDirty: boolean
	sectionDirty: boolean
	stepDirty: boolean
}

export type EditorIntentType = 'switch' | 'step' | 'add' | 'publish' | 'run' | 'archive' | 'delete' | 'close'

/**
 * Scope of drafts an intent would discard.
 *
 * - Opening another step only loses the current step draft; the section form
 *   stays mounted, so its draft and the metadata draft are preserved.
 * - Switching or adding a section loses the mounted section and step drafts but
 *   keeps the metadata fields (which stay mounted).
 * - Lifecycle actions (publish/run/archive/delete/close) act on saved data and
 *   must consider every visible edit.
 *
 * @param type Intent type.
 */
export function intentScope(type: EditorIntentType): DraftScope {
	if (type === 'step') {
		return 'step'
	}
	if (type === 'switch' || type === 'add') {
		return 'section'
	}

	return 'all'
}

/**
 * Whether a scope has drafts that would be lost (and must be confirmed).
 *
 * @param state Draft flags.
 * @param scope Draft scope.
 */
export function hasBlockingDrafts(state: EditorDraftState, scope: DraftScope): boolean {
	if (scope === 'step') {
		return state.stepDirty
	}
	if (scope === 'section') {
		return state.sectionDirty || state.stepDirty
	}

	return state.metadataDirty || state.sectionDirty || state.stepDirty
}

export interface DiscardOutcome {
	state: EditorDraftState
	/** Reset the focused section draft to its saved value. */
	resetSectionDraft: boolean
	/** Close (and discard) any open step editor. */
	closeStepEditor: boolean
	/** Reset the template metadata draft to its saved value. */
	resetMetadataDraft: boolean
}

/**
 * Discard every draft covered by the scope. Drafts outside the scope are kept,
 * so opening another step never discards a section or metadata draft, and
 * switching sections never discards an unrelated metadata draft.
 *
 * @param state Draft flags.
 * @param scope Draft scope.
 */
export function discardDrafts(state: EditorDraftState, scope: DraftScope): DiscardOutcome {
	return {
		state: {
			metadataDirty: scope === 'all' ? false : state.metadataDirty,
			sectionDirty: scope === 'step' ? state.sectionDirty : false,
			stepDirty: false,
		},
		resetSectionDraft: scope === 'section' || scope === 'all',
		closeStepEditor: true,
		resetMetadataDraft: scope === 'all',
	}
}

export interface StepEditorState {
	editingStepId: number | null
	error: string | null
}

/**
 * Next step-editor state after a save attempt.
 *
 * The editor is only closed on success; on failure the form stays open with its
 * draft intact and the error is surfaced next to it.
 *
 * @param editingStepId Currently open step id.
 * @param success Whether the save succeeded.
 * @param error Error message for a failed save.
 */
export function applyStepSaveResult(editingStepId: number | null, success: boolean, error: string | null): StepEditorState {
	if (success) {
		return { editingStepId: null, error: null }
	}

	return { editingStepId, error }
}

/**
 * Normalise a step configuration for comparison (options trimmed and filtered,
 * unit trimmed).
 *
 * @param config Step configuration.
 * @param type Step type.
 */
export function normalizeStepConfig(config: StepConfig | undefined, type: StepType): StepConfig {
	if (type === 'SELECT') {
		const options = Array.isArray(config?.options)
			? config.options
					.filter((option): option is string => typeof option === 'string')
					.map((option) => option.trim())
					.filter((option) => option !== '')
			: []

		return { options }
	}
	if (type === 'NUMBER') {
		return { unit: typeof config?.unit === 'string' ? config.unit.trim() : '' }
	}

	return {}
}

/**
 * Whether a step draft differs from the saved step, including type-specific
 * configuration.
 *
 * @param draft Draft payload.
 * @param step Saved step.
 */
export function isStepDraftDirty(draft: StepPayload, step: TemplateStep): boolean {
	const type = draft.type ?? step.type
	if ((draft.title ?? '') !== step.title || (draft.description ?? '') !== step.description || type !== step.type) {
		return true
	}
	if ((draft.required ?? step.required) !== step.required) {
		return true
	}
	if ((draft.defaultAssignee ?? null) !== (step.defaultAssignee ?? null)) {
		return true
	}
	if ((draft.dueOffset ?? null) !== (step.dueOffset ?? null)) {
		return true
	}

	return JSON.stringify(normalizeStepConfig(draft.config, type)) !== JSON.stringify(normalizeStepConfig(step.config, type))
}
