<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AppFeatures } from '../models/adminSettings.ts'
import type { RunAttachment, RunDetail, RunSectionWithSteps, StepAssignmentPayload, StepResponse } from '../models/run.ts'
import type { RunNavigationState } from '../utils/runNavigation.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import RunAclEditor from '../components/RunAclEditor.vue'
import RunActivityPanel from '../components/RunActivityPanel.vue'
import RunCommentsPanel from '../components/RunCommentsPanel.vue'
import RunEvidencePanel from '../components/RunEvidencePanel.vue'
import RunStatusBadge from '../components/RunStatusBadge.vue'
import RunStepCard from '../components/RunStepCard.vue'
import SectionNavigator from '../components/SectionNavigator.vue'
import SectionStatus from '../components/SectionStatus.vue'
import { getFeatures } from '../services/adminSettings.ts'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'
import { buildCopyEvidencePayload } from '../utils/copyEvidence.ts'
import { attachmentsForStep as attachmentsForStepHelper, degradedEvidenceNotice } from '../utils/runEvidence.ts'
import {
	nextActionHint,
	runProgressDisplay,
	sectionOfStep,
} from '../utils/runExecution.ts'
import {
	applyLoad,
	beginLoad,
	emptyNavigation,
	isStaleLoad,
	requestFocus,
	selectSection as selectNavSection,
} from '../utils/runNavigation.ts'

const props = defineProps<{
	runId: number
	focusStepId?: number | null
}>()

const emit = defineEmits<{
	close: []
}>()

const root = ref<HTMLElement | null>(null)
const highlightedStepId = ref<number | null>(null)
const detail = ref<RunDetail | null>(null)
const attachments = ref<RunAttachment[]>([])
const features = ref<AppFeatures | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const showCancel = ref(false)
const showDelete = ref(false)

/**
 * Navigation state for the execution navigator. Selecting a section never
 * changes flow state or grants an action; it only changes what is displayed.
 * A deep link is a one-shot intent (`pendingFocusStepId`) that is applied once
 * against the run it belongs to.
 */
const navigation = ref<RunNavigationState>(emptyNavigation())
const selectedSectionId = computed<number | null>(() => navigation.value.selectedSectionId)
/** Focused section container, focused on explicit user selection. */
const sectionContainer = ref<HTMLElement | null>(null)
/** Run the currently loaded `detail` belongs to. */
const loadedRunId = ref<number | null>(null)
/** Monotonic load sequence so an older request cannot overwrite a newer run. */
let loadSequence = 0

const runActive = computed<boolean>(() => detail.value?.run.status === 'ACTIVE')
const steps = computed(() => detail.value?.sections.flatMap((entry) => entry.steps) ?? [])
const stepTitles = computed<Record<number, string>>(() => Object.fromEntries(steps.value.map((step) => [step.id, step.title])))
const commentSteps = computed(() => steps.value.map((step) => ({ id: step.id, title: step.title })))

const progressDisplay = computed(() => (detail.value === null ? null : runProgressDisplay(detail.value)))
const nextHint = computed(() => (detail.value === null ? { kind: 'idle' as const } : nextActionHint(detail.value)))
const singleHint = computed(() => (nextHint.value.kind === 'single' ? nextHint.value : null))
const optionsHint = computed(() => (nextHint.value.kind === 'options' ? nextHint.value : null))
const showReadyHint = computed<boolean>(() => nextHint.value.kind === 'ready')
const showWaitingHint = computed<boolean>(() => nextHint.value.kind === 'waiting')
const selectedEntry = computed<RunSectionWithSteps | null>(() => {
	if (detail.value === null || selectedSectionId.value === null) {
		return null
	}

	return detail.value.sections.find((entry) => entry.section.id === selectedSectionId.value) ?? null
})

const commentsEnabled = computed<boolean>(() => features.value?.commentsEnabled ?? true)
const stepReopenEnabled = computed<boolean>(() => features.value?.stepReopenEnabled ?? true)
const runReopenEnabled = computed<boolean>(() => features.value?.runReopenEnabled ?? true)
const requireSkipReason = computed<boolean>(() => features.value?.requireSkipReason ?? true)

