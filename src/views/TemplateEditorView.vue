<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	SectionPayload,
	SectionWithSteps,
	StepPayload,
	Template,
	TemplateDetail,
	TemplatePermissions,
	TemplateSection,
} from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AclEditor from '../components/AclEditor.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import SectionEditor from '../components/SectionEditor.vue'
import StartRunDialog from '../components/StartRunDialog.vue'
import TemplateStatusBadge from '../components/TemplateStatusBadge.vue'
import * as api from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const props = defineProps<{
	templateId: number
}>()

const emit = defineEmits<{
	close: []
	runStarted: [runId: number]
}>()

const detail = ref<TemplateDetail | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)
const title = ref('')
const description = ref('')
const pendingAction = ref<'archive' | 'delete' | null>(null)
const showStartRun = ref(false)

const template = computed<Template | null>(() => detail.value?.template ?? null)
const sections = computed<SectionWithSteps[]>(() => detail.value?.sections ?? [])
const permissions = computed<TemplatePermissions | null>(() => detail.value?.permissions ?? null)
const isArchived = computed<boolean>(() => template.value?.status === 'ARCHIVED')
const canEdit = computed<boolean>(() => permissions.value?.canEdit ?? false)
const isOwner = computed<boolean>(() => permissions.value?.role === 'OWNER')

const effectiveRoleLabel = computed<string>(() => {
	switch (permissions.value?.role) {
		case 'OWNER':
			return t('runbook', 'Owner')
		case 'EDITOR':
			return t('runbook', 'Editor')
		case 'EXECUTOR':
			return t('runbook', 'Executor')
		case 'VIEWER':
			return t('runbook', 'Viewer')
		default:
			return ''
	}
})

const confirmName = computed<string>(() => pendingAction.value === 'archive'
	? t('runbook', 'Archive template')
	: t('runbook', 'Delete template'))
const confirmMessage = computed<string>(() => pendingAction.value === 'archive'
	? t('runbook', 'Archived templates can no longer be edited or published.')
	: t('runbook', 'This permanently deletes the template, its sections and its steps.'))
const confirmLabel = computed<string>(() => pendingAction.value === 'archive'
	? t('runbook', 'Archive')
	: t('runbook', 'Delete'))

/**
 * Load the template detail from the API.
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		const loaded = await api.getTemplate(props.templateId)
		detail.value = loaded
		title.value = loaded.template.title
		description.value = loaded.template.description
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.templateId, load)

/**
 * Apply an updated template to the local detail state.
 *
 * @param updated Updated template entity.
 */
function applyTemplate(updated: Template): void {
	if (detail.value === null) {
		return
	}
	detail.value.template = updated
	title.value = updated.title
	description.value = updated.description
}

/**
 * Find the section entry with its steps.
 *
 * @param sectionId Section identifier.
 */
function findEntry(sectionId: number): SectionWithSteps | undefined {
	return detail.value?.sections.find((entry) => entry.section.id === sectionId)
}

/**
 * Run a mutation while tracking the busy and error state.
 *
 * @param action Async action performing the API calls.
 */
async function run(action: () => Promise<void>): Promise<void> {
	busy.value = true
	error.value = null
	try {
		await action()
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		busy.value = false
	}
}

/**
 *
 */
function saveMetadata(): void {
	void run(async () => {
		applyTemplate(await api.updateTemplate(props.templateId, { title: title.value, description: description.value }))
	})
}

/**
 *
 */
function publish(): void {
	void run(async () => {
		applyTemplate(await api.publishTemplate(props.templateId))
	})
}

/**
 * Handle a newly started run.
 *
 * @param runId Newly created run identifier.
 */
function onRunStarted(runId: number): void {
	showStartRun.value = false
	emit('runStarted', runId)
}

/**
 *
 */
function confirmAction(): void {
	const action = pendingAction.value
	pendingAction.value = null
	if (action === 'archive') {
		void run(async () => {
			applyTemplate(await api.archiveTemplate(props.templateId))
		})
	} else if (action === 'delete') {
		void run(async () => {
			await api.deleteTemplate(props.templateId)
			emit('close')
		})
	}
}

/**
 *
 */
function addSection(): void {
	void run(async () => {
		if (detail.value === null) {
			return
		}
		const section = await api.createSection(props.templateId, { title: t('runbook', 'New section') })
		detail.value.sections.push({ section, steps: [] })
	})
}

