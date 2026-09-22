<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AppFeatures } from '../models/adminSettings.ts'
import type { Template, TemplateExportDocument } from '../models/template.ts'
import type { ImportSummary } from '../utils/templateImport.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import TemplateStatusBadge from '../components/TemplateStatusBadge.vue'
import { useTemplateList } from '../composables/useTemplateList.ts'
import { getFeatures } from '../services/adminSettings.ts'
import { exportTemplate, importTemplate } from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'
import { canCloseImportDialog, canStartImport, runTemplateImport } from '../utils/importFlow.ts'
import { downloadJson, exportFilename } from '../utils/templateExport.ts'
import { assertSourceFileSize, parseTemplateImport, TemplateImportError } from '../utils/templateImport.ts'

const props = withDefaults(defineProps<{
	archivedOnly?: boolean
}>(), {
	archivedOnly: false,
})

const emit = defineEmits<{
	open: [id: number]
}>()

const { templates, loading, error, refresh, create, remove, duplicate, unarchive } = useTemplateList()

const features = ref<AppFeatures | null>(null)
const showCreate = ref(false)
const createTitle = ref('')
const createDescription = ref('')
const creating = ref(false)
const pendingDelete = ref<Template | null>(null)
const pendingUnarchive = ref<Template | null>(null)
const deleting = ref(false)
const unarchiving = ref(false)
const duplicatingId = ref<number | null>(null)
const exportingId = ref<number | null>(null)
const exportError = ref<string | null>(null)
const showImport = ref(false)
const importFileName = ref('')
const importSummary = ref<ImportSummary | null>(null)
const importDocument = ref<TemplateExportDocument | null>(null)
const importing = ref(false)
const importError = ref<string | null>(null)
const importedTemplate = ref<Template | null>(null)
const importWarning = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const visibleTemplates = computed<Template[]>(() => props.archivedOnly
	? templates.value.filter((template) => template.status === 'ARCHIVED')
	: templates.value)

const canCreate = computed<boolean>(() => features.value?.canCreateTemplates ?? true)

/**
 * Whether the current user may duplicate a template.
 *
 * Only the template owner or a Nextcloud administrator may duplicate; the
 * server enforces this again, the UI simply hides the action.
 *
 * @param template Template to check.
 */
function canDuplicate(template: Template): boolean {
	if (features.value === null) {
		return false
	}

	return features.value.uid === template.owner || features.value.isAdmin
}

onMounted(() => {
	void refresh()
	void loadFeatures()
})

/**
 * Load the feature flags that affect template creation.
 */
async function loadFeatures(): Promise<void> {
	try {
		features.value = await getFeatures()
	} catch {
		// Fall back to the default (creation allowed); the server still enforces.
	}
}

/**
 * Format a Unix timestamp for display.
 *
 * @param timestamp Unix timestamp in seconds.
 */
function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString()
}

/**
 *
 */
async function submitCreate(): Promise<void> {
	creating.value = true
	const template = await create({ title: createTitle.value, description: createDescription.value })
	creating.value = false
	if (template !== null) {
		showCreate.value = false
		createTitle.value = ''
		createDescription.value = ''
		emit('open', template.id)
	}
}

/**
 *
 */
async function confirmDelete(): Promise<void> {
	if (pendingDelete.value === null) {
		return
	}
	deleting.value = true
	const success = await remove(pendingDelete.value.id)
	deleting.value = false
	if (success) {
		pendingDelete.value = null
	}
}

/**
 * Restore the pending archived template.
 */
async function confirmUnarchive(): Promise<void> {
	if (pendingUnarchive.value === null) {
		return
	}
	unarchiving.value = true
	const restored = await unarchive(pendingUnarchive.value.id)
	unarchiving.value = false
	if (restored !== null) {
		pendingUnarchive.value = null
	}
}

/**
 * Duplicate a template and open the copy.
 *
 * @param template Template to duplicate.
 */
