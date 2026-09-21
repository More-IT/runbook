/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { PrincipalType, StepCondition, StepConfig, StepType } from './template.ts'

export type RunStatus = 'ACTIVE' | 'COMPLETED' | 'CANCELLED'

export type RunStepStatus = 'PENDING' | 'IN_PROGRESS' | 'COMPLETED' | 'SKIPPED'

export type StepResponse = boolean | number | string | null

export type RunAclRole = 'OWNER' | 'PARTICIPANT' | 'VIEWER'

export type AssignableRunAclRole = Exclude<RunAclRole, 'OWNER'>

export const ASSIGNABLE_RUN_ACL_ROLES: AssignableRunAclRole[] = ['PARTICIPANT', 'VIEWER']

export type MyWorkFilter = 'all' | 'today' | 'upcoming' | 'overdue' | 'completed'

export const MY_WORK_FILTERS: MyWorkFilter[] = ['all', 'today', 'upcoming', 'overdue', 'completed']

export interface Run {
	id: number
	uuid: string
	templateId: number | null
	templateVersion: number
	title: string
	description: string
	owner: string
	status: RunStatus
	createdAt: number
	startedAt: number
	completedAt: number | null
	cancelledAt: number | null
	reopenedAt: number | null
	dueAt: number | null
	completedBy: string | null
	cancelledBy: string | null
	updatedAt: number
}

export interface RunSection {
	id: number
	runId: number
	sourceSectionId: number | null
	title: string
	description: string
	notes: string
	position: number
	dependsOn: number[]
	condition: StepCondition | null
	conditions: StepCondition[]
}

export interface RunStep {
	id: number
	runSectionId: number
	sourceStepId: number | null
	uuid: string
	title: string
	description: string
	type: StepType
	required: boolean
	position: number
	config: StepConfig
	status: RunStepStatus
	response: StepResponse
	skipReason: string | null
	assigneeType: PrincipalType | null
	assigneeId: string | null
	dueAt: number | null
	startedAt: number | null
	completedAt: number | null
	skippedAt: number | null
	reopenedAt: number | null
}

export interface RunProgress {
	total: number
	completed: number
	skipped: number
	pending: number
	percentage: number
	canComplete: boolean
}

export interface RunPermissions {
	uid: string
	role: RunAclRole | null
	canManage: boolean
	canModify: boolean
	canCancel: boolean
	canReopen: boolean
	canManageAssignments: boolean
	canComment: boolean
	canDelete: boolean
	executableStepIds: number[]
}

export interface RunComment {
	id: number
	uuid: string
	runId: number
	stepId: number | null
	authorUid: string
	body: string
	createdAt: number
	updatedAt: number
}

export interface RunCommentMention {
	uid: string
	displayName: string
}

export interface RunCommentItem {
	comment: RunComment
	authorDisplayName: string
	mentions: RunCommentMention[]
}

export interface CommentCreatePayload {
	body: string
	stepId?: number | null
}

export interface RunAttachment {
	id: number
	uuid: string
	runId: number
	stepId: number
	uploaderUid: string
	filename: string
	mimeType: string
	size: number
	checksum: string
	createdAt: number
}

export type ActivityType
	= | 'run_started'
		| 'run_completed'
		| 'run_cancelled'
		| 'run_reopened'
		| 'run_acl_changed'
		| 'run_assignment_changed'
		| 'section_notes_updated'
		| 'step_assignment_changed'
		| 'step_started'
		| 'step_response_updated'
		| 'step_completed'
		| 'step_skipped'
		| 'step_reopened'
		| 'step_returned'
		| 'section_returned'
		| 'comment_added'
		| 'comment_edited'
		| 'comment_deleted'
		| 'attachment_uploaded'
		| 'attachment_deleted'

export interface RunActivityEvent {
	id: number
	runId: number
	stepId: number | null
	actorUid: string
	eventType: ActivityType
	metadata: Record<string, unknown>
	createdAt: number
}

export type SectionReasonType = 'condition_false' | 'condition_pending' | 'dependency'

export interface SectionReason {
	type: SectionReasonType
	title: string
	stepId?: number
	sectionId?: number
	operator?: string
	expected?: string | number | boolean
}

export interface RunSectionWithSteps {
	section: RunSection
	steps: RunStep[]
	state: string
	blockedBy: string[]
	reason: SectionReason[]
}

export interface RunDetail {
	run: Run
	sections: RunSectionWithSteps[]
	progress: RunProgress
	permissions: RunPermissions
}

export interface RunListItem {
	run: Run
	progress: RunProgress
}

export interface StartRunPayload {
	title: string
	description?: string
	dueAt?: number | null
}

export interface RunAclEntry {
	id: number
	runId: number
	principalType: PrincipalType
	principalId: string
	role: RunAclRole
	createdAt: number
	updatedAt: number
}

export interface RunAcl {
	owner: string
	entries: RunAclEntry[]
}

export interface RunAclEntryPayload {
	principalType: PrincipalType
	principalId: string
	role: AssignableRunAclRole
}

export interface StepAssignmentPayload {
	assigneeType?: PrincipalType | null
	assigneeId?: string | null
	dueAt?: number | null
}

export interface WorkItem {
	runId: number
	runTitle: string
	runStatus: RunStatus
	runDueAt: number | null
	stepId: number
	stepTitle: string
	stepStatus: RunStepStatus
	stepType: StepType
	assigneeType: PrincipalType | null
	assigneeId: string | null
	dueAt: number | null
	overdue: boolean
	dueToday: boolean
	sectionTitle: string
	required: boolean
}

export interface Overview {
	activeRuns: number
	assignedActiveSteps: number
	overdue: number
	completedStepsThisMonth: number
	completedRunsThisMonth: number
	assignedWork: WorkItem[]
	recentRuns: Run[]
}
