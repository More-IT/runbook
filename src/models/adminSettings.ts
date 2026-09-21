/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export type TemplateCreationPolicy = 'everyone' | 'selected_groups' | 'admins'

export const TEMPLATE_CREATION_POLICIES: TemplateCreationPolicy[] = ['everyone', 'selected_groups', 'admins']

export interface AdminSettingsValues {
	templateCreationPolicy: TemplateCreationPolicy
	templateCreatorGroups: string[]
	commentsEnabled: boolean
	stepReopenEnabled: boolean
	requireSkipReason: boolean
	runReopenEnabled: boolean
	maxAttachmentSize: number
	dashboardEnabled: boolean
	notificationsEnabled: boolean
	searchEnabled: boolean
	retentionDays: number
}

export interface AdminSettingsBounds {
	minAttachmentSize: number
	maxAttachmentSize: number
	maxRetentionDays: number
}

export interface AdminSettingsPayload {
	settings: AdminSettingsValues
	bounds: AdminSettingsBounds
}

export interface AppFeatures {
	commentsEnabled: boolean
	stepReopenEnabled: boolean
	requireSkipReason: boolean
	runReopenEnabled: boolean
	maxAttachmentSize: number
	canCreateTemplates: boolean
	uid: string
	isAdmin: boolean
}
