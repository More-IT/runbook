/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Template, TemplatePayload } from '../models/template.ts'

import { ref } from 'vue'
import {
	createTemplate as createTemplateRequest,
	deleteTemplate as deleteTemplateRequest,
	listTemplates,
} from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

/**
 * List state for the Templates view.
 */
export function useTemplateList() {
	const templates = ref<Template[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)

	/**
	 * Reload the list of visible templates.
	 */
	async function refresh(): Promise<void> {
		loading.value = true
		error.value = null
		try {
			templates.value = await listTemplates()
		} catch (caught) {
			error.value = apiErrorMessage(caught)
		} finally {
			loading.value = false
		}
	}

	/**
	 * Create a template and prepend it to the list.
	 *
	 * @param payload Template metadata.
	 */
	async function create(payload: TemplatePayload): Promise<Template | null> {
		error.value = null
		try {
			const template = await createTemplateRequest(payload)
			templates.value = [template, ...templates.value]

			return template
		} catch (caught) {
			error.value = apiErrorMessage(caught)

			return null
		}
	}

	/**
	 * Delete a template and remove it from the list.
	 *
	 * @param id Template identifier.
	 */
	async function remove(id: number): Promise<boolean> {
		error.value = null
		try {
			await deleteTemplateRequest(id)
			templates.value = templates.value.filter((template) => template.id !== id)

			return true
		} catch (caught) {
			error.value = apiErrorMessage(caught)

			return false
		}
	}

	return {
		templates,
		loading,
		error,
		refresh,
		create,
		remove,
	}
}
