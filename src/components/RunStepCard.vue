<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunAttachment, RunStep, RunStepStatus, StepAssignmentPayload, StepResponse } from '../models/run.ts'
import type { Principal, StepType } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { attachmentDownloadUrl } from '../services/runs.ts'
import { searchPrincipals } from '../services/templates.ts'

const props = defineProps<{
	step: RunStep
	runActive: boolean
	canExecute: boolean
	canManageAssignments: boolean
	attachments: RunAttachment[]
	canUploadEvidence: boolean
	uid: string
	isOwner: boolean
	canReopen: boolean
	requireSkipReason: boolean
}>()

const emit = defineEmits<{
	start: []
	save: [response: StepResponse]
	complete: [response: StepResponse]
	skip: [reason: string]
	reopen: []
	assign: [payload: StepAssignmentPayload]
	uploadEvidence: [file: File]
	deleteEvidence: [id: number]
}>()

const boolValue = ref(false)
const textValue = ref('')
const numberValue = ref('')
const selectValue = ref<string | null>(null)
const dateValue = ref('')
const userValue = ref<Principal | null>(null)
const userOptions = ref<Principal[]>([])
const userLoading = ref(false)
const skipOpen = ref(false)
const skipReason = ref('')
const evidenceFile = ref<File | null>(null)

const assignOpen = ref(false)
const assigneeValue = ref<Principal | null>(null)
const assigneeOptions = ref<Principal[]>([])
const assigneeLoading = ref(false)
const assigneeDueDate = ref('')

const selectOptions = computed<string[]>(() => {
	const options = props.step.config.options
	return Array.isArray(options) ? options.filter((option): option is string => typeof option === 'string') : []
})

/**
 * Configured unit of a NUMBER step, or an empty string when none is set.
 */
const unit = computed<string>(() => {
	const value = props.step.config.unit
	return typeof value === 'string' ? value.trim() : ''
})

/**
 * Label of the NUMBER response field, including the configured unit.
 */
const numberFieldLabel = computed<string>(() => unit.value === ''
	? t('runbook', 'Response')
	: t('runbook', 'Response ({unit})', { unit: unit.value }))

const isEditable = computed<boolean>(() => props.step.status === 'PENDING' || props.step.status === 'IN_PROGRESS')
const canComplete = computed<boolean>(() => props.step.type !== 'FILE' || !props.step.required)
const isOverdue = computed<boolean>(() => isEditable.value && props.step.dueAt !== null && props.step.dueAt < Date.now() / 1000)

/**
 * Status class used for the theme-safe visual treatment.
 *
 * A step that was reopened and is actionable again is highlighted separately
 * (orange) because "reopened" is not a persisted status on its own.
 */
const statusClass = computed<string>(() => {
	if (props.step.reopenedAt !== null && (props.step.status === 'PENDING' || props.step.status === 'IN_PROGRESS')) {
		return 'runbook-run-step--reopened'
	}
	switch (props.step.status) {
		case 'COMPLETED':
			return 'runbook-run-step--completed'
		case 'IN_PROGRESS':
			return 'runbook-run-step--in-progress'
		case 'SKIPPED':
			return 'runbook-run-step--skipped'
		default:
			return 'runbook-run-step--pending'
	}
})

/**
 * Human readable label for a step type.
 *
 * @param type Step type.
 */
function typeLabel(type: StepType): string {
	switch (type) {
		case 'CHECK':
			return t('runbook', 'Check')
		case 'CONFIRMATION':
			return t('runbook', 'Confirmation')
		case 'TEXT':
			return t('runbook', 'Text')
		case 'NUMBER':
			return t('runbook', 'Number')
		case 'SELECT':
			return t('runbook', 'Selection')
		case 'DATE':
			return t('runbook', 'Date')
		case 'USER':
			return t('runbook', 'User')
		case 'FILE':
			return t('runbook', 'File')
	}
}

/**
 * Human readable label for a step status.
 *
 * @param status Step status.
 */
function statusLabel(status: RunStepStatus): string {
	switch (status) {
		case 'IN_PROGRESS':
			return t('runbook', 'In progress')
		case 'COMPLETED':
			return t('runbook', 'Completed')
		case 'SKIPPED':
			return t('runbook', 'Skipped')
		default:
			return t('runbook', 'Pending')
	}
}

/**
 * Human readable representation of a stored response.
 *
 * Booleans are never rendered as "true"/"false": confirmation steps use
 * "Confirmed"/"Not confirmed" and check steps use "Yes"/"No". Number responses
 * include the configured unit when present.
 */