/**
 * Persist section fields.
 *
 * @param sectionId Section identifier.
 * @param payload Section fields.
 */
function saveSection(sectionId: number, payload: SectionPayload): void {
	void run(async () => {
		replaceSection(await api.updateSection(sectionId, payload))
	})
}

/**
 * Replace a section in the local detail state.
 *
 * @param updated Updated section entity.
 */
function replaceSection(updated: TemplateSection): void {
	const entry = findEntry(updated.id)
	if (entry !== undefined) {
		entry.section = updated
	}
}

/**
 * Delete a section and its steps.
 *
 * @param sectionId Section identifier.
 */
function deleteSection(sectionId: number): void {
	void run(async () => {
		if (detail.value === null) {
			return
		}
		await api.deleteSection(sectionId)
		detail.value.sections = detail.value.sections.filter((entry) => entry.section.id !== sectionId)
	})
}

/**
 * Move a section up or down.
 *
 * @param sectionId Section identifier.
 * @param direction Direction to move the section in.
 */
function moveSection(sectionId: number, direction: 'up' | 'down'): void {
	void run(async () => {
		if (detail.value === null) {
			return
		}
		const current = findEntry(sectionId)
		if (current === undefined) {
			return
		}
		const position = direction === 'up' ? current.section.position - 1 : current.section.position + 1
		const ordered = await api.reorderSection(sectionId, position)
		const byId = new Map(detail.value.sections.map((entry) => [entry.section.id, entry]))
		detail.value.sections = ordered.map((section) => byId.get(section.id) ?? { section, steps: [] })
	})
}

/**
 * Append a step to a section.
 *
 * @param sectionId Parent section identifier.
 */
function addStep(sectionId: number): void {
	void run(async () => {
		const entry = findEntry(sectionId)
		if (entry === undefined) {
			return
		}
		const step = await api.createStep(sectionId, { title: t('runbook', 'New step'), type: 'CHECK' })
		entry.steps.push(step)
	})
}

/**
 * Persist step fields.
 *
 * @param sectionId Parent section identifier.
 * @param stepId Step identifier.
 * @param payload Step fields.
 */
function saveStep(sectionId: number, stepId: number, payload: StepPayload): void {
	void run(async () => {
		const updated = await api.updateStep(stepId, payload)
		const entry = findEntry(sectionId)
		if (entry !== undefined) {
			entry.steps = entry.steps.map((step) => step.id === updated.id ? updated : step)
		}
	})
}

/**
 * Delete a step.
 *
 * @param sectionId Parent section identifier.
 * @param stepId Step identifier.
 */
function deleteStep(sectionId: number, stepId: number): void {
	void run(async () => {
		await api.deleteStep(stepId)
		const entry = findEntry(sectionId)
		if (entry !== undefined) {
			entry.steps = entry.steps.filter((step) => step.id !== stepId)
		}
	})
}

/**
 * Move a step up or down within its section.
 *
 * @param sectionId Parent section identifier.
 * @param stepId Step identifier.
 * @param direction Direction to move the step in.
 */
function moveStep(sectionId: number, stepId: number, direction: 'up' | 'down'): void {
	void run(async () => {
		const entry = findEntry(sectionId)
		if (entry === undefined) {
			return
		}
		const current = entry.steps.find((step) => step.id === stepId)
		if (current === undefined) {
			return
		}
		const position = direction === 'up' ? current.position - 1 : current.position + 1
		entry.steps = await api.reorderStep(stepId, position)
	})
}
</script>

