/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	CommentCreatePayload,
	MyWorkFilter,
	Overview,
	Run,
	RunAcl,
	RunAclEntryPayload,
	RunActivityEvent,
	RunAttachment,
	RunCommentItem,
	RunDetail,
	RunListItem,
	RunStep,
	StartRunPayload,
	StepAssignmentPayload,
	StepResponse,
	WorkItem,
} from '../models/run.ts'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Build the absolute URL of a Runbook API endpoint.
 *
 * @param path Endpoint path relative to the API prefix.
 */
function endpoint(path: string): string {
	return generateUrl(`/apps/runbook/api/v1${path}`)
}

/**
 * List the runs owned by the current user.
 */
export async function listRuns(): Promise<RunListItem[]> {
	const { data } = await axios.get<{ runs: RunListItem[] }>(endpoint('/runs'))

	return data.runs
}

/**
 * Start a run from a published template.
 *
 * @param templateId Source template identifier.
 * @param payload Run metadata.
 */
export async function startRun(templateId: number, payload: StartRunPayload): Promise<Run> {
	const { data } = await axios.post<{ run: Run }>(endpoint(`/templates/${templateId}/runs`), payload)

	return data.run
}

/**
 * Load a run with its sections, steps and progress.
 *
 * @param id Run identifier.
 */
export async function getRun(id: number): Promise<RunDetail> {
	const { data } = await axios.get<RunDetail>(endpoint(`/runs/${id}`))

	return data
}

/**
 * Mark a run as completed.
 *
 * @param id Run identifier.
 */
export async function completeRun(id: number): Promise<Run> {
	const { data } = await axios.post<{ run: Run }>(endpoint(`/runs/${id}/complete`))

	return data.run
}

/**
 * Cancel a run.
 *
 * @param id Run identifier.
 */
export async function cancelRun(id: number): Promise<Run> {
	const { data } = await axios.post<{ run: Run }>(endpoint(`/runs/${id}/cancel`))

	return data.run
}

/**
 * Reopen a completed run.
 *
 * @param id Run identifier.
 */
export async function reopenRun(id: number): Promise<Run> {
	const { data } = await axios.post<{ run: Run }>(endpoint(`/runs/${id}/reopen`))

	return data.run
}

/**
 * Mark a pending step as in progress.
 *
 * @param id Run step identifier.
 */
export async function startStep(id: number): Promise<RunStep> {
	const { data } = await axios.post<{ step: RunStep }>(endpoint(`/run-steps/${id}/start`))

	return data.step
}

/**
 * Store a step response without changing its status.
 *
 * @param id Run step identifier.
 * @param response Response value.
 */
export async function updateStep(id: number, response: StepResponse): Promise<RunStep> {
	const { data } = await axios.patch<{ step: RunStep }>(endpoint(`/run-steps/${id}`), { response })

	return data.step
}

/**
 * Complete a step with an optional response.
 *
 * @param id Run step identifier.
 * @param response Response value.
 */
export async function completeStep(id: number, response: StepResponse): Promise<RunStep> {
	const { data } = await axios.post<{ step: RunStep }>(endpoint(`/run-steps/${id}/complete`), { response })

	return data.step
}

/**
 * Skip a step with a mandatory reason.
 *
 * @param id Run step identifier.
 * @param reason Skip reason.
 */
export async function skipStep(id: number, reason: string): Promise<RunStep> {
	const { data } = await axios.post<{ step: RunStep }>(endpoint(`/run-steps/${id}/skip`), { reason })

	return data.step
}

/**
 * Reopen a completed or skipped step.
 *
 * @param id Run step identifier.
 */
export async function reopenStep(id: number): Promise<RunStep> {
	const { data } = await axios.post<{ step: RunStep }>(endpoint(`/run-steps/${id}/reopen`))

	return data.step
}

/**
 * Update the assignee and/or due date of a run step.
 *
 * @param id Run step identifier.
 * @param assignment Assignment fields to update.
 */
export async function assignStep(id: number, assignment: StepAssignmentPayload): Promise<RunStep> {
	const { data } = await axios.patch<{ step: RunStep }>(endpoint(`/run-steps/${id}`), assignment)

	return data.step
}

/**
 * Load the participant and viewer list of a run.
 *
 * @param id Run identifier.
 */