function responseText(): string {
	const response = props.step.response
	if (response === null) {
		return ''
	}
	if (typeof response === 'boolean') {
		if (props.step.type === 'CONFIRMATION') {
			return response ? t('runbook', 'Confirmed') : t('runbook', 'Not confirmed')
		}

		return response ? t('runbook', 'Yes') : t('runbook', 'No')
	}
	if (props.step.type === 'NUMBER' && unit.value !== '') {
		return `${response} ${unit.value}`
	}

	return String(response)
}

/**
 * Format a Unix timestamp for display.
 *
 * @param timestamp Unix timestamp in seconds.
 */
function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleDateString()
}

/**
 *
 */
function assigneeLabel(): string {
	if (props.step.assigneeType === null || props.step.assigneeId === null) {
		return t('runbook', 'Unassigned')
	}

	return `${props.step.assigneeType}: ${props.step.assigneeId}`
}

/**
 *
 */
function reset(): void {
	const response = props.step.response
	boolValue.value = typeof response === 'boolean' ? response : false
	textValue.value = typeof response === 'string' ? response : ''
	numberValue.value = typeof response === 'number' ? String(response) : ''
	selectValue.value = typeof response === 'string' ? response : null
	dateValue.value = props.step.type === 'DATE' && typeof response === 'string' ? response : ''
	userValue.value = props.step.type === 'USER' && typeof response === 'string' && response !== ''
		? { principalType: 'USER', principalId: response, displayName: response }
		: null
	skipOpen.value = false
	skipReason.value = ''
	assignOpen.value = false
	assigneeValue.value = props.step.assigneeType !== null && props.step.assigneeId !== null
		? { principalType: props.step.assigneeType, principalId: props.step.assigneeId, displayName: props.step.assigneeId }
		: null
	assigneeDueDate.value = props.step.dueAt !== null ? new Date(props.step.dueAt * 1000).toISOString().slice(0, 10) : ''
}

watch(() => props.step, reset, { deep: true })

reset()

/**
 * Current response value based on the step type.
 */
function currentResponse(): StepResponse {
	switch (props.step.type) {
		case 'CHECK':
		case 'CONFIRMATION':
			return boolValue.value
		case 'TEXT':
			return textValue.value
		case 'NUMBER':
			return numberValue.value.trim() === '' ? null : Number(numberValue.value)
		case 'SELECT':
			return selectValue.value
		case 'DATE':
			return dateValue.value.trim() === '' ? null : dateValue.value
		case 'USER':
			return userValue.value?.principalId ?? null
		default:
			return null
	}
}

/**
 * Search Nextcloud users for a USER step response.
 *
 * @param query Search term.
 */
async function searchUsers(query: string): Promise<void> {
	if (query.trim() === '') {
		userOptions.value = []
		return
	}

	userLoading.value = true
	try {
		const principals = await searchPrincipals(query)
		userOptions.value = principals.filter((principal) => principal.principalType === 'USER')
	} finally {
		userLoading.value = false
	}
}

/**
 * Search users and groups for a step assignee.
 *
 * @param query Search term.
 */
async function searchAssignees(query: string): Promise<void> {
	if (query.trim() === '') {
		assigneeOptions.value = []
		return
	}

	assigneeLoading.value = true
	try {
		assigneeOptions.value = await searchPrincipals(query)
	} finally {
		assigneeLoading.value = false
	}
}

/**
 * Human readable file size.
 *
 * @param size Size in bytes.
 */
function formatFileSize(size: number): string {
	if (size < 1024) {
		return `${size} B`
	}
	if (size < 1024 * 1024) {
		return `${(size / 1024).toFixed(1)} KiB`
	}

	return `${(size / (1024 * 1024)).toFixed(1)} MiB`
}

/**
 * Whether the current user may delete an attachment.
 *
 * @param attachment Attachment to check.
 */
function canDeleteEvidence(attachment: RunAttachment): boolean {
	return props.runActive && (props.isOwner || attachment.uploaderUid === props.uid)
}

/**
 * Capture the selected evidence file.
 *
 * @param event Change event of the file input.
 */
function onEvidenceSelected(event: Event): void {
	const input = event.target as HTMLInputElement
	evidenceFile.value = input.files !== null && input.files.length > 0 ? input.files[0] : null
}

/**
 * Upload the selected evidence file.
 */
function submitEvidence(): void {
	if (evidenceFile.value === null) {
		return
	}
	emit('uploadEvidence', evidenceFile.value)
	evidenceFile.value = null
}

/**
 *
 */
function confirmSkip(): void {
	if (props.requireSkipReason && skipReason.value.trim() === '') {
		return
	}
	emit('skip', skipReason.value.trim())
	skipOpen.value = false
}

/**
 *
 */
