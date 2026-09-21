<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AppFeatures } from '../models/adminSettings.ts'
import type { RunAttachment, RunDetail, RunSectionWithSteps, StepAssignmentPayload, StepResponse } from '../models/run.ts'

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
import { getFeatures } from '../services/adminSettings.ts'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'
import { inapplicableReasonText, sectionStateLabel, STATUS_REASON_SEPARATOR } from '../utils/sectionReason.ts'

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

const runActive = computed<boolean>(() => detail.value?.run.status === 'ACTIVE')
const steps = computed(() => detail.value?.sections.flatMap((entry) => entry.steps) ?? [])
const stepTitles = computed<Record<number, string>>(() => Object.fromEntries(steps.value.map((step) => [step.id, step.title])))
const commentSteps = computed(() => steps.value.map((step) => ({ id: step.id, title: step.title })))

const commentsEnabled = computed<boolean>(() => features.value?.commentsEnabled ?? true)
const stepReopenEnabled = computed<boolean>(() => features.value?.stepReopenEnabled ?? true)
const runReopenEnabled = computed<boolean>(() => features.value?.runReopenEnabled ?? true)
const requireSkipReason = computed<boolean>(() => features.value?.requireSkipReason ?? true)

/**
 * Per-section collapse override. Only sections that are fully resolved
 * (every step completed or skipped) default to collapsed; unresolved sections
 * stay expanded. An explicit user toggle is remembered for the session.
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
 * Expand the section that contains the given step, if any.
 *
 * @param stepId Run step identifier.
 */
function expandSectionForStep(stepId: number): void {
	const entry = detail.value?.sections.find((candidate) => candidate.steps.some((step) => step.id === stepId))
	if (entry !== undefined && isCollapsed(entry)) {
		sectionOverrides.value = { ...sectionOverrides.value, [entry.section.id]: false }
	}
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
 *
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		const [run, files, flags] = await Promise.all([
			api.getRun(props.runId),
			api.listAttachments(props.runId),
			getFeatures(),
		])
		detail.value = run
		attachments.value = files
		features.value = flags
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}

	// Focus after the loading state is cleared so the step anchors are rendered.
	await applyStepFocus()
}

onMounted(load)
watch(() => props.runId, load)
watch(() => props.focusStepId, () => {
	void applyStepFocus()
})

/**
 * Scroll to and highlight the step referenced by the current deep link.
 *
 * The step only exists once the run detail has been loaded, so this is called
 * after every load as well as when the requested step changes.
 */
