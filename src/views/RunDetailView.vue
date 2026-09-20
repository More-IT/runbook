<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AppFeatures } from '../models/adminSettings.ts'
import type { RunAttachment, RunDetail, StepAssignmentPayload, StepResponse } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
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

const props = defineProps<{
	runId: number
}>()

const emit = defineEmits<{
	close: []
}>()

const detail = ref<RunDetail | null>(null)
const attachments = ref<RunAttachment[]>([])
const features = ref<AppFeatures | null>(null)
const loading = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)
const showCancel = ref(false)

const runActive = computed<boolean>(() => detail.value?.run.status === 'ACTIVE')
const steps = computed(() => detail.value?.sections.flatMap((entry) => entry.steps) ?? [])
const stepTitles = computed<Record<number, string>>(() => Object.fromEntries(steps.value.map((step) => [step.id, step.title])))
const commentSteps = computed(() => steps.value.map((step) => ({ id: step.id, title: step.title })))

const commentsEnabled = computed<boolean>(() => features.value?.commentsEnabled ?? true)
const stepReopenEnabled = computed<boolean>(() => features.value?.stepReopenEnabled ?? true)
const runReopenEnabled = computed<boolean>(() => features.value?.runReopenEnabled ?? true)
const requireSkipReason = computed<boolean>(() => features.value?.requireSkipReason ?? true)

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
}

onMounted(load)
watch(() => props.runId, load)

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
 * Format a Unix timestamp for display.
 *
 * @param timestamp Unix timestamp in seconds.
 */
function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString()
}
</script>

<template>
	<section class="runbook-run">
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
				<span>
					{{ t('runbook', 'Progress: {percent}%', { percent: detail.progress.percentage }) }}
					({{ t('runbook', '{resolved} of {total} steps resolved', { resolved: detail.progress.completed + detail.progress.skipped, total: detail.progress.total }) }})
				</span>
				<span v-if="detail.progress.skipped > 0">
					{{ t('runbook', '{count} skipped', { count: detail.progress.skipped }) }}
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
			</div>

			<RunAclEditor
				v-if="detail.permissions.canManage"
				:runId="runId"
				:owner="detail.run.owner"
				:readOnly="!runActive" />

			<div class="runbook-run__sections">
				<div v-for="entry in detail.sections" :key="entry.section.id" class="runbook-run__section">
					<h3>{{ entry.section.title }}</h3>
					<p v-if="entry.section.description" class="runbook-run__description">
						{{ entry.section.description }}
					</p>
					<RunStepCard
						v-for="step in entry.steps"
						:key="step.id"
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
						@assign="(payload) => assignStep(step.id, payload)"
						@uploadEvidence="(file) => uploadEvidence(step.id, file)"
						@deleteEvidence="deleteEvidence" />
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

			<RunActivityPanel :runId="runId" />

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

.runbook-run__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.runbook-run__section {
	margin-bottom: 16px;
}
</style>