export async function getRunAcl(id: number): Promise<RunAcl> {
	const { data } = await axios.get<RunAcl>(endpoint(`/runs/${id}/acl`))

	return data
}

/**
 * Replace the complete access list of a run.
 *
 * @param id Run identifier.
 * @param entries Complete list of participant and viewer entries.
 */
export async function replaceRunAcl(id: number, entries: RunAclEntryPayload[]): Promise<RunAcl> {
	const { data } = await axios.put<RunAcl>(endpoint(`/runs/${id}/acl`), { entries })

	return data
}

/**
 * Load the assigned work of the current user for a filter.
 *
 * @param filter My Work filter.
 */
export async function getMyWork(filter: MyWorkFilter): Promise<WorkItem[]> {
	const { data } = await axios.get<{ work: WorkItem[] }>(endpoint('/my-work'), { params: { filter } })

	return data.work
}

/**
 * Load the overview counters and short lists.
 */
export async function getOverview(): Promise<Overview> {
	const { data } = await axios.get<Overview>(endpoint('/overview'))

	return data
}

/**
 * Load the comments of a run, including step comments.
 *
 * @param runId Run identifier.
 */
export async function listComments(runId: number): Promise<RunCommentItem[]> {
	const { data } = await axios.get<{ comments: RunCommentItem[] }>(endpoint(`/runs/${runId}/comments`))

	return data.comments
}

/**
 * Add a comment to a run or to one of its steps.
 *
 * @param runId Run identifier.
 * @param payload Comment body and optional step identifier.
 */
export async function createComment(runId: number, payload: CommentCreatePayload): Promise<RunCommentItem> {
	const { data } = await axios.post<RunCommentItem>(endpoint(`/runs/${runId}/comments`), payload)

	return data
}

/**
 * Edit an existing comment.
 *
 * @param commentId Comment identifier.
 * @param body New comment body.
 */
export async function updateComment(commentId: number, body: string): Promise<RunCommentItem> {
	const { data } = await axios.patch<RunCommentItem>(endpoint(`/comments/${commentId}`), { body })

	return data
}

/**
 * Delete a comment.
 *
 * @param commentId Comment identifier.
 */
export async function deleteComment(commentId: number): Promise<void> {
	await axios.delete(endpoint(`/comments/${commentId}`))
}

/**
 * Load all evidence attachments of a run.
 *
 * @param runId Run identifier.
 */
export async function listAttachments(runId: number): Promise<RunAttachment[]> {
	const { data } = await axios.get<{ attachments: RunAttachment[] }>(endpoint(`/runs/${runId}/attachments`))

	return data.attachments
}

/**
 * Upload one evidence file to a run step.
 *
 * @param stepId Run step identifier.
 * @param file File selected by the user.
 */
export async function uploadAttachment(stepId: number, file: File): Promise<RunAttachment> {
	const form = new FormData()
	form.append('file', file)

	const { data } = await axios.post<{ attachment: RunAttachment }>(
		endpoint(`/run-steps/${stepId}/attachments`),
		form,
		{ headers: { 'Content-Type': 'multipart/form-data' } },
	)

	return data.attachment
}

/**
 * Delete an evidence attachment.
 *
 * @param id Attachment identifier.
 */
export async function deleteAttachment(id: number): Promise<void> {
	await axios.delete(endpoint(`/attachments/${id}`))
}

/**
 * Build the download URL of an evidence attachment.
 *
 * @param id Attachment identifier.
 */
export function attachmentDownloadUrl(id: number): string {
	return endpoint(`/attachments/${id}`)
}

/**
 * Load the activity history of a run.
 *
 * @param runId Run identifier.
 * @param limit Maximum number of events.
 * @param order `desc` for newest first (default) or `asc` for oldest first.
 */
export async function getActivity(runId: number, limit?: number, order?: 'asc' | 'desc'): Promise<RunActivityEvent[]> {
	const params: Record<string, string | number> = {}
	if (limit !== undefined) {
		params.limit = limit
	}
	if (order !== undefined) {
		params.order = order
	}

	const { data } = await axios.get<{ activity: RunActivityEvent[] }>(endpoint(`/runs/${runId}/activity`), {
		params,
	})

	return data.activity
}