/**
 * Per-section collapse override. Only sections that are fully resolved
 * (every step completed or skipped) default to collapsed; unresolved sections
 * stay expanded. An explicit user toggle is remembered while the run is open.
 */
const sectionOverrides = ref<Record<number, boolean>>({})
const editingNotesId = ref<number | null>(null)
const notesDraft = ref('')
const sectionReturnId = ref<number | null>(null)
const sectionReturnReason = ref('')

/**
 * Whether every step of a section is resolved (completed or skipped).
 *
 * @param entry Section with its steps.
 */
function isSectionResolved(entry: RunSectionWithSteps): boolean {
	return entry.steps.length > 0
		&& entry.steps.every((step) => step.status === 'COMPLETED' || step.status === 'SKIPPED')
}

/**
 * Whether a section is currently collapsed.
 *
 * @param entry Section with its steps.
 */
function isCollapsed(entry: RunSectionWithSteps): boolean {
	return sectionOverrides.value[entry.section.id] ?? isSectionResolved(entry)
}

/**
 * Toggle a section's collapsed state.
 *
 * @param entry Section with its steps.
 */
function toggleSection(entry: RunSectionWithSteps): void {
	sectionOverrides.value = {
		...sectionOverrides.value,
		[entry.section.id]: !isCollapsed(entry),
	}
}

/**
 * Focus a section without changing its flow state. This is only called for an
 * explicit user selection, so it may move focus to the section content;
 * background reloads never do.
 *
 * @param sectionId Run section identifier.
 */
async function selectSection(sectionId: number): Promise<void> {
	if (sectionId === selectedSectionId.value) {
		return
	}
	navigation.value = selectNavSection(navigation.value, sectionId)
	await nextTick()
	sectionContainer.value?.scrollIntoView({ block: 'nearest' })
	sectionContainer.value?.focus({ preventScroll: true })
}
/**
 * Whether a section can be returned to execution for correction.
 *
 * @param entry Section with its steps.
 */
function canReturnSection(entry: RunSectionWithSteps): boolean {
	return runActive.value
		&& detail.value?.permissions.canManage === true
		&& entry.state === 'resolved'
		&& entry.steps.length > 0
}

/**
 * Start returning a section to execution.
 *
 * @param sectionId Run section identifier.
 */
function startSectionReturn(sectionId: number): void {
	sectionReturnId.value = sectionId
	sectionReturnReason.value = ''
}

/**
 * Confirm the section return with the entered reason.
 *
 * @param sectionId Run section identifier.
 */
function submitSectionReturn(sectionId: number): void {
	const reason = sectionReturnReason.value.trim()
	if (reason === '') {
		return
	}
	sectionReturnId.value = null
	sectionReturnReason.value = ''
	void mutate(() => api.returnRunSection(sectionId, reason))
}

/**
 * Return a single step to execution for correction.
 *
 * @param stepId Run step identifier.
 * @param reason Mandatory return reason.
 */
function returnStep(stepId: number, reason: string): void {
	void mutate(() => api.returnStep(stepId, reason))
}

/**
 * Start editing a section's notes.
 *
 * @param entry Section with its steps.
 */
function startNotes(entry: RunSectionWithSteps): void {
	editingNotesId.value = entry.section.id
	notesDraft.value = entry.section.notes
}

/**
 * Save the section notes currently being edited.
 *
 * @param sectionId Run section identifier.
 */
function saveNotes(sectionId: number): void {
	const value = notesDraft.value
	editingNotesId.value = null
	void mutate(() => api.updateRunSectionNotes(sectionId, value))
}

/**
 * Reset run-scoped UI state (selection, collapse, inline editors) and drop any
 * detail from a previously loaded run so it can never be used for navigation,
 * selection or rendering.
 */
function resetViewState(): void {
	navigation.value = emptyNavigation()
	loadedRunId.value = null
	detail.value = null
	attachments.value = []
	error.value = null
	sectionOverrides.value = {}
	editingNotesId.value = null
	sectionReturnId.value = null
	sectionReturnReason.value = ''
	highlightedStepId.value = null
}

