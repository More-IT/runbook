/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the template authoring editor (issue #28): the section
 * outline, focus/selection rules, condition draft validation and draft-vs-saved
 * comparison. They contain no flow decisions — authoring rules and execution
 * states are different concepts.
 */

import type { ConditionOperator, SectionWithSteps, StepCondition, StepType, TemplatePermissions, TemplateSection, TemplateStatus } from '../models/template.ts'

export interface SectionOutlineItem {
	id: number
	position: number
	title: string
	stepCount: number
	hasSteps: boolean
}

/**
 * Build the section outline shown beside the editor.
 *
 * @param sections Sections with their steps.
 */
export function sectionOutline(sections: SectionWithSteps[]): SectionOutlineItem[] {
	return sections.map((entry, index) => ({
		id: entry.section.id,
		position: index + 1,
		title: entry.section.title,
		stepCount: entry.steps.length,
		hasSteps: entry.steps.length > 0,
	}))
}

/**
 * Section selected by default: the first one, or null for an empty template.
 *
 * @param sections Sections with their steps.
 */
export function defaultSelectedSectionId(sections: SectionWithSteps[]): number | null {
	return sections[0]?.section.id ?? null
}

/**
 * Sensible neighbour to focus after deleting a section: the next one, otherwise
 * the previous one, otherwise null.
 *
 * @param sections Sections with their steps (before deletion).
 * @param removedId Section being removed.
 */
export function neighborSectionId(sections: SectionWithSteps[], removedId: number): number | null {
	const index = sections.findIndex((entry) => entry.section.id === removedId)
	if (index === -1) {
		return defaultSelectedSectionId(sections)
	}

	return sections[index + 1]?.section.id ?? sections[index - 1]?.section.id ?? null
}

/**
 * Whether a section id still exists in the given list.
 *
 * @param sections Sections with their steps.
 * @param sectionId Section identifier.
 */
export function hasSection(sections: SectionWithSteps[], sectionId: number | null): boolean {
	return sectionId !== null && sections.some((entry) => entry.section.id === sectionId)
}

export interface ConditionStepOption {
	id: number
	title: string
	/** Disambiguated label, prefixed with the parent section title. */
	label: string
	sectionId: number
	type: StepType
	options: string[]
}

/**
 * Condition step options for a section: every step of the template except the
 * section's own steps (same-section conditions are rejected by the server).
 * Labels are prefixed with the parent section so similarly named steps can be
 * told apart.
 *
 * @param sections Sections with their steps.
 * @param forSectionId Section currently being edited.
 */
export function conditionStepOptions(sections: SectionWithSteps[], forSectionId: number): ConditionStepOption[] {
	const options: ConditionStepOption[] = []
	for (const entry of sections) {
		if (entry.section.id === forSectionId) {
			continue
		}
		for (const step of entry.steps) {
			options.push({
				id: step.id,
				title: step.title,
				label: `${entry.section.title} · ${step.title}`,
				sectionId: entry.section.id,
				type: step.type,
				options: Array.isArray(step.config.options)
					? step.config.options.filter((option): option is string => typeof option === 'string')
					: [],
			})
		}
	}

	return options
}

export interface ConditionDraftRow {
	stepId: number | null
	operator: ConditionOperator
	value: string
}

/**
 * Whether a condition row still needs input (no controlling step, or a missing
 * comparison value for a non-boolean step).
 *
 * @param row Draft condition row.
 * @param option Matching condition step option, if any.
 */
export function isConditionRowIncomplete(row: ConditionDraftRow, option: ConditionStepOption | undefined): boolean {
	if (row.stepId === null || option === undefined) {
		return true
	}
	if (option.type === 'CHECK' || option.type === 'CONFIRMATION') {
		return false
	}

	return row.value.trim() === ''
}

/**
 * Indexes of incomplete condition rows, so submission can be blocked with a
 * clear message instead of silently dropping the row.
 *
 * @param rows Draft condition rows.
 * @param options Available condition step options.
 */
export function incompleteConditionRows(rows: ConditionDraftRow[], options: ConditionStepOption[]): number[] {
	const byId = new Map(options.map((option) => [option.id, option]))
	const incomplete: number[] = []
	rows.forEach((row, index) => {
		if (isConditionRowIncomplete(row, row.stepId === null ? undefined : byId.get(row.stepId))) {
			incomplete.push(index)
		}
	})

	return incomplete
}

export interface SectionDraft {
	title: string
	description: string
	notes: string
	dependsOn: number[]
	conditions: StepCondition[]
}

/**
 * Saved conditions of a section, regardless of which storage shape was used.
 *
 * @param section Section entity.
 */
export function savedConditions(section: TemplateSection): StepCondition[] {
	return section.conditions.length > 0
		? section.conditions
		: (section.condition !== null ? [section.condition] : [])
}

/**
 * Whether the local draft differs from the saved section.
 *
 * @param draft Local draft.
 * @param section Saved section.
 */
export function isSectionDraftDirty(draft: SectionDraft, section: TemplateSection): boolean {
	if (draft.title !== section.title || draft.description !== section.description || draft.notes !== section.notes) {
		return true
	}
	if (!sameNumberSet(draft.dependsOn, section.dependsOn)) {
		return true
	}

	return JSON.stringify(draft.conditions) !== JSON.stringify(savedConditions(section))
}

/**
 * Order-insensitive numeric list comparison.
 *
 * @param left First list.
 * @param right Second list.
 */
export function sameNumberSet(left: number[], right: number[]): boolean {
	const a = [...left].sort((x, y) => x - y)
	const b = [...right].sort((x, y) => x - y)

	return a.length === b.length && a.every((value, index) => value === b[index])
}

/**
 * Short description preview for the section summary.
 *
 * @param description Full description.
 * @param maxLength Maximum preview length.
 */
export function descriptionPreview(description: string, maxLength = 140): string {
	const trimmed = description.trim()
	if (trimmed.length <= maxLength) {
		return trimmed
	}

	return `${trimmed.slice(0, maxLength).trimEnd()}…`
}

export interface EffectiveEditorPermissions {
	canEdit: boolean
	canManageAcl: boolean
	canPublish: boolean
	canArchive: boolean
	canStartRun: boolean
	canDelete: boolean
}

/**
 * Effective UI permissions, combining the server permissions with the current
 * template status. Archiving invalidates the cached edit/manage permissions
 * immediately, so editable controls disappear without a reload. Delete stays
 * governed by the existing server authorization only.
 *
 * @param permissions Server permissions.
 * @param status Current template status.
 */
export function effectiveEditorPermissions(
	permissions: TemplatePermissions | null,
	status: TemplateStatus | undefined,
): EffectiveEditorPermissions {
	const archived = status === 'ARCHIVED'
	const canEdit = (permissions?.canEdit ?? false) && !archived
	const canManageAcl = (permissions?.canManageAcl ?? false) && !archived

	return {
		canEdit,
		canManageAcl,
		canPublish: canManageAcl && status === 'DRAFT',
		canArchive: canManageAcl,
		canStartRun: status === 'PUBLISHED' && (permissions?.canExecute ?? false),
		canDelete: permissions?.canDelete ?? false,
	}
}