async function duplicateTemplate(template: Template): Promise<void> {
	duplicatingId.value = template.id
	const copy = await duplicate(template.id)
	duplicatingId.value = null
	if (copy !== null) {
		emit('open', copy.id)
	}
}

/**
 * Download a portable JSON export of a template.
 *
 * @param template Template to export.
 */
async function exportTemplateFile(template: Template): Promise<void> {
	if (exportingId.value !== null) {
		return
	}
	exportingId.value = template.id
	exportError.value = null
	try {
		const document = await exportTemplate(template.id)
		downloadJson(exportFilename(template.title), document)
	} catch (caught) {
		exportError.value = apiErrorMessage(caught)
	} finally {
		exportingId.value = null
	}
}

/**
 * Translate a selected-file parsing failure or a server import error.
 *
 * @param caught Value thrown while parsing or importing.
 */
function importErrorMessage(caught: unknown): string {
	if (caught instanceof TemplateImportError) {
		if (caught.code === 'invalid_json') {
			return t('runbook', 'This file is not valid JSON.')
		}
		if (caught.code === 'too_large') {
			return t('runbook', 'This file is too large to import.')
		}

		return t('runbook', 'This file is not a Runbook template export.')
	}

	return apiErrorMessage(caught)
}

/**
 * Clear the import selection so the same file can be chosen again.
 *
 * The import dialog is only ever reset once no request is in flight, so a
 * successful creation can never be hidden by a late close.
 */
function resetImport(): void {
	showImport.value = false
	importFileName.value = ''
	importSummary.value = null
	importDocument.value = null
	importError.value = null
	if (fileInput.value !== null) {
		fileInput.value.value = ''
	}
}

/**
 * Handle a close request from the confirmation dialog.
 *
 * `@closing` is emitted after the dialog has already begun closing and is not
 * cancellable, so this only performs cleanup and must never be described as
 * vetoing closure. Suppression of the supported user close paths relies on
 * `noClose`/`closeOnClickOutside` plus the disabled Cancel button. A close that
 * slips through the tiny race before `importing` is reflected in the render
 * cannot cancel the in-flight request: it still completes and, on success, the
 * created template is preserved and offered for recovery.
 */
function onImportDialogClosing(): void {
	if (!canCloseImportDialog(importing.value)) {
		return
	}
	resetImport()
}

/**
 * Cancel the confirmation dialog, unless an import is in flight.
 */
function onImportCancel(): void {
	if (!canCloseImportDialog(importing.value)) {
		return
	}
	resetImport()
}

/**
 * Open the local file picker.
 */
function openImportPicker(): void {
	importError.value = null
	fileInput.value?.click()
}

/**
 * Read and parse the selected file, then show the confirmation dialog.
 *
 * Only the local source-file safety cap is checked here, before reading, so an
 * unreasonably large file is never loaded into memory. No document-size decision
 * is made in the browser: the server's canonical cap is authoritative, because
 * JavaScript and PHP serialise some values to different lengths.
 *
 * @param event File input change event.
 */
async function onImportFileSelected(event: Event): Promise<void> {
	const input = event.target as HTMLInputElement
	const file = input.files?.[0] ?? null
	input.value = ''
	if (file === null) {
		return
	}

	importError.value = null
	try {
		assertSourceFileSize(file.size)
		const parsed = parseTemplateImport(await file.text())
		importFileName.value = file.name
		importSummary.value = parsed.summary
		importDocument.value = parsed.document
		showImport.value = true
	} catch (caught) {
		importSummary.value = null
		importDocument.value = null
		importError.value = importErrorMessage(caught)
	}
}

/**
 * Create the new draft from the parsed document.
 *
 * A refresh or navigation problem after a successful creation is not reported
 * as a failed import: the created template is preserved and offered for reopening
 * so the user never retries (and duplicates) a template that already exists.
 */