/**
 * Load the run detail and its supporting data.
 *
 * Each load gets a monotonic sequence so a slower earlier request (for example
 * run A) can never overwrite the state of a newer one (run B).
 */
async function load(): Promise<void> {
	const runId = props.runId
	const { sequence, latest } = beginLoad(loadSequence)
	loadSequence = latest

	loading.value = true
	error.value = null
	try {
		const [run, files, flags] = await Promise.all([
			api.getRun(runId),
			api.listAttachments(runId),
			getFeatures(),
		])
		if (isStaleLoad(sequence, loadSequence)) {
			return
		}
		detail.value = run
		loadedRunId.value = run.run.id
		attachments.value = files
		features.value = flags
	} catch (caught) {
		if (isStaleLoad(sequence, loadSequence)) {
			return
		}
		error.value = apiErrorMessage(caught)
	} finally {
		if (!isStaleLoad(sequence, loadSequence)) {
			loading.value = false
		}
	}

	if (isStaleLoad(sequence, loadSequence)) {
		return
	}

	// Reconcile selection and apply a pending deep-link intent once, after the
	// loading state is cleared so the step anchors are rendered.
	await applyStepFocus()
}

onMounted(() => {
	navigation.value = requestFocus(navigation.value, props.runId, props.focusStepId ?? null)
	void load()
})
watch(() => props.runId, () => {
	resetViewState()
	navigation.value = requestFocus(navigation.value, props.runId, props.focusStepId ?? null)
	void load()
})
watch(() => props.focusStepId, (value) => {
	navigation.value = requestFocus(navigation.value, props.runId, value ?? null)
	void applyStepFocus()
})

/**
 * Scroll to and highlight a step, selecting and expanding its section first.
 *
 * @param stepId Run step identifier.
 * @param focus Whether to move keyboard focus to the step.
 */
async function revealStep(stepId: number, focus: boolean): Promise<void> {
	if (detail.value === null) {
		return
	}
	const entry = sectionOfStep(detail.value, stepId)
	if (entry === null) {
		highlightedStepId.value = null
		return
	}

	navigation.value = selectNavSection(navigation.value, entry.section.id)
	if (isCollapsed(entry)) {
		sectionOverrides.value = { ...sectionOverrides.value, [entry.section.id]: false }
	}
	highlightedStepId.value = stepId
	await nextTick()

	const element = root.value?.querySelector<HTMLElement>(`[data-runbook-step-id="${stepId}"]`) ?? null
	if (element === null) {
		return
	}
	element.scrollIntoView({ behavior: 'smooth', block: 'center' })
	if (focus) {
		element.focus({ preventScroll: true })
	}
}

/**
 * Reveal a step from the page (next-action shortcut).
 *
 * @param stepId Run step identifier.
 */
function focusStep(stepId: number): void {
	// An explicit jump clears any pending deep-link intent for the current run.
	navigation.value = requestFocus(navigation.value, props.runId, null)
	void revealStep(stepId, true)
}

/**
 * Reconcile navigation with the loaded run and apply a pending deep-link intent
 * exactly once. The detail must belong to the current run, so an intent for run
 * B is never consumed against the still-loaded detail of run A. Later reloads of
 * the same run keep the user's selection.
 */
async function applyStepFocus(): Promise<void> {
	if (detail.value === null || loadedRunId.value !== props.runId) {
		return
	}

	const result = applyLoad(navigation.value, detail.value)
	navigation.value = result.state
	if (result.focusStepId !== null) {
		await revealStep(result.focusStepId, true)
	}
}

/**
 * Run a mutation and reload the detail afterwards.
 *
 * @param action Async action performing the API call.
 */
async function mutate(action: () => Promise<unknown>): Promise<void> {
	busy.value = true
	error.value = null
	try {
		await action()
		await load()
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		busy.value = false
	}
}

/**
 * Start a pending step.
 *
 * @param stepId Run step identifier.
 */
