/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { AdminDestinationState, AdminSettingsPayload, AdminSettingsValues, AppFeatures } from '../models/adminSettings.ts'

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
 * Save the global administration destination folder (issue #47).
 *
 * The path is a locator from the administrator's own Files; identity is
 * captured server-side. A failed save leaves the stored reference unchanged.
 *
 * @param path User-visible folder path selected in the administrator's Files.
 */
export async function saveAdminDestination(path: string): Promise<AdminDestinationState> {
	const { data } = await axios.put<{ destination: AdminDestinationState }>(endpoint('/admin/settings/destination'), { path })

	return data.destination
}

/**
 * Clear the global administration destination folder (issue #47), restoring the
 * default `Files/Runbook` behaviour.
 */
export async function clearAdminDestination(): Promise<AdminDestinationState> {
	const { data } = await axios.delete<{ destination: AdminDestinationState }>(endpoint('/admin/settings/destination'))

	return data.destination
}

/**
 * Load the feature flags that affect the main application UI.
 */
export async function getFeatures(): Promise<AppFeatures> {
	const { data } = await axios.get<{ features: AppFeatures }>(endpoint('/features'))

	return data.features
}
