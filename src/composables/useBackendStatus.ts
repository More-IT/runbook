/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { readonly, ref } from 'vue'

export type BackendStatus = 'checking' | 'ok' | 'error'

/**
 * Shape of the response returned by the Runbook status endpoint.
 */
interface BackendStatusResponse {
	status: string
	app: string
	version: string
}

/**
 * Small composable used to verify that the Vue frontend can reach the
 * PHP backend of the Runbook app.
 *
 * It intentionally only talks to the foundation status endpoint and does
 * not implement any business API.
 */
export function useBackendStatus() {
	const status = ref<BackendStatus>('checking')

	/**
	 * Query the foundation status endpoint and update the reactive state.
	 */
	async function checkBackendStatus(): Promise<void> {
		status.value = 'checking'

		try {
			const { data } = await axios.get<BackendStatusResponse>(generateUrl('/apps/runbook/api/status'))
			status.value = data.status === 'ok' ? 'ok' : 'error'
		} catch {
			status.value = 'error'
		}
	}

	return {
		status: readonly(status),
		checkBackendStatus,
	}
}
