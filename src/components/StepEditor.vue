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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { STEP_TYPES } from '../models/template.ts'

interface TypeOption {
	id: StepType
	label: string
}

const props = defineProps<{
	step: TemplateStep
	busy?: boolean
	canMoveUp: boolean
	canMoveDown: boolean
}>()

const emit = defineEmits<{
	save: [payload: StepPayload]
	delete: []
	moveUp: []
	moveDown: []
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

const expanded = ref(false)
const editing = ref(false)
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
	editing.value = false
}

watch(() => props.step, reset, { deep: true })

/**
 *
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
 * Build and emit the step payload from the local form state.
 */
function submit(): void {
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

	emit('save', payload)
}
</script>

<template>
	<div class="runbook-step">
		<div class="runbook-step__summary">
			<button
				type="button"
				class="runbook-step__toggle"
				:aria-expanded="expanded"
				@click="expanded = !expanded">
				{{ expanded ? '▾' : '▸' }}
			</button>
			<span class="runbook-step__title">{{ step.title }}</span>
			<span class="runbook-step__type">{{ typeLabel(step.type) }}</span>
			<span v-if="unitDisplay !== ''" class="runbook-step__unit">{{ unitDisplay }}</span>
			<span v-if="step.required" class="runbook-step__required">{{ t('runbook', 'Required') }}</span>
			<div class="runbook-step__actions">
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
				<NcButton variant="tertiary" @click="editing = !editing">
					{{ t('runbook', 'Edit') }}
				</NcButton>
				<NcButton variant="error" @click="emit('delete')">
					{{ t('runbook', 'Delete') }}
				</NcButton>
			</div>
		</div>

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
				<div v-for="(option, index) in options" :key="index" class="runbook-step__option">
					<NcTextField v-model="options[index]" :label="t('runbook', 'Option')" />
					<NcButton variant="tertiary" @click="removeOption(index)">
						{{ t('runbook', 'Remove') }}
					</NcButton>
				</div>
				<NcButton @click="addOption">
					{{ t('runbook', 'Add option') }}
				</NcButton>
			</div>

			<NcTextField v-if="type === 'NUMBER'" v-model="unit" :label="t('runbook', 'Unit')" />

			<div class="runbook-step__form-actions">
				<NcButton type="submit" variant="primary" :disabled="busy">
					{{ t('runbook', 'Save step') }}
				</NcButton>
				<NcButton @click="editing = false">
					{{ t('runbook', 'Cancel') }}
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

.runbook-step__toggle {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 1em;
	padding: 0 4px;
}

.runbook-step__title {
	font-weight: bold;
	flex: 1 1 auto;
}

.runbook-step__type,
.runbook-step__unit,
.runbook-step__required {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-step__actions {
	display: flex;
	gap: 4px;
}

.runbook-step__form {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.runbook-step__label {
	font-weight: bold;
}

.runbook-step__option {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 4px;
}

.runbook-step__form-actions {
	display: flex;
	gap: 8px;
}
</style>