function startStep(stepId: number): void {
	void mutate(() => api.startStep(stepId))
}

/**
 * Store a step response.
 *
 * @param stepId Run step identifier.
 * @param response Response value.
 */
function saveStep(stepId: number, response: StepResponse): void {
	void mutate(() => api.updateStep(stepId, response))
}

/**
 * Complete a step.
 *
 * @param stepId Run step identifier.
 * @param response Response value.
 */
function completeStep(stepId: number, response: StepResponse): void {
	void mutate(() => api.completeStep(stepId, response))
}

/**
 * Skip a step with a reason.
 *
 * @param stepId Run step identifier.
 * @param reason Skip reason.
 */
function skipStep(stepId: number, reason: string): void {
	void mutate(() => api.skipStep(stepId, reason))
}

/**
 * Reopen a step.
 *
 * @param stepId Run step identifier.
 */
function reopenStep(stepId: number): void {
	void mutate(() => api.reopenStep(stepId))
}

/**
 * Update a step assignment.
 *
 * @param stepId Run step identifier.
 * @param payload Assignment fields.
 */
function assignStep(stepId: number, payload: StepAssignmentPayload): void {
	void mutate(() => api.assignStep(stepId, payload))
}

/**
 * Whether the current user may execute a step.
 *
 * @param stepId Run step identifier.
 */
function canExecuteStep(stepId: number): boolean {
	return detail.value?.permissions.executableStepIds.includes(stepId) ?? false
}

/**
 * Evidence attachments belonging to a step, using the shared helper so the
 * RunStepCard props are regression-tested.
 *
 * @param stepId Run step identifier.
 */
function attachmentsForStep(stepId: number): RunAttachment[] {
	return attachmentsForStepHelper(attachments.value, stepId)
}

/**
 * Upload evidence to a step.
 *
 * @param stepId Run step identifier.
 * @param file Selected file.
 */
function uploadEvidence(stepId: number, file: File): void {
	void mutate(() => api.uploadAttachment(stepId, file))
}

/**
 * Attach a copy of an existing Files item to a step (issue #53).
 *
 * @param stepId Run step identifier.
 * @param sourcePath Advisory source path selected by the user.
 */
function copyEvidence(stepId: number, sourcePath: string): void {
	void mutate(() => api.copyAttachment(stepId, buildCopyEvidencePayload(sourcePath)))
}

/**
 * Delete an evidence attachment.
 *
 * @param id Attachment identifier.
 */
function deleteEvidence(id: number): void {
	void mutate(() => api.deleteAttachment(id))
}

/**
 * Complete the run.
 */
function completeRun(): void {
	void mutate(() => api.completeRun(props.runId))
}

/**
 * Confirm cancelling the run.
 */
function confirmCancel(): void {
	showCancel.value = false
	void mutate(() => api.cancelRun(props.runId))
}

/**
 * Reopen a completed run.
 */
function reopenRun(): void {
	void mutate(() => api.reopenRun(props.runId))
}

/**
 * Confirm and permanently delete the run, then close the view.
 */
function confirmDeleteRun(): void {
	showDelete.value = false
	void (async () => {
		busy.value = true
		error.value = null
		try {
			await api.deleteRun(props.runId)
			emit('close')
		} catch (caught) {
			error.value = apiErrorMessage(caught)
		} finally {
			busy.value = false
		}
	})()
}

/**
 * Format a Unix timestamp for display.
 *
 * @param timestamp Unix timestamp in seconds.
 */
function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString()
}
</script>