async function confirmImport(): Promise<void> {
	if (!canStartImport(importing.value) || importDocument.value === null) {
		return
	}
	importing.value = true
	importError.value = null
	importWarning.value = null

	const result = await runTemplateImport(importDocument.value, {
		create: (document) => importTemplate(document),
		refresh: () => refresh(),
		open: (template) => emit('open', template.id),
	})
	importing.value = false

	if (result.status === 'failed') {
		importError.value = importErrorMessage(result.error)
		return
	}

	importedTemplate.value = result.template
	if (!result.refreshed) {
		importWarning.value = t('runbook', 'The template was created, but the list could not be refreshed.')
	} else if (!result.opened) {
		importWarning.value = t('runbook', 'The template was created, but it could not be opened automatically.')
	}
	resetImport()
}

/**
 * Open the template created by the last successful import.
 */
function openImportedTemplate(): void {
	if (importedTemplate.value === null) {
		return
	}
	emit('open', importedTemplate.value.id)
}
</script>

<template>
	<section class="runbook-view">
		<header class="runbook-view__header">
			<h2>{{ archivedOnly ? t('runbook', 'Archived templates') : t('runbook', 'Templates') }}</h2>
			<div v-if="canCreate" class="runbook-view__actions">
				<NcButton @click="openImportPicker">
					{{ t('runbook', 'Import template') }}
				</NcButton>
				<NcButton variant="primary" @click="showCreate = true">
					{{ t('runbook', 'New template') }}
				</NcButton>
			</div>
		</header>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcNoteCard v-if="exportError" type="error">
			{{ exportError }}
		</NcNoteCard>
		<NcNoteCard v-if="importError && !showImport" type="error">
			{{ importError }}
		</NcNoteCard>
		<NcNoteCard v-if="importWarning" type="warning">
			{{ importWarning }}
		</NcNoteCard>
		<NcNoteCard v-if="importedTemplate !== null" type="success">
			<p>
				{{ t('runbook', 'The template was created as a new draft.') }}
			</p>
			<p class="runbook-view__summary-title">
				{{ importedTemplate.title || t('runbook', 'Untitled template') }}
			</p>
			<div class="runbook-view__actions">
				<NcButton @click="openImportedTemplate">
					{{ t('runbook', 'Open imported template') }}
				</NcButton>
				<NcButton @click="importedTemplate = null; importWarning = null">
					{{ t('runbook', 'Dismiss') }}
				</NcButton>
			</div>
		</NcNoteCard>

		<NcNoteCard v-if="!canCreate" type="info">
			{{ t('runbook', 'Only certain users may create templates. Contact your administrator if you need to create one.') }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-view__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="visibleTemplates.length === 0"
			:name="archivedOnly ? t('runbook', 'No archived templates') : t('runbook', 'No templates yet')"
			:description="t('runbook', 'Create your first runbook template to get started.')">
			<template v-if="canCreate" #action>
				<NcButton @click="openImportPicker">
					{{ t('runbook', 'Import template') }}
				</NcButton>
				<NcButton variant="primary" @click="showCreate = true">
					{{ t('runbook', 'New template') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<ul v-else class="runbook-list">
			<li v-for="template in visibleTemplates" :key="template.id" class="runbook-list__item">
				<div class="runbook-list__main">
					<button type="button" class="runbook-list__title" @click="emit('open', template.id)">
						{{ template.title || t('runbook', 'Untitled template') }}
					</button>
					<div class="runbook-list__meta">
						<TemplateStatusBadge :status="template.status" />
						<span>v{{ template.version }}</span>
						<span>{{ formatDate(template.updatedAt) }}</span>
					</div>
				</div>
				<div class="runbook-list__actions">
					<NcButton @click="emit('open', template.id)">
						{{ t('runbook', 'Open') }}
					</NcButton>
					<NcButton :disabled="exportingId === template.id" @click="exportTemplateFile(template)">
						{{ exportingId === template.id ? t('runbook', 'Exporting…') : t('runbook', 'Export') }}
					</NcButton>
					<NcButton v-if="canDuplicate(template)" :disabled="duplicatingId === template.id" @click="duplicateTemplate(template)">
						{{ t('runbook', 'Duplicate') }}
					</NcButton>
					<NcButton v-if="template.status === 'ARCHIVED'" @click="pendingUnarchive = template">
						{{ t('runbook', 'Unarchive') }}
					</NcButton>
					<NcButton variant="error" @click="pendingDelete = template">
						{{ t('runbook', 'Delete') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<input
			ref="fileInput"
			type="file"
			accept=".json,application/json"
			class="runbook-view__file-input"
			@change="onImportFileSelected">

		<NcDialog v-if="showCreate" :name="t('runbook', 'New template')" @closing="showCreate = false">
			<NcTextField v-model="createTitle" :label="t('runbook', 'Title')" />
			<NcTextArea v-model="createDescription" :label="t('runbook', 'Description')" />
			<template #actions>
				<NcButton @click="showCreate = false">
					{{ t('runbook', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="creating" @click="submitCreate">
					{{ t('runbook', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>

		<NcDialog
			v-if="showImport && importSummary !== null"
			:name="t('runbook', 'Import template')"
			:noClose="importing"
			:closeOnClickOutside="!importing"
			@closing="onImportDialogClosing">
			<p>
				{{ t('runbook', 'A new draft owned by you will be created from this file. No existing template is changed.') }}
			</p>
			<p class="runbook-view__summary-title">
				{{ importSummary.title || t('runbook', 'Untitled template') }}
			</p>
			<p v-if="importSummary.description !== ''">
				{{ importSummary.description }}
			</p>
			<p>
				{{ t('runbook', 'Sections') }}: {{ importSummary.sections }}
				&middot;
				{{ t('runbook', 'Steps') }}: {{ importSummary.steps }}
			</p>
			<p v-if="importFileName !== ''" class="runbook-view__file-name">
				{{ importFileName }}
			</p>
			<NcNoteCard v-if="importError" type="error">
				{{ importError }}
			</NcNoteCard>
			<template #actions>
				<NcButton :disabled="importing" @click="onImportCancel">
					{{ t('runbook', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="importing" @click="confirmImport">
					{{ importing ? t('runbook', 'Importing…') : t('runbook', 'Import') }}
				</NcButton>
			</template>
		</NcDialog>

		<ConfirmDialog
			v-if="pendingDelete !== null"
			:name="t('runbook', 'Delete template')"
			:message="t('runbook', 'This permanently deletes the template, its sections and its steps.')"
			:confirmLabel="t('runbook', 'Delete')"
			:busy="deleting"
			@confirm="confirmDelete"
			@cancel="pendingDelete = null" />

		<ConfirmDialog
			v-if="pendingUnarchive !== null"
			:name="t('runbook', 'Unarchive template')"
			:message="t('runbook', 'Restore this template to the active list. New runs can be started once it is published.')"
			:confirmLabel="t('runbook', 'Unarchive')"
			:busy="unarchiving"
			@confirm="confirmUnarchive"
			@cancel="pendingUnarchive = null" />
	</section>
</template>

<style scoped>
.runbook-view {
	padding: 16px;
}

.runbook-view__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 16px;
}

.runbook-view__actions {
	display: flex;
	gap: 8px;
}

.runbook-view__file-input {
	display: none;
}

.runbook-view__summary-title {
	font-weight: bold;
}

.runbook-view__file-name {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-view__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.runbook-list__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	margin-bottom: 8px;
}

.runbook-list__main {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.runbook-list__title {
	background: none;
	border: none;
	padding: 0;
	font-size: 1.1em;
	font-weight: bold;
	cursor: pointer;
	text-align: left;
}

.runbook-list__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-list__actions {
	display: flex;
	gap: 4px;
}
</style>
