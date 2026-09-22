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
import type { EditorDraftState } from '../utils/editorDrafts.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AclEditor from '../components/AclEditor.vue'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import SectionEditor from '../components/SectionEditor.vue'
import SectionOutline from '../components/SectionOutline.vue'
import StartRunDialog from '../components/StartRunDialog.vue'
import TemplateStatusBadge from '../components/TemplateStatusBadge.vue'
import * as api from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'
import {
	applyStepSaveResult,
	discardDrafts,
	hasBlockingDrafts,
	intentScope,
} from '../utils/editorDrafts.ts'
import {
	conditionStepOptions,
	defaultSelectedSectionId,
	effectiveEditorPermissions,
	hasSection,
	neighborSectionId,
	sectionOutline,
} from '../utils/templateAuthoring.ts'

const props = defineProps<{
	templateId: number
}>()

const emit = defineEmits<{
	close: []
	runStarted: [runId: number]
	'update:dirty': [dirty: boolean]
}>()

type PendingIntent
	= | { type: 'switch', sectionId: number }
		| { type: 'step', stepId: number }
		| { type: 'add' }
		| { type: 'close' }
		| { type: 'run' }
		| { type: 'publish' }
		| { type: 'archive' }
		| { type: 'delete' }

const detail = ref<TemplateDetail | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
const busy = ref(false)
const title = ref('')
const description = ref('')
const pendingAction = ref<'archive' | 'delete' | null>(null)
const showStartRun = ref(false)

/** Focused section for the editor; selection only changes the editor focus. */
const selectedSectionId = ref<number | null>(null)
const sectionDirty = ref(false)
const stepDirty = ref(false)
const editingStepId = ref<number | null>(null)
const stepError = ref<string | null>(null)
/** Bumped to force the section editor to drop its draft on discard. */
const sectionResetToken = ref(0)
const pendingIntent = ref<PendingIntent | null>(null)

const template = computed<Template | null>(() => detail.value?.template ?? null)
const sections = computed<SectionWithSteps[]>(() => detail.value?.sections ?? [])
const outline = computed(() => sectionOutline(sections.value))
const selectedEntry = computed<SectionWithSteps | null>(() => sections.value.find((entry) => entry.section.id === selectedSectionId.value) ?? null)
const selectedOutlineItem = computed(() => outline.value.find((item) => item.id === selectedSectionId.value) ?? null)
const conditionOptions = computed(() => (selectedSectionId.value === null ? [] : conditionStepOptions(sections.value, selectedSectionId.value)))

const permissions = computed<TemplatePermissions | null>(() => detail.value?.permissions ?? null)
const isArchived = computed<boolean>(() => template.value?.status === 'ARCHIVED')
const isOwner = computed<boolean>(() => permissions.value?.role === 'OWNER')

/**
 * Effective UI permissions: server permissions combined with the current
 * template status, so archiving makes the page read-only immediately without a
 * reload.
 */
const effectivePermissions = computed(() => effectiveEditorPermissions(permissions.value, template.value?.status))
const canEdit = computed<boolean>(() => effectivePermissions.value.canEdit)
const canPublish = computed<boolean>(() => effectivePermissions.value.canPublish)
const canArchive = computed<boolean>(() => effectivePermissions.value.canArchive)
const canStartRun = computed<boolean>(() => effectivePermissions.value.canStartRun)
const canDelete = computed<boolean>(() => effectivePermissions.value.canDelete)

const metadataDirty = computed<boolean>(() => {
	const current = template.value
	return current !== null && (title.value !== current.title || description.value !== current.description)
})
const draftState = computed<EditorDraftState>(() => ({
	metadataDirty: metadataDirty.value,
	sectionDirty: sectionDirty.value,
	stepDirty: stepDirty.value,
}))
const hasAnyDrafts = computed<boolean>(() => metadataDirty.value || sectionDirty.value || stepDirty.value)
watch(hasAnyDrafts, (value) => emit('update:dirty', value))

/** Container focused when the user explicitly selects a section. */
const editorContainer = ref<HTMLElement | null>(null)

/**
 * Move focus to the focused-section editor after an explicit section selection.
 */
async function focusEditor(): Promise<void> {
	await nextTick()
	editorContainer.value?.scrollIntoView({ block: 'nearest' })
	editorContainer.value?.focus({ preventScroll: true })
}

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
 * Other sections of the template that a section may depend on.
 *
 * @param sectionId Identifier of the section being edited.
 */