<template>
	<section ref="root" class="runbook-exec">
		<header class="runbook-exec__header">
			<NcButton @click="emit('close')">
				{{ t('runbook', 'Back to runs') }}
			</NcButton>

			<div v-if="detail !== null" class="runbook-exec__heading">
				<h2 class="runbook-exec__title">
					{{ detail.run.title }}
				</h2>
				<div class="runbook-exec__context">
					<RunStatusBadge :status="detail.run.status" />
					<span>{{ t('runbook', 'Template v{version}', { version: detail.run.templateVersion }) }}</span>
					<span v-if="detail.permissions.role !== null && detail.permissions.role !== 'OWNER'">
						{{ t('runbook', 'Your role: {role}', { role: detail.permissions.role }) }}
					</span>
					<span v-if="detail.run.dueAt !== null">
						{{ t('runbook', 'Due {date}', { date: formatDate(detail.run.dueAt) }) }}
					</span>
				</div>
				<p v-if="detail.run.description" class="runbook-exec__description">
					{{ detail.run.description }}
				</p>
			</div>
		</header>

		<div v-if="loading" class="runbook-exec__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="detail === null"
			:name="t('runbook', 'Run not found')"
			:description="error ?? t('runbook', 'The run could not be loaded.')" />

		<template v-else>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="!runActive" type="info">
				{{ t('runbook', 'This run is read-only. Reopen it to continue execution.') }}
			</NcNoteCard>
			<NcNoteCard v-if="detail.evidenceDegraded" type="warning">
				{{ degradedEvidenceNotice(t, attachments, detail.managedFolderState) }}
			</NcNoteCard>

			<div v-if="progressDisplay" class="runbook-exec__progress">
				<div v-if="progressDisplay.hasWork" class="runbook-exec__progress-bar">
					<NcProgressBar :value="progressDisplay.percentage" />
					<span class="runbook-exec__progress-summary">
						{{ t('runbook', 'Progress: {percent}%', { percent: progressDisplay.percentage }) }}
						—
						{{ t('runbook', '{resolved} of {total} steps resolved', { resolved: progressDisplay.resolved, total: progressDisplay.totalSteps }) }}
					</span>
				</div>
				<p v-else class="runbook-exec__no-work">
					{{ t('runbook', 'This run has no steps to complete.') }}
				</p>

				<ul class="runbook-exec__counts">
					<li>{{ t('runbook', 'Sections: {count}', { count: progressDisplay.totalSections }) }}</li>
					<li>{{ t('runbook', 'Total steps: {count}', { count: progressDisplay.totalSteps }) }}</li>
					<li>{{ t('runbook', 'Completed: {count}', { count: progressDisplay.completed }) }}</li>
					<li>{{ t('runbook', 'Skipped by user: {count}', { count: progressDisplay.skippedByUser }) }}</li>
					<li>{{ t('runbook', 'Outside the current path: {count}', { count: progressDisplay.outsidePath }) }}</li>
					<li>{{ t('runbook', 'Pending: {count}', { count: progressDisplay.pending }) }}</li>
					<li v-if="progressDisplay.blocked > 0">
						{{ t('runbook', 'Blocked: {count}', { count: progressDisplay.blocked }) }}
					</li>
				</ul>
			</div>

			<div v-if="singleHint" class="runbook-exec__next">
				<span>{{ t('runbook', 'Next: {step}', { step: singleHint.step.title }) }}</span>
				<NcButton @click="focusStep(singleHint.step.id)">
					{{ t('runbook', 'Go to step') }}
				</NcButton>
			</div>
			<div v-else-if="optionsHint" class="runbook-exec__next">
				<span v-if="optionsHint.startable > 0">
					{{ t('runbook', '{count} steps can be started', { count: optionsHint.startable }) }}
				</span>
				<span v-if="optionsHint.continuable > 0">
					{{ t('runbook', '{count} steps are in progress', { count: optionsHint.continuable }) }}
				</span>
			</div>
			<div v-else-if="showReadyHint" class="runbook-exec__next">
				<span>{{ t('runbook', 'All required steps are resolved. You can complete the run.') }}</span>
			</div>
			<div v-else-if="showWaitingHint" class="runbook-exec__next">
				<span>{{ t('runbook', 'Waiting for other steps to become available.') }}</span>
			</div>

			<div class="runbook-exec__actions">
				<div class="runbook-exec__actions-primary">
					<NcButton
						v-if="detail.permissions.canModify"
						variant="primary"
						:disabled="busy || !detail.progress.canComplete"
						@click="completeRun">
						{{ t('runbook', 'Complete run') }}
					</NcButton>
					<NcButton v-if="detail.permissions.canReopen && runReopenEnabled" :disabled="busy" @click="reopenRun">
						{{ t('runbook', 'Reopen run') }}
					</NcButton>
				</div>
				<div class="runbook-exec__actions-destructive">
					<NcButton v-if="detail.permissions.canCancel" :disabled="busy" @click="showCancel = true">
						{{ t('runbook', 'Cancel run') }}
					</NcButton>
					<NcButton
						v-if="detail.permissions.canDelete"
						variant="error"
						:disabled="busy"
						@click="showDelete = true">
						{{ t('runbook', 'Delete run') }}
					</NcButton>
				</div>
			</div>

			<div v-if="detail.sections.length === 0" class="runbook-exec__empty">
				<NcEmptyContent
					:name="t('runbook', 'This run has no sections.')"
					:description="t('runbook', 'The template contains no sections to execute.')" />
			</div>

			<div v-else class="runbook-exec__body">
				<SectionNavigator
					:sections="detail.sections"
					:selectedId="selectedSectionId"
					@select="selectSection" />

				<div class="runbook-exec__content">
					<article
						v-if="selectedEntry"
						ref="sectionContainer"
						class="runbook-exec__section"
						tabindex="-1">
						<div class="runbook-exec__section-header">
							<h3 class="runbook-exec__section-title">
								{{ selectedEntry.section.title }}
							</h3>
							<NcButton
								v-if="selectedEntry.steps.length > 0"
								variant="tertiary"
								:aria-expanded="!isCollapsed(selectedEntry)"
								:aria-label="isCollapsed(selectedEntry) ? t('runbook', 'Expand section {title}', { title: selectedEntry.section.title }) : t('runbook', 'Collapse section {title}', { title: selectedEntry.section.title })"
								@click="toggleSection(selectedEntry)">
								{{ isCollapsed(selectedEntry) ? t('runbook', 'Expand') : t('runbook', 'Collapse') }}
							</NcButton>
						</div>

						<SectionStatus
							:state="selectedEntry.state"
							:steps="selectedEntry.steps"
							:reasons="selectedEntry.reason"
							:blockedBy="selectedEntry.blockedBy" />

						<p v-if="selectedEntry.section.description" class="runbook-exec__description">
							{{ selectedEntry.section.description }}
						</p>

						<div
							v-if="selectedEntry.section.notes !== '' || editingNotesId === selectedEntry.section.id || (detail.permissions.canManage && runActive)"
							class="runbook-exec__section-notes">
							<NcTextArea
								v-if="editingNotesId === selectedEntry.section.id"
								v-model="notesDraft"
								:label="t('runbook', 'Section notes')" />
							<p v-else-if="selectedEntry.section.notes !== ''" class="runbook-exec__notes-text">
								{{ selectedEntry.section.notes }}
							</p>
							<div v-if="detail.permissions.canManage && runActive" class="runbook-exec__notes-actions">
								<template v-if="editingNotesId === selectedEntry.section.id">
									<NcButton variant="primary" :disabled="busy" @click="saveNotes(selectedEntry.section.id)">
										{{ t('runbook', 'Save notes') }}
									</NcButton>
									<NcButton @click="editingNotesId = null">
										{{ t('runbook', 'Cancel') }}
									</NcButton>
								</template>
								<NcButton v-else @click="startNotes(selectedEntry)">
									{{ t('runbook', 'Edit notes') }}
								</NcButton>
							</div>
						</div>

						<div v-if="canReturnSection(selectedEntry)" class="runbook-exec__section-return">
							<NcButton v-if="sectionReturnId !== selectedEntry.section.id" @click="startSectionReturn(selectedEntry.section.id)">
								{{ t('runbook', 'Reopen section') }}
							</NcButton>
							<template v-else>
								<NcTextArea v-model="sectionReturnReason" :label="t('runbook', 'Return reason')" />
								<div class="runbook-exec__notes-actions">
									<NcButton
										:disabled="busy || sectionReturnReason.trim() === ''"
										variant="primary"
										@click="submitSectionReturn(selectedEntry.section.id)">
										{{ t('runbook', 'Confirm return') }}
									</NcButton>
									<NcButton @click="sectionReturnId = null">
										{{ t('runbook', 'Cancel') }}
									</NcButton>
								</div>
							</template>
						</div>

						<div v-show="!isCollapsed(selectedEntry)">
							<div
								v-for="step in selectedEntry.steps"
								:key="step.id"
								class="runbook-exec__step-anchor"
								:class="{ 'runbook-exec__step-anchor--highlighted': highlightedStepId === step.id }"
								:data-runbook-step-id="step.id"
								tabindex="-1">
								<RunStepCard
									:step="step"
									:runActive="runActive"
									:canExecute="canExecuteStep(step.id)"
									:canManageAssignments="detail.permissions.canManageAssignments"
									:attachments="attachmentsForStep(step.id)"
									:canUploadEvidence="runActive && canExecuteStep(step.id)"
									:uid="detail.permissions.uid"
									:isOwner="detail.permissions.canManage"
									:canReopen="stepReopenEnabled"
									:requireSkipReason="requireSkipReason"
									@start="startStep(step.id)"
									@save="(response) => saveStep(step.id, response)"
									@complete="(response) => completeStep(step.id, response)"
									@skip="(reason) => skipStep(step.id, reason)"
									@reopen="reopenStep(step.id)"
									@return="(reason) => returnStep(step.id, reason)"
									@assign="(payload) => assignStep(step.id, payload)"
									@uploadEvidence="(file) => uploadEvidence(step.id, file)"
									@copyEvidence="(path) => copyEvidence(step.id, path)"
									@deleteEvidence="deleteEvidence" />
							</div>
						</div>
					</article>
				</div>
			</div>

			<section class="runbook-exec__supporting">
				<h3 class="runbook-exec__supporting-title">
					{{ t('runbook', 'Supporting information') }}
				</h3>

				<details class="runbook-exec__panel">
					<summary>{{ t('runbook', 'Evidence') }}</summary>
					<RunEvidencePanel
						:attachments="attachments"
						:stepTitles="stepTitles"
						:uid="detail.permissions.uid"
						:isOwner="detail.permissions.canManage"
						:active="runActive"
						@remove="deleteEvidence" />
				</details>

				<details class="runbook-exec__panel">
					<summary>{{ t('runbook', 'Comments') }}</summary>
					<RunCommentsPanel
						:runId="runId"
						:uid="detail.permissions.uid"
						:canComment="detail.permissions.canComment && commentsEnabled"
						:active="runActive"
						:steps="commentSteps" />
				</details>

				<details class="runbook-exec__panel">
					<summary>{{ t('runbook', 'Activity') }}</summary>
					<RunActivityPanel :runId="runId" :stepTitles="stepTitles" />
				</details>

				<details v-if="detail.permissions.canManage" class="runbook-exec__panel">
					<summary>{{ t('runbook', 'Participants') }}</summary>
					<RunAclEditor
						:runId="runId"
						:owner="detail.run.owner"
						:readOnly="!runActive" />
				</details>
			</section>

			<p v-if="detail.run.completedAt !== null" class="runbook-exec__timestamps">
				{{ t('runbook', 'Completed on {date}', { date: formatDate(detail.run.completedAt) }) }}
			</p>
			<p v-if="detail.run.cancelledAt !== null" class="runbook-exec__timestamps">
				{{ t('runbook', 'Cancelled on {date}', { date: formatDate(detail.run.cancelledAt) }) }}
			</p>
		</template>

		<ConfirmDialog
			v-if="showCancel"
			:name="t('runbook', 'Cancel run')"
			:message="t('runbook', 'Cancelling a run stops execution and makes it read-only. This cannot be undone in this version.')"
			:confirmLabel="t('runbook', 'Cancel run')"
			:busy="busy"
			@confirm="confirmCancel"
			@cancel="showCancel = false" />

		<ConfirmDialog
			v-if="showDelete"
			:name="t('runbook', 'Delete run')"
			:message="t('runbook', 'This permanently deletes the run, its activity, comments and evidence. This cannot be undone.')"
			:confirmLabel="t('runbook', 'Delete run')"
			:busy="busy"
			@confirm="confirmDeleteRun"
			@cancel="showDelete = false" />
	</section>
