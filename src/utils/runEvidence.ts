/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the FILE-step evidence state (issue: required FILE
 * completion). Server rules remain authoritative; these only drive the UI.
 */

import type { RunAttachment, RunStepStatus } from '../models/run.ts'
import type { StepType } from '../models/template.ts'

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
 * The server refuses to delete the last attachment of a completed required
 * `FILE` step; uploading a replacement first keeps the step valid.
 *
 * @param type Step type.
 * @param required Whether the step is required.
 * @param status Current step status.
 * @param evidenceCount Number of attachments already loaded for this step.
 */
export function canRemoveEvidence(
	type: StepType,
	required: boolean,
	status: RunStepStatus,
	evidenceCount: number,
): boolean {
	if (type === 'FILE' && required && status === 'COMPLETED' && evidenceCount <= 1) {
		return false
	}

	return true
}