function siblingSections(sectionId: number): Array<{ id: number, title: string }> {
	return sections.value
		.filter((entry) => entry.section.id !== sectionId)
		.map((entry) => ({ id: entry.section.id, title: entry.section.title }))
}

/** Monotonic load sequence so an earlier response cannot overwrite a newer template. */
let loadSequence = 0

/**
 * Load the template detail from the API. Each load gets a sequence so a slower
 * earlier request (template A) can never overwrite the latest one (template B).
 */
async function load(): Promise<void> {
	const templateId = props.templateId
	const sequence = ++loadSequence

	loading.value = true
	error.value = null
	try {
		const loaded = await api.getTemplate(templateId)
		if (sequence !== loadSequence) {
			return
		}
		detail.value = loaded
		title.value = loaded.template.title
		description.value = loaded.template.description
		if (!hasSection(loaded.sections, selectedSectionId.value)) {
			selectedSectionId.value = defaultSelectedSectionId(loaded.sections)
		}
		sectionDirty.value = false
		stepDirty.value = false
		editingStepId.value = null
		stepError.value = null
	} catch (caught) {
		if (sequence !== loadSequence) {
			return
		}
		error.value = apiErrorMessage(caught)
	} finally {
		if (sequence === loadSequence) {
			loading.value = false
		}
	}
}

onMounted(load)
watch(() => props.templateId, () => {
	// Drop stale detail immediately so template A is never shown under B's route.
	detail.value = null
	title.value = ''
	description.value = ''
	selectedSectionId.value = null
	sectionDirty.value = false
	stepDirty.value = false
	editingStepId.value = null
	stepError.value = null
	void load()
})

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
 * Perform a navigation or lifecycle intent.
 *
 * @param intent Intent to perform.
 */
function performIntent(intent: PendingIntent): void {
	pendingIntent.value = null
	switch (intent.type) {
		case 'switch':
			selectedSectionId.value = intent.sectionId
			sectionDirty.value = false
			stepDirty.value = false
			editingStepId.value = null
			stepError.value = null
			void focusEditor()
			break
		case 'step':
			editingStepId.value = intent.stepId
			stepDirty.value = false
			stepError.value = null
			break
		case 'add':
			addSection()
			break
		case 'close':
			emit('close')
			break
		case 'run':
			showStartRun.value = true
			break
		case 'publish':
			publish()
			break
		case 'archive':
			pendingAction.value = 'archive'
			break
		case 'delete':
			pendingAction.value = 'delete'
			break
	}
}

/**
 * Restore the template metadata draft to its saved value.
 */
function resetMetadataDraft(): void {
	const current = template.value
	if (current !== null) {
		title.value = current.title
		description.value = current.description
	}
}

/**
 * Request an intent, warning only about the drafts it would actually discard.
 *
 * @param intent Intent to request.
 */
function requestIntent(intent: PendingIntent): void {
	if (hasBlockingDrafts(draftState.value, intentScope(intent.type))) {
		pendingIntent.value = intent
		return
	}
	performIntent(intent)
}

/**
 * Confirm discarding the drafts covered by the pending intent, then continue.
 */
function confirmDiscard(): void {
	const intent = pendingIntent.value
	if (intent === null) {
		return
	}
	pendingIntent.value = null

	const outcome = discardDrafts(draftState.value, intentScope(intent.type))
	sectionDirty.value = outcome.state.sectionDirty
	stepDirty.value = outcome.state.stepDirty
	if (outcome.resetMetadataDraft) {
		resetMetadataDraft()
	}
	if (outcome.resetSectionDraft) {
		sectionResetToken.value += 1
	}
	if (outcome.closeStepEditor) {
		editingStepId.value = null
		stepError.value = null
	}

	performIntent(intent)
}

/**
 * Open a step for editing, warning if another step has unsaved edits.
 *
 * @param stepId Step identifier.
 */
function startStepEdit(stepId: number): void {
	if (editingStepId.value === stepId) {
		return
	}
	requestIntent({ type: 'step', stepId })
}

/**
 * Close the step editor, discarding its draft.
 */
function cancelStepEdit(): void {
	editingStepId.value = null
	stepError.value = null
	stepDirty.value = false
}

/**
 * Save the template metadata.
 */
