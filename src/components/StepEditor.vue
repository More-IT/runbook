<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { StepPayload, StepType, TemplateStep } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { STEP_TYPES } from '../models/template.ts'
import { isStepDraftDirty } from '../utils/editorDrafts.ts'

interface TypeOption {
	id: StepType
	label: string
}

const props = defineProps<{
	step: TemplateStep
	index?: number
	busy?: boolean
	canEdit?: boolean
	editing?: boolean
	error?: string | null
	canMoveUp: boolean
	canMoveDown: boolean
}>()

const emit = defineEmits<{
	save: [payload: StepPayload]
	delete: []
	moveUp: []
	moveDown: []
	edit: []
	cancel: []
	'update:dirty': [dirty: boolean]
}>()

/**
 * Human readable label for a step type.
 *
 * @param type Step type value.
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

const typeOptions: TypeOption[] = STEP_TYPES.map((type) => ({ id: type, label: typeLabel(type) }))

const title = ref(props.step.title)
const description = ref(props.step.description)
const type = ref<StepType>(props.step.type)
const required = ref(props.step.required)
const defaultAssignee = ref(props.step.defaultAssignee ?? '')
const dueOffset = ref(props.step.dueOffset ?? '')
const options = ref<string[]>([...(props.step.config.options ?? [])])
const unit = ref(typeof props.step.config.unit === 'string' ? props.step.config.unit : '')

const selectedType = computed<TypeOption | null>({
	get: () => typeOptions.find((option) => option.id === type.value) ?? null,
	set: (option) => {
		if (option !== null) {
			type.value = option.id
		}
	},
})

/**
 * Configured unit of a NUMBER step for display, or an empty string when none.
 */
const unitDisplay = computed<string>(() => {
	if (props.step.type !== 'NUMBER') {
		return ''
	}
	const value = props.step.config.unit

	return typeof value === 'string' ? value.trim() : ''
})

/**
 * Build the step payload from the local form state.
 */
function buildPayload(): StepPayload {
	const payload: StepPayload = {
		title: title.value,
		description: description.value,
		type: type.value,
		required: required.value,
		defaultAssignee: defaultAssignee.value.trim() === '' ? null : defaultAssignee.value.trim(),
		dueOffset: dueOffset.value.trim() === '' ? null : dueOffset.value.trim(),
		config: {},
	}

	if (type.value === 'SELECT') {
		payload.config = {
			options: options.value.map((option) => option.trim()).filter((option) => option !== ''),
		}
	} else if (type.value === 'NUMBER') {
		payload.config = { unit: unit.value.trim() }
	}

	return payload
}

const dirty = computed<boolean>(() => props.editing === true && isStepDraftDirty(buildPayload(), props.step))

watch(dirty, (value) => emit('update:dirty', value))

/**
 * Reset the local form state from the current step value.
 */
function reset(): void {
	title.value = props.step.title
	description.value = props.step.description
	type.value = props.step.type
	required.value = props.step.required
	defaultAssignee.value = props.step.defaultAssignee ?? ''
	dueOffset.value = props.step.dueOffset ?? ''
	options.value = [...(props.step.config.options ?? [])]
	unit.value = typeof props.step.config.unit === 'string' ? props.step.config.unit : ''
}

// A saved step (or opening/closing the form) always starts from the saved value,
// so a discarded or cancelled draft can never leak back in.
watch(() => props.step, reset, { deep: true })
watch(() => props.editing, reset)
reset()

/**
 * Add a selection option.
 */
function addOption(): void {
	options.value.push('')
}

/**
 * Remove a selection option.
 *
 * @param index Index of the option to remove.
 */
function removeOption(index: number): void {
	options.value.splice(index, 1)
}

/**
 * Emit the step payload; the parent closes the editor only after a successful save.
 */
function submit(): void {
	emit('save', buildPayload())
}
</script>

