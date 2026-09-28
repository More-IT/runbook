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

/**
 * Global administration destination folder state (issue #47).
 *
 * `configured` = a reference is stored; `valid` = the stored reference is
 * complete. Identity (storage id / file id) is never exposed to the browser;
 * `path` is advisory display text only.
 */
export interface AdminDestinationState {
	configured: boolean
	valid: boolean
	path: string | null
	configuredBy: string | null
}

export interface AdminSettingsPayload {
	settings: AdminSettingsValues
	bounds: AdminSettingsBounds
	destination?: AdminDestinationState
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