<template>
	<section class="runbook-editor">
		<header class="runbook-editor__header">
			<NcButton @click="emit('close')">
				{{ t('runbook', 'Back to templates') }}
			</NcButton>
		</header>

		<div v-if="loading" class="runbook-editor__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="detail === null"
			:name="t('runbook', 'Template not found')"
			:description="error ?? t('runbook', 'The template could not be loaded.')" />

		<template v-else>
			<div class="runbook-editor__meta">
				<h2>{{ template?.title || t('runbook', 'Untitled template') }}</h2>
				<div class="runbook-editor__badges">
					<TemplateStatusBadge v-if="template" :status="template.status" />
					<span>v{{ template?.version }}</span>
					<span v-if="permissions && !isOwner" class="runbook-editor__role">
						{{ t('runbook', 'Your access: {role}', { role: effectiveRoleLabel }) }}
					</span>
				</div>
				<div class="runbook-editor__lifecycle">
					<NcButton
						v-if="template && template.status === 'PUBLISHED' && permissions?.canExecute"
						variant="primary"
						:disabled="busy"
						@click="showStartRun = true">
						{{ t('runbook', 'Start run') }}
					</NcButton>
					<NcButton
						v-if="permissions?.canManageAcl && template && template.status !== 'PUBLISHED'"
						variant="primary"
						:disabled="busy"
						@click="publish">
						{{ t('runbook', 'Publish') }}
					</NcButton>
					<NcButton
						v-if="permissions?.canManageAcl"
						:disabled="busy"
						@click="pendingAction = 'archive'">
						{{ t('runbook', 'Archive') }}
					</NcButton>
					<NcButton
						v-if="permissions?.canDelete"
						variant="error"
						:disabled="busy"
						@click="pendingAction = 'delete'">
						{{ t('runbook', 'Delete') }}
					</NcButton>
				</div>
			</div>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="isArchived" type="warning">
				{{ t('runbook', 'This template is archived and can no longer be edited.') }}
			</NcNoteCard>

			<fieldset :disabled="!canEdit" class="runbook-editor__fields">
				<NcTextField v-model="title" :label="t('runbook', 'Title')" />
				<NcTextArea v-model="description" :label="t('runbook', 'Description')" />
				<div class="runbook-editor__fields-actions">
					<NcButton :disabled="busy || !canEdit" @click="saveMetadata">
						{{ t('runbook', 'Save changes') }}
					</NcButton>
				</div>
			</fieldset>

			<AclEditor
				v-if="isOwner"
				:templateId="templateId"
				:owner="template?.owner ?? ''"
				:readOnly="isArchived" />

			<fieldset :disabled="!canEdit" class="runbook-editor__sections">
				<div class="runbook-editor__sections-header">
					<h3>{{ t('runbook', 'Sections') }}</h3>
					<NcButton :disabled="busy || !canEdit" @click="addSection">
						{{ t('runbook', 'Add section') }}
					</NcButton>
				</div>

				<p v-if="sections.length === 0" class="runbook-editor__hint">
					{{ t('runbook', 'This template has no sections yet.') }}
				</p>

				<SectionEditor
					v-for="(entry, index) in sections"
					:key="entry.section.id"
					:section="entry.section"
					:steps="entry.steps"
					:busy="busy"
					:canMoveUp="index > 0"
					:canMoveDown="index < sections.length - 1"
					@save="(payload) => saveSection(entry.section.id, payload)"
					@delete="deleteSection(entry.section.id)"
					@moveUp="moveSection(entry.section.id, 'up')"
					@moveDown="moveSection(entry.section.id, 'down')"
					@addStep="addStep(entry.section.id)"
					@stepSave="(event) => saveStep(entry.section.id, event.stepId, event.payload)"
					@stepDelete="(stepId) => deleteStep(entry.section.id, stepId)"
					@stepMove="(event) => moveStep(entry.section.id, event.stepId, event.direction)" />
			</fieldset>
		</template>

		<StartRunDialog
			v-if="showStartRun"
			:templateId="templateId"
			:templateTitle="template?.title ?? ''"
			@started="onRunStarted"
			@close="showStartRun = false" />

		<ConfirmDialog
			v-if="pendingAction !== null"
			:name="confirmName"
			:message="confirmMessage"
			:confirmLabel="confirmLabel"
			:busy="busy"
			@confirm="confirmAction"
			@cancel="pendingAction = null" />
	</section>
</template>

<style scoped>
.runbook-editor {
	padding: 16px;
}

.runbook-editor__header {
	margin-bottom: 16px;
}

.runbook-editor__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-editor__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.runbook-editor__badges {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast, #555);
}

.runbook-editor__role {
	font-style: italic;
}

.runbook-editor__lifecycle {
	display: flex;
	gap: 8px;
	margin-left: auto;
}

.runbook-editor__fields {
	display: flex;
	flex-direction: column;
	gap: 8px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
}

.runbook-editor__fields-actions {
	display: flex;
	gap: 8px;
}

.runbook-editor__sections {
	border: 0;
	padding: 0;
	margin: 0;
	min-width: 0;
}

.runbook-editor__sections-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.runbook-editor__hint {
	color: var(--color-text-maxcontrast, #555);
}
</style>