</template>

<style scoped>
.runbook-exec {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-3);
	max-width: var(--runbook-content-max);
	margin-inline: auto;
	padding: var(--runbook-space-4);
}

.runbook-exec__header {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
}

.runbook-exec__title {
	margin: 0;
	overflow-wrap: anywhere;
}

.runbook-exec__context {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--runbook-space-2) var(--runbook-space-3);
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
}

.runbook-exec__description {
	margin: 0;
	overflow-wrap: anywhere;
}

.runbook-exec__center {
	display: flex;
	justify-content: center;
	padding: var(--runbook-space-5);
}

.runbook-exec__progress {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	padding: var(--runbook-space-3);
	border: 1px solid var(--runbook-border);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface-subtle);
}

.runbook-exec__progress-bar {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
}

.runbook-exec__progress-summary,
.runbook-exec__no-work {
	margin: 0;
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
}

.runbook-exec__counts {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-1) var(--runbook-space-3);
	margin: 0;
	padding: 0;
	list-style: none;
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
}

.runbook-exec__next {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--runbook-space-2);
	min-height: var(--runbook-target-min);
}

.runbook-exec__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: var(--runbook-space-2);
}

.runbook-exec__actions-primary,
.runbook-exec__actions-destructive {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-2);
}

.runbook-exec__actions-destructive {
	padding-inline-start: var(--runbook-space-3);
	border-inline-start: 1px solid var(--runbook-border);
}

