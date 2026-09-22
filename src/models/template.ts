/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type TemplateStatus = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED'

export type PrincipalType = 'USER' | 'GROUP'

export type AclRole = 'VIEWER' | 'EXECUTOR' | 'EDITOR' | 'OWNER'

export type AssignableAclRole = Exclude<AclRole, 'OWNER'>

export const ASSIGNABLE_ACL_ROLES: AssignableAclRole[] = ['VIEWER', 'EXECUTOR', 'EDITOR']

export type StepType
	= | 'CHECK'
		| 'CONFIRMATION'
		| 'TEXT'
		| 'NUMBER'
		| 'SELECT'
		| 'DATE'
		| 'USER'
		| 'FILE'

export const STEP_TYPES: StepType[] = [
	'CHECK',
	'CONFIRMATION',
	'TEXT',
	'NUMBER',
	'SELECT',
	'DATE',
	'USER',
	'FILE',
]

export interface StepConfig {
	options?: string[]
	unit?: string
	[key: string]: unknown
}

export type ConditionOperator
	= | 'is_true'
		| 'is_false'
		| 'equals'
		| 'not_equals'
		| 'greater_than'
		| 'less_than'
		| 'greater_or_equal'
		| 'less_or_equal'

export interface StepCondition {
	stepId: number
	operator: ConditionOperator
	value?: string | number | boolean
}

/**
 * Operators accepted per step type. Mirrors FlowService::operatorsByType() on
 * the server so authoring, evaluation and display never diverge.
 */
export const CONDITION_OPERATORS_BY_TYPE: Record<StepType, ConditionOperator[]> = {
	CHECK: ['is_true', 'is_false'],
	CONFIRMATION: ['is_true', 'is_false'],
	NUMBER: ['equals', 'not_equals', 'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal'],
	TEXT: ['equals', 'not_equals'],
	SELECT: ['equals', 'not_equals'],
	DATE: ['equals', 'not_equals'],
	USER: ['equals', 'not_equals'],
	FILE: [],
}

/**
 * Operators accepted for a step type.
 *
 * @param type Step type.
 */
export function conditionOperatorsForType(type: StepType): ConditionOperator[] {
	return CONDITION_OPERATORS_BY_TYPE[type] ?? []
}

export interface Template {
	id: number
	uuid: string
	title: string
	description: string
	version: number
	status: TemplateStatus
	owner: string
	createdAt: number
	updatedAt: number
	publishedAt: number | null
	archivedAt: number | null
}

export interface TemplateSection {
	id: number
	templateId: number
	title: string
	description: string
	notes: string
	position: number
	dependsOn: number[]
	condition: StepCondition | null
	conditions: StepCondition[]
}

export interface TemplateStep {
	id: number
	sectionId: number
	uuid: string
	title: string
	description: string
	type: StepType
	required: boolean
	position: number
	config: StepConfig
	defaultAssignee: string | null
	dueOffset: string | null
}

export interface SectionWithSteps {
	section: TemplateSection
	steps: TemplateStep[]
}

export interface TemplatePermissions {
	role: AclRole | null
	canView: boolean
	canExecute: boolean
	canEdit: boolean
	canManageAcl: boolean
	canDelete: boolean
}

export interface TemplateAclEntry {
	id: number
	templateId: number
	principalType: PrincipalType
	principalId: string
	role: AclRole
	createdAt: number
	updatedAt: number
}

export interface TemplateAcl {
	owner: string
	entries: TemplateAclEntry[]
}

export interface AclEntryPayload {
	principalType: PrincipalType
	principalId: string
	role: AssignableAclRole
}

export interface Principal {
	principalType: PrincipalType
	principalId: string
	displayName: string
}

export interface TemplateDetail {
	template: Template
	sections: SectionWithSteps[]
	permissions: TemplatePermissions
}

export interface TemplatePayload {
	title?: string
	description?: string
}

export interface SectionPayload {
	title?: string
	description?: string
	notes?: string
	dependsOn?: number[]
	condition?: StepCondition | null
	conditions?: StepCondition[]
}

export interface StepPayload {
	title?: string
	description?: string
	type?: StepType
	required?: boolean
	config?: StepConfig
	defaultAssignee?: string | null
	dueOffset?: string | null
}

export interface TemplateExportCondition {
	stepRef: string
	operator: ConditionOperator
	value?: string | number | boolean
}

export interface TemplateExportSection {
	ref: string
	title: string
	description: string
	notes: string
	dependsOn: string[]
	conditions: TemplateExportCondition[]
}

export interface TemplateExportStep {
	ref: string
	sectionRef: string
	title: string
	description: string
	type: StepType
	required: boolean
	position: number
	config: StepConfig
	defaultAssignee: string | null
	dueOffset: string | null
}

export interface TemplateExportDocument {
	format: 'runbook-template'
	schemaVersion: number
	template: { title: string, description: string }
	sections: TemplateExportSection[]
	steps: TemplateExportStep[]
}