<template>
	<div class="runbook-step">
		<div class="runbook-step__summary">
			<span v-if="index !== undefined" class="runbook-step__index">{{ index }}</span>
			<span class="runbook-step__title">{{ step.title }}</span>
			<span class="runbook-step__type">{{ typeLabel(step.type) }}</span>
			<span v-if="unitDisplay !== ''" class="runbook-step__unit">{{ unitDisplay }}</span>
			<span v-if="step.required" class="runbook-step__required">{{ t('runbook', 'Required') }}</span>
			<span v-if="step.defaultAssignee" class="runbook-step__assignee">{{ step.defaultAssignee }}</span>
			<span v-if="step.dueOffset" class="runbook-step__due">{{ step.dueOffset }}</span>
			<div v-if="canEdit && !editing" class="runbook-step__actions">
				<NcButton
					variant="tertiary"
					:disabled="!canMoveUp || busy"
					:ariaLabel="t('runbook', 'Move up')"
					@click="emit('moveUp')">
					↑
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!canMoveDown || busy"
					:ariaLabel="t('runbook', 'Move down')"
					@click="emit('moveDown')">
					↓
				</NcButton>
				<NcButton :disabled="busy" @click="emit('edit')">
					{{ t('runbook', 'Edit step') }}
				</NcButton>
			</div>
		</div>

		<p v-if="!editing && step.description" class="runbook-step__description">
			{{ step.description }}
		</p>

		<form v-if="editing" class="runbook-step__form" @submit.prevent="submit">
			<NcTextField v-model="title" :label="t('runbook', 'Title')" />
			<NcTextArea v-model="description" :label="t('runbook', 'Instructions')" />
			<label class="runbook-step__label" for="runbook-step-type">{{ t('runbook', 'Step type') }}</label>
			<NcSelect
				v-model="selectedType"
				inputId="runbook-step-type"
				:options="typeOptions"
				label="label"
				:clearable="false" />
			<NcCheckboxRadioSwitch v-model="required" type="switch">
				{{ t('runbook', 'Required') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model="defaultAssignee"
				:label="t('runbook', 'Default assignee')"
				:helperText="t('runbook', 'Nextcloud principal, for example principals/users/alice.')" />
			<NcTextField
				v-model="dueOffset"
				type="number"
				:label="t('runbook', 'Due offset')"
				:helperText="t('runbook', 'Minutes after the run starts, for example 60 for one hour.')" />
			<div v-if="type === 'SELECT'" class="runbook-step__options">
				<span class="runbook-step__label">{{ t('runbook', 'Options') }}</span>
				<div v-for="(option, optionIndex) in options" :key="optionIndex" class="runbook-step__option">
					<NcTextField v-model="options[optionIndex]" :label="t('runbook', 'Option')" />
					<NcButton variant="tertiary" @click="removeOption(optionIndex)">
						{{ t('runbook', 'Remove') }}
					</NcButton>
				</div>
				<NcButton @click="addOption">
					{{ t('runbook', 'Add option') }}
				</NcButton>
			</div>
			<NcTextField v-if="type === 'NUMBER'" v-model="unit" :label="t('runbook', 'Unit')" />

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<div class="runbook-step__form-actions">
				<NcButton type="submit" variant="primary" :disabled="busy">
					{{ t('runbook', 'Save step') }}
				</NcButton>
				<NcButton :disabled="busy" @click="emit('cancel')">
					{{ t('runbook', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					class="runbook-step__delete"
					:disabled="busy"
					@click="emit('delete')">
					{{ t('runbook', 'Delete') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<style scoped>
.runbook-step {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 8px;
	margin-bottom: 8px;
}

.runbook-step__summary {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
}

.runbook-step__title {
	font-weight: bold;
	flex: 1 1 auto;
}

.runbook-step__index {
	min-width: 1.4em;
	color: var(--runbook-text-muted);
	font-variant-numeric: tabular-nums;
}

.runbook-step__type,
.runbook-step__unit,
.runbook-step__required,
.runbook-step__assignee,
.runbook-step__due {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-step__description {
	margin: 8px 0 0;
	color: var(--color-text-maxcontrast, #555);
	overflow-wrap: anywhere;
}

.runbook-step__actions {
	display: flex;
	gap: 4px;
}

.runbook-step__form {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	margin-top: var(--runbook-space-2);
}

.runbook-step__label {
	font-weight: 600;
}

.runbook-step__options {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
}

.runbook-step__option {
	display: flex;
	align-items: flex-end;
	gap: var(--runbook-space-1);
}

.runbook-step__option > :first-child {
	flex: 1 1 auto;
}

.runbook-step__form-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-2);
}

.runbook-step__delete {
	margin-inline-start: auto;
}
</style>