.runbook-exec__body {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-3);
}

.runbook-exec__content {
	min-width: 0;
}

.runbook-exec__section {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	padding: var(--runbook-space-3);
	border: 1px solid var(--runbook-border);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface);
}

.runbook-exec__section-header {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--runbook-space-2);
}

.runbook-exec__section:focus-visible {
	outline: var(--runbook-focus-ring);
	outline-offset: 2px;
}

.runbook-exec__section-title {
	margin: 0;
	overflow-wrap: anywhere;
}

.runbook-exec__section-notes {
	border-inline-start: 3px solid var(--runbook-border-strong);
	background-color: var(--runbook-surface-subtle);
	border-radius: var(--runbook-radius);
	padding: var(--runbook-space-2);
}

.runbook-exec__notes-text {
	margin: 0;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}

.runbook-exec__notes-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-2);
	margin-top: var(--runbook-space-2);
}

.runbook-exec__section-return {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
}

.runbook-exec__step-anchor {
	scroll-margin-top: var(--runbook-space-5);
	border-radius: var(--runbook-radius);
}

.runbook-exec__step-anchor:focus-visible {
	outline: var(--runbook-focus-ring);
	outline-offset: 2px;
}

.runbook-exec__step-anchor--highlighted {
	box-shadow: 0 0 0 2px var(--runbook-accent-info);
}

.runbook-exec__supporting {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	margin-top: var(--runbook-space-3);
}

.runbook-exec__supporting-title {
	margin: 0;
}

.runbook-exec__panel {
	border: 1px solid var(--runbook-border);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface);
}

.runbook-exec__panel > summary {
	padding: var(--runbook-space-2) var(--runbook-space-3);
	min-height: var(--runbook-target-min);
	display: flex;
	align-items: center;
	cursor: pointer;
	font-weight: 600;
}

.runbook-exec__panel[open] > summary {
	border-bottom: 1px solid var(--runbook-border);
}

.runbook-exec__timestamps {
	margin: 0;
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
}

@media (min-width: 900px) {
	.runbook-exec__body {
		flex-direction: row;
		align-items: flex-start;
	}

	.runbook-exec__body > :first-child {
		flex: 0 0 var(--runbook-nav-width, 260px);
		position: sticky;
		top: var(--runbook-space-4);
	}

	.runbook-exec__content {
		flex: 1 1 auto;
	}
}
</style>