function saveMetadata(): void {
	void run(async () => {
		applyTemplate(await api.updateTemplate(props.templateId, { title: title.value, description: description.value }))
	})
}

/**
 * Publish the template.
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
 * Confirm the pending archive/delete action.
 */
function confirmAction(): void {
	const action = pendingAction.value
	pendingAction.value = null
	if (action === 'archive') {
		void run(async () => {
			applyTemplate(await api.archiveTemplate(props.templateId))
			// Archived is immediately read-only: close and discard any open form.
			editingStepId.value = null
			stepError.value = null
			stepDirty.value = false
			sectionDirty.value = false
			sectionResetToken.value += 1
		})
	} else if (action === 'delete') {
		void run(async () => {
			await api.deleteTemplate(props.templateId)
			emit('close')
		})
	}
}

/**
 * Select a section to edit, guarding unsaved changes.
 *
 * @param sectionId Section identifier.
 */
function selectSection(sectionId: number): void {
	if (sectionId === selectedSectionId.value) {
		return
	}
	requestIntent({ type: 'switch', sectionId })
}

/**
 * Create a section and focus it.
 */
function addSection(): void {
	void run(async () => {
		if (detail.value === null) {
			return
		}
		const section = await api.createSection(props.templateId, { title: t('runbook', 'New section') })
		detail.value.sections.push({ section, steps: [] })
		selectedSectionId.value = section.id
		sectionDirty.value = false
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
		sectionDirty.value = false
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
 * Delete a section and its steps, then focus a sensible neighbour.
 *
 * @param sectionId Section identifier.
 */
function deleteSection(sectionId: number): void {
	void run(async () => {
		if (detail.value === null) {
			return
		}
		const neighbour = neighborSectionId(detail.value.sections, sectionId)
		await api.deleteSection(sectionId)
		detail.value.sections = detail.value.sections.filter((entry) => entry.section.id !== sectionId)
		if (selectedSectionId.value === sectionId) {
			selectedSectionId.value = hasSection(detail.value.sections, neighbour)
				? neighbour
				: defaultSelectedSectionId(detail.value.sections)
		}
		sectionDirty.value = false
	})
}

/**
 * Move a section up or down, preserving the selected section by id.
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
	void (async () => {
		busy.value = true
		stepError.value = null
		try {
			const updated = await api.updateStep(stepId, payload)
			const entry = findEntry(sectionId)
			if (entry !== undefined) {
				entry.steps = entry.steps.map((step) => step.id === updated.id ? updated : step)
			}
			const result = applyStepSaveResult(editingStepId.value, true, null)
			editingStepId.value = result.editingStepId
			stepError.value = result.error
			stepDirty.value = false
		} catch (caught) {
			// Keep the form open with its draft on failure.
			const result = applyStepSaveResult(editingStepId.value, false, apiErrorMessage(caught))
			editingStepId.value = result.editingStepId
			stepError.value = result.error
		} finally {
			busy.value = false
		}
	})()
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

/**
 * Save the focused section's fields.
 *
 * @param payload Section fields.
 */
function saveSelectedSection(payload: SectionPayload): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		saveSection(entry.section.id, payload)
	}
}

/**
 *
 */
function deleteSelectedSection(): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		deleteSection(entry.section.id)
	}
}

/**
 * @param direction Direction to move the focused section in.
 */
function moveSelectedSection(direction: 'up' | 'down'): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		moveSection(entry.section.id, direction)
	}
}

/**
 *
 */
function addStepToSelectedSection(): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		addStep(entry.section.id)
	}
}

/**
 * @param event Step save event.
 * @param event.stepId Step identifier.
 * @param event.payload Step fields.
 */
function saveSelectedStep(event: { stepId: number, payload: StepPayload }): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		saveStep(entry.section.id, event.stepId, event.payload)
	}
}

/**
 * @param stepId Step identifier.
 */
function deleteSelectedStep(stepId: number): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		deleteStep(entry.section.id, stepId)
	}
}

/**
 * @param event Step move event.
 * @param event.stepId Step identifier.
 * @param event.direction Direction to move the step in.
 */
function moveSelectedStep(event: { stepId: number, direction: 'up' | 'down' }): void {
	const entry = selectedEntry.value
	if (entry !== null) {
		moveStep(entry.section.id, event.stepId, event.direction)
	}
}

/**
 * Discard every unsaved draft (metadata, section and step). Used by the app
 * shell when a guarded navigation is confirmed.
 */
