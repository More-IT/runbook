/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AdminSettingsPayload, AdminSettingsValues, AppFeatures } from '../models/adminSettings.ts'

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
 * Load the administration settings (administrators only).
 */
export async function getAdminSettings(): Promise<AdminSettingsPayload> {
	const { data } = await axios.get<AdminSettingsPayload>(endpoint('/admin/settings'))

	return data
}

/**
 * Save the administration settings (administrators only).
 *
 * @param settings Complete settings payload.
 */
export async function saveAdminSettings(settings: AdminSettingsValues): Promise<AdminSettingsPayload> {
	const { data } = await axios.put<AdminSettingsPayload>(endpoint('/admin/settings'), settings)

	return data
}

/**
 * Load the feature flags that affect the main application UI.
 */
export async function getFeatures(): Promise<AppFeatures> {
	const { data } = await axios.get<{ features: AppFeatures }>(endpoint('/features'))

	return data.features
}