function submitAssignment(): void {
	const payload: StepAssignmentPayload = {
		assigneeType: assigneeValue.value?.principalType ?? null,
		assigneeId: assigneeValue.value?.principalId ?? null,
		dueAt: assigneeDueDate.value === '' ? null : Math.floor(new Date(`${assigneeDueDate.value}T00:00:00Z`).getTime() / 1000),
	}
	emit('assign', payload)
	assignOpen.value = false
}
</script>

<template>
	<div class="runbook-run-step" :class="statusClass">
		<div class="runbook-run-step__header">
			<span class="runbook-run-step__title">{{ step.title }}</span>
			<span class="runbook-run-step__type">{{ typeLabel(step.type) }}</span>
			<span v-if="unit !== ''" class="runbook-run-step__unit">{{ unit }}</span>
			<span v-if="step.required" class="runbook-run-step__required">{{ t('runbook', 'Required') }}</span>
			<span class="runbook-run-step__status">{{ statusLabel(step.status) }}</span>
			<span v-if="isOverdue" class="runbook-run-step__overdue">{{ t('runbook', 'Overdue') }}</span>
			<span class="runbook-run-step__assignee">{{ assigneeLabel() }}</span>
			<span v-if="step.dueAt !== null">{{ t('runbook', 'Due {date}', { date: formatDate(step.dueAt) }) }}</span>
		</div>

		<p v-if="step.description" class="runbook-run-step__description">
			{{ step.description }}
		</p>

		<div v-if="attachments.length > 0" class="runbook-run-step__evidence">
			<div v-for="attachment in attachments" :key="attachment.id" class="runbook-run-step__evidence-row">
				<span class="runbook-run-step__evidence-name">{{ attachment.filename }}</span>
				<span class="runbook-run-step__evidence-size">{{ formatFileSize(attachment.size) }}</span>
				<NcButton :href="attachmentDownloadUrl(attachment.id)">
					{{ t('runbook', 'Download') }}
				</NcButton>
				<NcButton v-if="canDeleteEvidence(attachment)" variant="error" @click="emit('deleteEvidence', attachment.id)">
					{{ t('runbook', 'Delete') }}
				</NcButton>
			</div>
		</div>

		<div v-if="canUploadEvidence && runActive" class="runbook-run-step__upload">
			<input
				type="file"
				class="runbook-run-step__file"
				:aria-label="t('runbook', 'Evidence file')"
				@change="onEvidenceSelected">
			<NcButton :disabled="evidenceFile === null" @click="submitEvidence">
				{{ t('runbook', 'Upload evidence') }}
			</NcButton>
		</div>

		<div v-if="canExecute && isEditable && runActive && step.type !== 'FILE'" class="runbook-run-step__response">
			<NcCheckboxRadioSwitch v-if="step.type === 'CHECK'" v-model="boolValue" type="checkbox">
				{{ t('runbook', 'Done') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch v-else-if="step.type === 'CONFIRMATION'" v-model="boolValue" type="checkbox">
				{{ t('runbook', 'I confirm') }}
			</NcCheckboxRadioSwitch>

			<NcTextArea v-else-if="step.type === 'TEXT'" v-model="textValue" :label="t('runbook', 'Response')" />

			<NcTextField
				v-else-if="step.type === 'NUMBER'"
				v-model="numberValue"
				type="number"
				:label="numberFieldLabel" />

			<NcSelect
				v-else-if="step.type === 'SELECT'"
				v-model="selectValue"
				:options="selectOptions"
				:clearable="false" />

			<input
				v-else-if="step.type === 'DATE'"
				v-model="dateValue"
				type="date"
				class="runbook-run-step__date"
				:aria-label="t('runbook', 'Response')">

			<NcSelect
				v-else-if="step.type === 'USER'"
				v-model="userValue"
				:options="userOptions"
				:loading="userLoading"
				label="displayName"
				:clearable="true"
				:placeholder="t('runbook', 'Search users')"
				@search="searchUsers" />
		</div>

		<div v-if="!canExecute || !isEditable" class="runbook-run-step__result">
			<p v-if="step.skipReason !== null">
				{{ t('runbook', 'Skipped: {reason}', { reason: step.skipReason }) }}
			</p>
			<p v-else-if="step.response !== null">
				{{ t('runbook', 'Response: {response}', { response: responseText() }) }}
			</p>
		</div>

		<div v-if="canExecute && runActive && isEditable" class="runbook-run-step__actions">
			<NcButton v-if="step.status === 'PENDING'" @click="emit('start')">
				{{ t('runbook', 'Start') }}
			</NcButton>
			<NcButton v-if="step.type !== 'FILE'" @click="emit('save', currentResponse())">
				{{ t('runbook', 'Save response') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canComplete" @click="emit('complete', currentResponse())">
				{{ t('runbook', 'Complete') }}
			</NcButton>
			<NcButton @click="skipOpen = !skipOpen">
				{{ t('runbook', 'Skip') }}
			</NcButton>
		</div>

		<div v-if="canExecute && runActive && !isEditable && canReopen" class="runbook-run-step__actions">
			<NcButton @click="emit('reopen')">
				{{ t('runbook', 'Reopen step') }}
			</NcButton>
		</div>

		<div v-if="canManageAssignments && runActive" class="runbook-run-step__assign">
			<NcButton variant="tertiary" @click="assignOpen = !assignOpen">
				{{ t('runbook', 'Assign step') }}
			</NcButton>
			<div v-if="assignOpen" class="runbook-run-step__assign-form">
				<NcSelect
					v-model="assigneeValue"
					:options="assigneeOptions"
					:loading="assigneeLoading"
					label="displayName"
					:clearable="true"
					:placeholder="t('runbook', 'Search users and groups')"
					@search="searchAssignees" />
				<input
					v-model="assigneeDueDate"
					type="date"
					class="runbook-run-step__date"
					:aria-label="t('runbook', 'Due date')">
				<NcButton variant="primary" @click="submitAssignment">
					{{ t('runbook', 'Save assignment') }}
				</NcButton>
			</div>
		</div>

		<div v-if="skipOpen" class="runbook-run-step__skip">
			<NcTextArea v-model="skipReason" :label="t('runbook', 'Skip reason')" />
			<div class="runbook-run-step__skip-actions">
				<NcButton :disabled="requireSkipReason && skipReason.trim() === ''" @click="confirmSkip">
					{{ t('runbook', 'Confirm skip') }}
				</NcButton>
				<NcButton @click="skipOpen = false">
					{{ t('runbook', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<style scoped>
.runbook-run-step {
	border: 1px solid var(--color-border, #ededed);
	border-inline-start-width: 4px;
	border-radius: var(--border-radius, 4px);
	padding: 8px;
	margin-bottom: 8px;
}

/* Theme-safe status accents. Element colors are provided by Nextcloud for
 * both light and dark themes; the fallbacks keep the treatment readable. */
.runbook-run-step--completed {
	border-inline-start-color: var(--color-success-element, #099f05);
}

.runbook-run-step--pending {
	border-inline-start-color: var(--color-info-element, #0077c7);
}

.runbook-run-step--in-progress {
	border-inline-start-color: var(--color-error-element, #c90000);
}

.runbook-run-step--skipped {
	border-inline-start-color: var(--color-warning-element, #bf7900);
}

.runbook-run-step--reopened {
	border-inline-start-color: color-mix(in srgb, var(--color-warning-element, #bf7900) 55%, var(--color-error-element, #c90000));
}

.runbook-run-step--completed .runbook-run-step__status {
	color: var(--color-success-element, #099f05);
	font-weight: bold;
}

.runbook-run-step--pending .runbook-run-step__status {
	color: var(--color-info-element, #0077c7);
	font-weight: bold;
}

.runbook-run-step--in-progress .runbook-run-step__status {
	color: var(--color-error-element, #c90000);
	font-weight: bold;
}

.runbook-run-step--skipped .runbook-run-step__status {
	color: var(--color-warning-element, #bf7900);
	font-weight: bold;
}

.runbook-run-step--reopened .runbook-run-step__status {
	color: color-mix(in srgb, var(--color-warning-element, #bf7900) 55%, var(--color-error-element, #c90000));
	font-weight: bold;
}

.runbook-run-step__header {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.runbook-run-step__title {
	font-weight: bold;
	flex: 1 1 auto;
}

.runbook-run-step__type,
.runbook-run-step__unit,
.runbook-run-step__required,
.runbook-run-step__status,
.runbook-run-step__assignee {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-run-step__overdue {
	color: var(--color-error, #d40000);
	font-weight: bold;
	font-size: 0.85em;
}

.runbook-run-step__description {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-run-step__response {
	margin: 8px 0;
}

.runbook-run-step__date {
	width: 100%;
	padding: 8px;
}

.runbook-run-step__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.runbook-run-step__assign {
	margin-top: 8px;
}

.runbook-run-step__assign-form {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.runbook-run-step__evidence {
	margin: 8px 0;
}

.runbook-run-step__evidence-row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 2px 0;
}

.runbook-run-step__evidence-name {
	font-weight: bold;
	overflow-wrap: anywhere;
}

.runbook-run-step__evidence-size {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-run-step__upload {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
}

.runbook-run-step__file {
	flex: 1 1 200px;
}

.runbook-run-step__skip {
	margin-top: 8px;
}

.runbook-run-step__skip-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}
</style>