function discardAllDrafts(): void {
	resetMetadataDraft()
	sectionDirty.value = false
	stepDirty.value = false
	editingStepId.value = null
	stepError.value = null
	pendingIntent.value = null
	sectionResetToken.value += 1
}

/**
 * Whether the editor currently has any unsaved draft. Read synchronously by the
 * app shell so a decide-then-emit sequence cannot double-prompt.
 */
function hasDrafts(): boolean {
	return metadataDirty.value || sectionDirty.value || stepDirty.value
}

defineExpose({ discardAllDrafts, hasDrafts })
</script>

<template>
	<section class="runbook-author">
		<header class="runbook-author__header">
			<NcButton @click="requestIntent({ type: 'close' })">
				{{ t('runbook', 'Back to templates') }}
			</NcButton>

			<div v-if="detail" class="runbook-author__heading">
				<h2 class="runbook-author__title">
					{{ template?.title || t('runbook', 'Untitled template') }}
				</h2>
				<div class="runbook-author__context">
					<TemplateStatusBadge v-if="template" :status="template.status" />
					<span>v{{ template?.version }}</span>
					<span v-if="permissions && !isOwner">
						{{ t('runbook', 'Your access: {role}', { role: effectiveRoleLabel }) }}
					</span>
				</div>
			</div>

			<div v-if="detail" class="runbook-author__actions">
				<div class="runbook-author__actions-primary">
					<NcButton
						v-if="canStartRun"
						variant="primary"
						:disabled="busy"
						@click="requestIntent({ type: 'run' })">
						{{ t('runbook', 'Start run') }}
					</NcButton>
					<NcButton
						v-else-if="canPublish"
						variant="primary"
						:disabled="busy"
						@click="requestIntent({ type: 'publish' })">
						{{ t('runbook', 'Publish') }}
					</NcButton>
				</div>
				<div class="runbook-author__actions-destructive">
					<NcButton v-if="canArchive" :disabled="busy" @click="requestIntent({ type: 'archive' })">
						{{ t('runbook', 'Archive') }}
					</NcButton>
					<NcButton
						v-if="canDelete"
						variant="error"
						:disabled="busy"
						@click="requestIntent({ type: 'delete' })">
						{{ t('runbook', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</header>

		<div v-if="loading" class="runbook-author__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="detail === null"
			:name="t('runbook', 'Template not found')"
			:description="error ?? t('runbook', 'The template could not be loaded.')" />

		<template v-else>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="isArchived" type="info">
				{{ t('runbook', 'This template is archived and read-only. Unarchive it to make changes.') }}
			</NcNoteCard>
			<NcNoteCard v-else-if="!canEdit" type="info">
				{{ t('runbook', 'You have read-only access to this template.') }}
			</NcNoteCard>

			<details class="runbook-author__panel" open>
				<summary>{{ t('runbook', 'Template details') }}</summary>
				<fieldset :disabled="!canEdit" class="runbook-author__fields">
					<NcTextField v-model="title" :label="t('runbook', 'Title')" />
					<NcTextArea v-model="description" :label="t('runbook', 'Description')" />
					<div class="runbook-author__fields-actions">
						<NcButton :disabled="busy || !canEdit || !metadataDirty" @click="saveMetadata">
							{{ t('runbook', 'Save changes') }}
						</NcButton>
					</div>
				</fieldset>
			</details>

			<details v-if="isOwner" class="runbook-author__panel">
				<summary>{{ t('runbook', 'Access') }}</summary>
				<AclEditor :templateId="templateId" :owner="template?.owner ?? ''" :readOnly="isArchived" />
			</details>

			<section class="runbook-author__workspace">
				<div class="runbook-author__workspace-header">
					<h3>{{ t('runbook', 'Sections') }}</h3>
					<NcButton :disabled="busy || !canEdit" @click="requestIntent({ type: 'add' })">
						{{ t('runbook', 'Add section') }}
					</NcButton>
				</div>

				<div v-if="sections.length === 0" class="runbook-author__empty">
					<NcEmptyContent
						:name="t('runbook', 'No sections yet')"
						:description="t('runbook', 'Add your first section to start building this runbook.')">
						<template #action>
							<NcButton :disabled="busy || !canEdit" variant="primary" @click="requestIntent({ type: 'add' })">
								{{ t('runbook', 'Add section') }}
							</NcButton>
						</template>
					</NcEmptyContent>
				</div>

				<div v-else class="runbook-author__body">
					<div class="runbook-author__outline">
						<SectionOutline :items="outline" :selectedId="selectedSectionId" @select="selectSection" />
					</div>
					<div ref="editorContainer" class="runbook-author__editor" tabindex="-1">
						<SectionEditor
							v-if="selectedEntry"
							:key="selectedEntry.section.id"
							:section="selectedEntry.section"
							:steps="selectedEntry.steps"
							:position="selectedOutlineItem?.position ?? 0"
							:siblingSections="siblingSections(selectedEntry.section.id)"
							:conditionSteps="conditionOptions"
							:busy="busy || !canEdit"
							:canEdit="canEdit"
							:editingStepId="editingStepId"
							:stepError="stepError"
							:resetToken="sectionResetToken"
							:canMoveUp="(selectedOutlineItem?.position ?? 1) > 1"
							:canMoveDown="(selectedOutlineItem?.position ?? 0) < sections.length"
							@save="saveSelectedSection"
							@delete="deleteSelectedSection"
							@moveUp="moveSelectedSection('up')"
							@moveDown="moveSelectedSection('down')"
							@addStep="addStepToSelectedSection"
							@stepEdit="startStepEdit"
							@stepCancel="cancelStepEdit"
							@stepDirty="stepDirty = $event"
							@stepSave="saveSelectedStep"
							@stepDelete="deleteSelectedStep"
							@stepMove="moveSelectedStep"
							@update:dirty="sectionDirty = $event" />
					</div>
				</div>
			</section>
		</template>

		<StartRunDialog
			v-if="showStartRun && template"
			:templateId="templateId"
			:templateTitle="template.title"
			@started="onRunStarted"
			@close="showStartRun = false" />

		<ConfirmDialog
			v-if="pendingIntent !== null"
			:name="t('runbook', 'Discard unsaved changes')"
			:message="t('runbook', 'You have unsaved changes. Discard them?')"
			:confirmLabel="t('runbook', 'Discard')"
			:busy="false"
			@confirm="confirmDiscard"
			@cancel="pendingIntent = null" />

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
.runbook-author {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-3);
	max-width: var(--runbook-content-max);
	margin-inline: auto;
	padding: var(--runbook-space-4);
}

.runbook-author__header {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
}

.runbook-author__title {
	margin: 0;
	overflow-wrap: anywhere;
}

.runbook-author__context {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--runbook-space-2) var(--runbook-space-3);
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
}

.runbook-author__center {
	display: flex;
	justify-content: center;
	padding: var(--runbook-space-5);
}

.runbook-author__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: var(--runbook-space-2);
}

