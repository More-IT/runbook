/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type {
	AclEntryPayload,
	Principal,
	SectionPayload,
	StepPayload,
	Template,
	TemplateAcl,
	TemplateDetail,
	TemplateExportDocument,
	TemplatePayload,
	TemplateSection,
	TemplateStep,
} from '../models/template.ts'

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
 * List the templates visible to the current user.
 */
export async function listTemplates(): Promise<Template[]> {
	const { data } = await axios.get<{ templates: Template[] }>(endpoint('/templates'))

	return data.templates
}

/**
 * Create a new draft template.
 *
 * @param payload Template metadata.
 */
export async function createTemplate(payload: TemplatePayload): Promise<Template> {
	const { data } = await axios.post<{ template: Template }>(endpoint('/templates'), payload)

	return data.template
}

/**
 * Load a template together with its sections and steps.
 *
 * @param id Template identifier.
 */
export async function getTemplate(id: number): Promise<TemplateDetail> {
	const { data } = await axios.get<TemplateDetail>(endpoint(`/templates/${id}`))

	return data
}

/**
 * Update template metadata.
 *
 * @param id Template identifier.
 * @param payload Metadata fields to update.
 */
export async function updateTemplate(id: number, payload: TemplatePayload): Promise<Template> {
	const { data } = await axios.patch<{ template: Template }>(endpoint(`/templates/${id}`), payload)

	return data.template
}

/**
 * Delete a template and its content.
 *
 * @param id Template identifier.
 */
export async function deleteTemplate(id: number): Promise<void> {
	await axios.delete(endpoint(`/templates/${id}`))
}

/**
 * Publish a draft template.
 *
 * @param id Template identifier.
 */
export async function publishTemplate(id: number): Promise<Template> {
	const { data } = await axios.post<{ template: Template }>(endpoint(`/templates/${id}/publish`))

	return data.template
}

/**
 * Archive a template.
 *
 * @param id Template identifier.
 */
export async function archiveTemplate(id: number): Promise<Template> {
	const { data } = await axios.post<{ template: Template }>(endpoint(`/templates/${id}/archive`))

	return data.template
}

/**
 * Restore an archived template to the active listing.
 *
 * @param id Template identifier.
 */
export async function unarchiveTemplate(id: number): Promise<Template> {
	const { data } = await axios.post<{ template: Template }>(endpoint(`/templates/${id}/unarchive`))

	return data.template
}

/**
 * Duplicate a template into a new draft owned by the current user.
 *
 * @param id Template identifier.
 */
export async function duplicateTemplate(id: number): Promise<Template> {
	const { data } = await axios.post<{ template: Template }>(endpoint(`/templates/${id}/duplicate`))

	return data.template
}

/**
 * Append a section to a template.
 *
 * @param templateId Parent template identifier.
 * @param payload Section fields.
 */
export async function createSection(templateId: number, payload: SectionPayload): Promise<TemplateSection> {
	const { data } = await axios.post<{ section: TemplateSection }>(endpoint(`/templates/${templateId}/sections`), payload)

	return data.section
}

/**
 * Update a section.
 *
 * @param id Section identifier.
 * @param payload Section fields to update.
 */
export async function updateSection(id: number, payload: SectionPayload): Promise<TemplateSection> {
	const { data } = await axios.patch<{ section: TemplateSection }>(endpoint(`/sections/${id}`), payload)

	return data.section
}

/**
 * Delete a section and its steps.
 *
 * @param id Section identifier.
 */
export async function deleteSection(id: number): Promise<void> {
	await axios.delete(endpoint(`/sections/${id}`))
}

/**
 * Move a section to a new position.
 *
 * @param id Section identifier.
 * @param position Zero-based target position.
 */
export async function reorderSection(id: number, position: number): Promise<TemplateSection[]> {
	const { data } = await axios.post<{ sections: TemplateSection[] }>(endpoint(`/sections/${id}/reorder`), { position })

	return data.sections
}

/**
 * Append a step to a section.
 *
 * @param sectionId Parent section identifier.
 * @param payload Step fields.
 */
export async function createStep(sectionId: number, payload: StepPayload): Promise<TemplateStep> {
	const { data } = await axios.post<{ step: TemplateStep }>(endpoint(`/sections/${sectionId}/steps`), payload)

	return data.step
}

/**
 * Update a step.
 *
 * @param id Step identifier.
 * @param payload Step fields to update.
 */
export async function updateStep(id: number, payload: StepPayload): Promise<TemplateStep> {
	const { data } = await axios.patch<{ step: TemplateStep }>(endpoint(`/steps/${id}`), payload)

	return data.step
}

/**
 * Delete a step.
 *
 * @param id Step identifier.
 */
export async function deleteStep(id: number): Promise<void> {
	await axios.delete(endpoint(`/steps/${id}`))
}

/**
 * Move a step to a new position within its section.
 *
 * @param id Step identifier.
 * @param position Zero-based target position.
 */
export async function reorderStep(id: number, position: number): Promise<TemplateStep[]> {
	const { data } = await axios.post<{ steps: TemplateStep[] }>(endpoint(`/steps/${id}/reorder`), { position })

	return data.steps
}

/**
 * Load the access control list of a template.
 *
 * @param id Template identifier.
 */
export async function getTemplateAcl(id: number): Promise<TemplateAcl> {
	const { data } = await axios.get<TemplateAcl>(endpoint(`/templates/${id}/acl`))

	return data
}

/**
 * Replace the complete access control list of a template.
 *
 * @param id Template identifier.
 * @param entries Complete list of normalized ACL entries.
 */
export async function replaceTemplateAcl(id: number, entries: AclEntryPayload[]): Promise<TemplateAcl> {
	const { data } = await axios.put<TemplateAcl>(endpoint(`/templates/${id}/acl`), { entries })

	return data
}

/**
 * Search Nextcloud users and groups for the ACL editor.
 *
 * @param search Search term.
 * @param limit Maximum number of results.
 */
export async function searchPrincipals(search: string, limit = 25): Promise<Principal[]> {
	const { data } = await axios.get<{ principals: Principal[] }>(endpoint('/principals'), {
		params: { search, limit },
	})

	return data.principals
}

/**
 * Export a template as a portable JSON document.
 *
 * @param id Template identifier.
 */
export async function exportTemplate(id: number): Promise<TemplateExportDocument> {
	const { data } = await axios.get<TemplateExportDocument>(endpoint(`/templates/${id}/export`))

	return data
}

/**
 * Import a portable template export document as a new draft owned by the
 * current user.
 *
 * @param document Export document exactly as parsed from the selected file.
 */
export async function importTemplate(document: TemplateExportDocument): Promise<Template> {
	const { data } = await axios.post<{ template: Template }>(endpoint('/templates/import'), document)

	return data.template
}