async function applyStepFocus(): Promise<void> {
	const target = props.focusStepId ?? null
	if (target === null || detail.value === null || !steps.value.some((step) => step.id === target)) {
		highlightedStepId.value = null
		return
	}

	highlightedStepId.value = target
	expandSectionForStep(target)
	await nextTick()

	const container = root.value
	const element = container?.querySelector<HTMLElement>(`[data-runbook-step-id="${target}"]`) ?? null
	if (element === null) {
		return
	}

	element.scrollIntoView({ behavior: 'smooth', block: 'center' })
	element.focus({ preventScroll: true })
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
 * Evidence attachments belonging to a step.
 *
 * @param stepId Run step identifier.
 */
function attachmentsForStep(stepId: number): RunAttachment[] {
	return attachments.value.filter((attachment) => attachment.stepId === stepId)
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
 * Delete an evidence attachment.
 *
 * @param id Attachment identifier.
 */
function deleteEvidence(id: number): void {
	void mutate(() => api.deleteAttachment(id))
}

/**
 *
 */
function completeRun(): void {
	void mutate(() => api.completeRun(props.runId))
}

/**
 *
 */
function confirmCancel(): void {
	showCancel.value = false
	void mutate(() => api.cancelRun(props.runId))
}

/**
 *
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
	<section ref="root" class="runbook-run">
		<header class="runbook-run__header">
			<NcButton @click="emit('close')">
				{{ t('runbook', 'Back to runs') }}
			</NcButton>
		</header>

		<div v-if="loading" class="runbook-run__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="detail === null"
			:name="t('runbook', 'Run not found')"
			:description="error ?? t('runbook', 'The run could not be loaded.')" />

		<template v-else>
			<div class="runbook-run__meta">
				<h2>{{ detail.run.title }}</h2>
				<RunStatusBadge :status="detail.run.status" />
				<span class="runbook-run__version">{{ t('runbook', 'Template v{version}', { version: detail.run.templateVersion }) }}</span>
				<span v-if="detail.permissions.role !== null && detail.permissions.role !== 'OWNER'" class="runbook-run__version">
					{{ t('runbook', 'Your role: {role}', { role: detail.permissions.role }) }}
				</span>
				<span v-if="detail.run.dueAt !== null" class="runbook-run__version">
					{{ t('runbook', 'Due {date}', { date: formatDate(detail.run.dueAt) }) }}
				</span>
			</div>

			<p v-if="detail.run.description" class="runbook-run__description">
				{{ detail.run.description }}
			</p>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="!runActive" type="info">
				{{ t('runbook', 'This run is read-only. Reopen it to continue execution.') }}
			</NcNoteCard>

			<div class="runbook-run__progress">
				<NcProgressBar :value="detail.progress.percentage" />
				<span class="runbook-run__progress-summary">
					{{ t('runbook', 'Progress: {percent}%', { percent: detail.progress.percentage }) }}
					({{ t('runbook', '{resolved} of {total} steps resolved', { resolved: detail.progress.completed + detail.progress.skipped, total: detail.progress.total }) }})
				</span>
				<span class="runbook-run__progress-breakdown">
					<span class="runbook-run__progress-item runbook-run__progress-item--completed">
						{{ t('runbook', 'Completed: {count}', { count: detail.progress.completed }) }}
					</span>
					<span class="runbook-run__progress-item runbook-run__progress-item--skipped">
						{{ t('runbook', 'Skipped: {count}', { count: detail.progress.skipped }) }}
					</span>
					<span class="runbook-run__progress-item runbook-run__progress-item--pending">
						{{ t('runbook', 'Pending: {count}', { count: detail.progress.pending }) }}
					</span>
				</span>
			</div>

			<div class="runbook-run__actions">
				<NcButton
					v-if="detail.permissions.canModify"
					variant="primary"
					:disabled="busy || !detail.progress.canComplete"
					@click="completeRun">
					{{ t('runbook', 'Complete run') }}
				</NcButton>
				<NcButton v-if="detail.permissions.canCancel" :disabled="busy" @click="showCancel = true">
					{{ t('runbook', 'Cancel run') }}
				</NcButton>
				<NcButton v-if="detail.permissions.canReopen && runReopenEnabled" :disabled="busy" @click="reopenRun">
					{{ t('runbook', 'Reopen run') }}
				</NcButton>
				<NcButton
					v-if="detail.permissions.canDelete"
					variant="error"
					:disabled="busy"
					@click="showDelete = true">
					{{ t('runbook', 'Delete run') }}
				</NcButton>
			</div>

			<RunAclEditor
				v-if="detail.permissions.canManage"
				:runId="runId"
				:owner="detail.run.owner"
				:readOnly="!runActive" />

			<div class="runbook-run__sections">
				<div v-for="entry in detail.sections" :key="entry.section.id" class="runbook-run__section">
					<div class="runbook-run__section-header">
						<h3>{{ entry.section.title }}</h3>
						<NcButton
							v-if="entry.steps.length > 0"
							variant="tertiary"
							:aria-expanded="!isCollapsed(entry)"
							:aria-label="isCollapsed(entry) ? t('runbook', 'Expand section {title}', { title: entry.section.title }) : t('runbook', 'Collapse section {title}', { title: entry.section.title })"
							@click="toggleSection(entry)">
							{{ isCollapsed(entry) ? t('runbook', 'Expand') : t('runbook', 'Collapse') }}
						</NcButton>
					</div>

					<div
						v-if="entry.state === 'blocked' || entry.state === 'inapplicable' || entry.state === 'active'"
						class="runbook-run__section-flow">
						<span class="runbook-run__section-state">{{ sectionStateLabel(t, entry.state) }}</span>
						<template v-if="entry.blockedBy.length > 0">
							<span class="runbook-run__section-separator">{{ STATUS_REASON_SEPARATOR }}</span>
							<span class="runbook-run__section-blocked">
								{{ t('runbook', 'Waiting for: {sections}', { sections: entry.blockedBy.join(', ') }, { escape: false, sanitize: false }) }}
							</span>
						</template>
						<template v-if="entry.state === 'inapplicable' && inapplicableReasonText(t, entry) !== ''">
							<span class="runbook-run__section-separator">{{ STATUS_REASON_SEPARATOR }}</span>
							<span class="runbook-run__section-blocked">
								{{ t('runbook', 'Reason:') }} {{ inapplicableReasonText(t, entry) }}
							</span>
						</template>
					</div>

					<p v-if="entry.section.description" class="runbook-run__description">
						{{ entry.section.description }}
					</p>

					<div
						v-if="entry.section.notes !== '' || editingNotesId === entry.section.id || (detail.permissions.canManage && runActive)"
						class="runbook-run__section-notes">
						<NcTextArea
							v-if="editingNotesId === entry.section.id"
							v-model="notesDraft"
							:label="t('runbook', 'Section notes')" />
						<p v-else-if="entry.section.notes !== ''" class="runbook-run__notes-text">
							{{ entry.section.notes }}
						</p>
						<div v-if="detail.permissions.canManage && runActive" class="runbook-run__notes-actions">
							<template v-if="editingNotesId === entry.section.id">
								<NcButton variant="primary" :disabled="busy" @click="saveNotes(entry.section.id)">
									{{ t('runbook', 'Save notes') }}
								</NcButton>
								<NcButton @click="editingNotesId = null">
									{{ t('runbook', 'Cancel') }}
								</NcButton>
							</template>
							<NcButton v-else @click="startNotes(entry)">
								{{ t('runbook', 'Edit notes') }}
							</NcButton>
						</div>
					</div>

					<div v-if="canReturnSection(entry)" class="runbook-run__section-return">
						<NcButton v-if="sectionReturnId !== entry.section.id" @click="startSectionReturn(entry.section.id)">
							{{ t('runbook', 'Reopen section') }}
						</NcButton>
						<template v-else>
							<NcTextArea v-model="sectionReturnReason" :label="t('runbook', 'Return reason')" />
							<div class="runbook-run__notes-actions">
								<NcButton
									:disabled="busy || sectionReturnReason.trim() === ''"
									variant="primary"
									@click="submitSectionReturn(entry.section.id)">
									{{ t('runbook', 'Confirm return') }}
								</NcButton>
								<NcButton @click="sectionReturnId = null">
									{{ t('runbook', 'Cancel') }}
								</NcButton>
							</div>
						</template>
					</div>

					<div v-show="!isCollapsed(entry)">
						<div
							v-for="step in entry.steps"
							:key="step.id"
							class="runbook-run__step-anchor"
							:class="{ 'runbook-run__step-anchor--highlighted': highlightedStepId === step.id }"
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
								@deleteEvidence="deleteEvidence" />
						</div>
					</div>
				</div>
			</div>

			<RunEvidencePanel
				:attachments="attachments"
				:stepTitles="stepTitles"
				:uid="detail.permissions.uid"
				:isOwner="detail.permissions.canManage"
				:active="runActive"
				@remove="deleteEvidence" />

			<RunCommentsPanel
				:runId="runId"
				:uid="detail.permissions.uid"
				:canComment="detail.permissions.canComment && commentsEnabled"
				:active="runActive"
				:steps="commentSteps" />

			<RunActivityPanel :runId="runId" :stepTitles="stepTitles" />

			<p v-if="detail.run.completedAt !== null" class="runbook-run__timestamps">
				{{ t('runbook', 'Completed on {date}', { date: formatDate(detail.run.completedAt) }) }}
			</p>
			<p v-if="detail.run.cancelledAt !== null" class="runbook-run__timestamps">
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
.runbook-run {
	padding: 16px;
}

.runbook-run__header {
	margin-bottom: 16px;
}

.runbook-run__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-run__meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
}

.runbook-run__version,
.runbook-run__timestamps {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-run__description {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-run__progress {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	margin: 16px 0;
}

.runbook-run__progress-summary {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-run__progress-breakdown {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.runbook-run__progress-item {
	font-size: 0.85em;
	font-weight: bold;
}

.runbook-run__progress-item--completed {
	color: var(--color-success-element, #099f05);
}

.runbook-run__progress-item--skipped {
	color: var(--color-warning-element, #bf7900);
}

.runbook-run__progress-item--pending {
	color: var(--color-info-element, #0077c7);
}

.runbook-run__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.runbook-run__section {
	margin-bottom: 16px;
}

.runbook-run__section-header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.runbook-run__section-flow {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
	margin: 4px 0;
	font-size: 0.85em;
}

.runbook-run__section-state {
	font-weight: bold;
	color: var(--color-text-maxcontrast, #555);
}

.runbook-run__section-separator {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-run__section-blocked {
	color: var(--color-warning-element, #bf7900);
}

.runbook-run__section-return {
	margin: 8px 0;
}

.runbook-run__section-notes {
	border-inline-start: 3px solid var(--color-border-dark, #ccc);
	background-color: var(--color-background-hover, #f5f5f5);
	border-radius: var(--border-radius, 4px);
	padding: 8px;
	margin: 8px 0;
}

.runbook-run__notes-text {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	margin: 0 0 4px;
}

.runbook-run__notes-actions {
	display: flex;
	gap: 8px;
}

.runbook-run__step-anchor {
	border-radius: var(--border-radius, 4px);
	outline: none;
}

.runbook-run__step-anchor--highlighted {
	outline: 3px solid var(--color-primary-element, #0082c9);
	outline-offset: 2px;
	background-color: var(--color-primary-light, #e6f0f8);
}
</style>
