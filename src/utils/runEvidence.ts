/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the FILE-step evidence state (issue: required FILE
 * completion). Server rules remain authoritative; these only drive the UI.
 */

import type { translate } from '@nextcloud/l10n'
import type { EvidenceState, ManagedFolderState, RunAttachment, RunStepStatus } from '../models/run.ts'
import type { StepType } from '../models/template.ts'

type Translate = typeof translate

/**
 * Attachments belonging to one step.
 *
 * This is the exact filter RunDetailView uses before passing the list to
 * RunStepCard, so a required FILE step with an existing attachment yields a
 * non-empty list and therefore an enabled Complete button. Kept pure so the
 * data flow is regression-testable without a DOM harness.
 *
 * @param attachments All attachments of the run.
 * @param stepId Run step identifier.
 */
export function attachmentsForStep(attachments: RunAttachment[], stepId: number): RunAttachment[] {
	return attachments.filter((attachment) => attachment.stepId === stepId)
}

/**
 * Number of attachments that still count as evidence (issue #52).
 *
 * A missing, out-of-scope or unavailable file is not usable evidence; an
 * attachment without a reconciled state (legacy AppData, older payloads) is
 * treated as present.
 *
 * @param attachments Attachments of one step.
 */
export function usableEvidenceCount(attachments: RunAttachment[]): number {
	return attachments.filter((attachment) => attachment.fileState === undefined || attachment.fileState === 'present').length
}

/**
 * Whether any attachment is no longer present and in scope.
 *
 * @param attachments Attachments of one step or the whole run.
 */
export function hasDegradedEvidence(attachments: RunAttachment[]): boolean {
	return attachments.some((attachment) => attachment.fileState !== undefined && attachment.fileState !== 'present')
}

/**
 * Human readable explanation of a degraded evidence state, or null when the
 * attachment is present.
 *
 * @param t Translator.
 * @param state Reconciled Files state.
 */
export function evidenceStateText(t: Translate, state: EvidenceState | undefined): string | null {
	switch (state) {
		case 'missing':
			return t('runbook', 'This file is missing from Files.')
		case 'out_of_scope':
			return t('runbook', 'This file is no longer inside the run folder.')
		case 'unavailable':
			return t('runbook', 'This file is temporarily unavailable.')
		default:
			return null
	}
}

/**
 * Run-level notice shown when tracked evidence has been lost or moved.
 *
 * The copy must be truthful about whether a replacement upload can actually
 * succeed: a missing/unavailable managed folder blocks uploads, so it must not
 * promise a re-upload. Out-of-scope/unavailable files are also not restored by a
 * simple replacement.
 *
 * @param t Translator.
 * @param attachments All attachments of the run.
 * @param managedFolderState Reconciled run-managed folder availability.
 */
export function degradedEvidenceNotice(
	t: Translate,
	attachments: RunAttachment[] = [],
	managedFolderState: ManagedFolderState = 'available',
): string {
	if (managedFolderState === 'missing') {
		return t('runbook', 'The run folder is missing in Files. New evidence cannot be uploaded until the folder is repaired; contact the run owner or an administrator.')
	}
	if (managedFolderState === 'unavailable') {
		return t('runbook', 'The run folder is currently unavailable in Files. New evidence cannot be uploaded yet; try again later.')
	}
	if (attachments.some((attachment) => attachment.fileState === 'out_of_scope')) {
		return t('runbook', 'Some evidence files were moved outside the run folder. Move them back, then upload replacements if needed.')
	}
	if (attachments.some((attachment) => attachment.fileState === 'unavailable')) {
		return t('runbook', 'Some evidence files are currently unavailable in Files. They may reappear later; upload a replacement if the step needs one.')
	}

	return t('runbook', 'Some evidence files are missing from Files. Upload replacements to restore them.')
}

/**
 * Whether a step can be completed.
 *
 * Every step except a required `FILE` step can be completed directly; a required
 * `FILE` step needs at least one persisted attachment on the server. The client
 * only mirrors that rule for the button state.
 *
 * @param type Step type.
 * @param required Whether the step is required.
 * @param evidenceCount Number of attachments already loaded for this step.
 */
export function canCompleteStep(type: StepType, required: boolean, evidenceCount: number): boolean {
	if (type !== 'FILE') {
		return true
	}

	return !required || evidenceCount > 0
}

/**
 * Whether completion is unavailable specifically because a required `FILE` step
 * has no evidence yet, so the UI can explain why.
 *
 * @param type Step type.
 * @param required Whether the step is required.
 * @param evidenceCount Number of attachments already loaded for this step.
 */
export function missingRequiredFileEvidence(type: StepType, required: boolean, evidenceCount: number): boolean {
	return type === 'FILE' && required && evidenceCount === 0
}

/**
 * Whether an attachment may be removed.
 *
 * The server refuses to delete the last *present* attachment of a completed
 * required `FILE` step; uploading a replacement first keeps the step valid. An
 * attachment that is no longer present (missing/out-of-scope/unavailable) is not
 * evidence, so the server allows removing its metadata even when it is the last
 * row — the UI must not hide it behind a total-attachment count.
 *
 * @param type Step type.
 * @param required Whether the step is required.
 * @param status Current step status.
 * @param presentCount Number of *usable* (present) attachments for the step.
 * @param attachmentState Reconciled state of the attachment being removed.
 */
export function canRemoveEvidence(
	type: StepType,
	required: boolean,
	status: RunStepStatus,
	presentCount: number,
	attachmentState: EvidenceState | undefined = 'present',
): boolean {
	if (attachmentState !== undefined && attachmentState !== 'present') {
		return true
	}
	if (type === 'FILE' && required && status === 'COMPLETED' && presentCount <= 1) {
		return false
	}

	return true
}