.runbook-author__actions-primary,
.runbook-author__actions-destructive {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-2);
}

.runbook-author__actions-destructive {
	padding-inline-start: var(--runbook-space-3);
	border-inline-start: 1px solid var(--runbook-border);
}

.runbook-author__panel {
	border: 1px solid var(--runbook-border);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface);
}

.runbook-author__panel > summary {
	display: flex;
	align-items: center;
	min-height: var(--runbook-target-min);
	padding: var(--runbook-space-2) var(--runbook-space-3);
	cursor: pointer;
	font-weight: 600;
}

.runbook-author__panel[open] > summary {
	border-bottom: 1px solid var(--runbook-border);
}

.runbook-author__fields {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	max-width: var(--runbook-content-narrow);
	padding: var(--runbook-space-3);
	border: 0;
	margin: 0;
}

.runbook-author__fields-actions {
	display: flex;
	gap: var(--runbook-space-2);
}

.runbook-author__workspace {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-3);
}

.runbook-author__workspace-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--runbook-space-2);
}

.runbook-author__workspace-header h3 {
	margin: 0;
}

.runbook-author__body {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-3);
}

.runbook-author__editor {
	min-width: 0;
}

.runbook-author__editor:focus-visible {
	outline: var(--runbook-focus-ring);
	outline-offset: 2px;
}

@media (min-width: 900px) {
	.runbook-author__body {
		flex-direction: row;
		align-items: flex-start;
	}

	.runbook-author__outline {
		flex: 0 0 var(--runbook-nav-width, 260px);
		position: sticky;
		top: var(--runbook-space-4);
	}

	.runbook-author__editor {
		flex: 1 1 auto;
		max-width: var(--runbook-content-narrow);
	}
}
</style>
