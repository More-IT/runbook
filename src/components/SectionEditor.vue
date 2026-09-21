<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ConditionOperator, SectionPayload, StepCondition, StepPayload, StepType, TemplateSection, TemplateStep } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import StepEditor from './StepEditor.vue'
import { conditionOperatorsForType } from '../models/template.ts'

interface ConditionStepOption {
	id: number
	title: string
	type: StepType
	options: string[]
}

interface ConditionOperatorOption {
	id: ConditionOperator
	label: string
}

interface ConditionRow {
	stepId: number | null
	operator: ConditionOperator
	value: string
}

const props = defineProps<{
	section: TemplateSection
	steps: TemplateStep[]
	siblingSections?: Array<{ id: number, title: string }>
	conditionSteps?: ConditionStepOption[]
	busy?: boolean
	canMoveUp: boolean
	canMoveDown: boolean
}>()

const emit = defineEmits<{
	save: [payload: SectionPayload]
	delete: []
	moveUp: []
	moveDown: []
	addStep: []
	stepSave: [event: { stepId: number, payload: StepPayload }]
	stepDelete: [stepId: number]
	stepMove: [event: { stepId: number, direction: 'up' | 'down' }]
}>()

const title = ref(props.section.title)
const description = ref(props.section.description)
const notes = ref(props.section.notes)
const dependsOnValue = ref<Array<{ id: number, title: string }>>([])
const conditionRows = ref<ConditionRow[]>([])

/**
 * Controlling step selected for a condition row.
 *
 * @param row Condition row.
 */
function conditionStepFor(row: ConditionRow): ConditionStepOption | null {
	return (props.conditionSteps ?? []).find((candidate) => candidate.id === row.stepId) ?? null
}

/**
 * Steps that may control this section's condition. Steps of the section itself
 * are excluded because same-section conditions are rejected by the server (the
 * section could never become available).
 */
function selectableConditionSteps(): ConditionStepOption[] {
	const ownStepIds = new Set(props.steps.map((step) => step.id))
	return (props.conditionSteps ?? []).filter((step) => !ownStepIds.has(step.id))
}

/**
 * Operator choices for a condition row, based on the selected step type.
 *
 * @param row Condition row.
 */
function operatorOptionsFor(row: ConditionRow): ConditionOperatorOption[] {
	const type = conditionStepFor(row)?.type
	return conditionOperatorsForType(type ?? 'TEXT').map((operator) => ({ id: operator, label: operatorLabel(operator) }))
}

/**
 * Selected operator option for a condition row.
 *
 * @param row Condition row.
 */
function selectedOperatorFor(row: ConditionRow): ConditionOperatorOption | null {
	return operatorOptionsFor(row).find((option) => option.id === row.operator) ?? null
}

/**
 * Whether a condition row needs a comparison value (boolean steps do not).
 *
 * @param row Condition row.
 */
function needsValueFor(row: ConditionRow): boolean {
	const type = conditionStepFor(row)?.type
	return type !== undefined && type !== 'CHECK' && type !== 'CONFIRMATION'
}

/**
 * Apply the selected controlling step and keep the operator compatible.
 *
 * @param row Condition row.
 * @param step Selected step option.
 */
function onStepChange(row: ConditionRow, step: ConditionStepOption | null): void {
	row.stepId = step?.id ?? null
	const options = operatorOptionsFor(row)
	if (!options.some((option) => option.id === row.operator)) {
		row.operator = options[0]?.id ?? 'equals'
	}
}

/**
 * Add an empty condition row to the gate.
 */
function addCondition(): void {
	conditionRows.value.push({ stepId: null, operator: 'equals', value: '' })
}

/**
 * @param index Row index.
 */
function removeCondition(index: number): void {
	conditionRows.value.splice(index, 1)
}

/**
 * Human readable label for a condition operator.
 *
 * @param operator Condition operator.
 */
function operatorLabel(operator: ConditionOperator): string {
	switch (operator) {
		case 'is_true':
			return t('runbook', 'is yes')
		case 'is_false':
			return t('runbook', 'is no')
		case 'equals':
			return t('runbook', 'equals')
		case 'not_equals':
			return t('runbook', 'does not equal')
		case 'greater_than':
			return t('runbook', 'is greater than')
		case 'less_than':
			return t('runbook', 'is less than')
		case 'greater_or_equal':
			return t('runbook', 'is greater or equal')
		case 'less_or_equal':
			return t('runbook', 'is less or equal')
	}
}

/**
 * Reset the local form state from the current section value.
 */
function reset(): void {
	title.value = props.section.title
	description.value = props.section.description
	notes.value = props.section.notes
	dependsOnValue.value = (props.siblingSections ?? []).filter((candidate) => props.section.dependsOn.includes(candidate.id))
	const conditions = props.section.conditions.length > 0
		? props.section.conditions
		: (props.section.condition !== null ? [props.section.condition] : [])
	conditionRows.value = conditions.map((condition) => ({
		stepId: condition.stepId,
		operator: condition.operator,
		value: condition.value !== undefined ? String(condition.value) : '',
	}))
}

watch(() => props.section, reset, { deep: true })
reset()

/**
 * Emit the current section fields for saving.
 */
function submit(): void {
	const conditions: StepCondition[] = []
	for (const row of conditionRows.value) {
		if (row.stepId === null) {
			continue
		}
		const condition: StepCondition = { stepId: row.stepId, operator: row.operator }
		if (needsValueFor(row)) {
			condition.value = conditionStepFor(row)?.type === 'NUMBER' ? Number(row.value) : row.value
		}
		conditions.push(condition)
	}
	emit('save', {
		title: title.value,
		description: description.value,
		notes: notes.value,
		dependsOn: dependsOnValue.value.map((section) => section.id),
		conditions,
		condition: conditions[0] ?? null,
	})
}
</script>

<template>
	<div class="runbook-section">
		<header class="runbook-section__header">
			<NcTextField v-model="title" :label="t('runbook', 'Section title')" />
			<div class="runbook-section__actions">
				<NcButton
					variant="tertiary"
					:disabled="!canMoveUp || busy"
					:ariaLabel="t('runbook', 'Move section up')"
					@click="emit('moveUp')">
					↑
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="!canMoveDown || busy"
					:ariaLabel="t('runbook', 'Move section down')"
					@click="emit('moveDown')">
					↓
				</NcButton>
				<NcButton variant="error" @click="emit('delete')">
					{{ t('runbook', 'Delete section') }}
				</NcButton>
			</div>
		</header>

		<NcTextArea v-model="description" :label="t('runbook', 'Section description')" />

		<NcTextArea v-model="notes" :label="t('runbook', 'Section notes')" />

		<div v-if="(siblingSections ?? []).length > 0" class="runbook-section__flow">
			<span class="runbook-section__label">{{ t('runbook', 'Depends on sections') }}</span>
			<NcSelect
				v-model="dependsOnValue"
				:options="siblingSections ?? []"
				:multiple="true"
				label="title"
				:placeholder="t('runbook', 'Select prerequisite sections')" />
		</div>

		<div class="runbook-section__flow">
			<span class="runbook-section__label">{{ t('runbook', 'Conditions (all must match)') }}</span>
			<p v-if="conditionRows.length === 0" class="runbook-section__hint">
				{{ t('runbook', 'This section has no condition.') }}
			</p>
			<div
				v-for="(row, index) in conditionRows"
				:key="index"
				class="runbook-section__condition">
				<NcSelect
					:modelValue="conditionStepFor(row)"
					:options="selectableConditionSteps()"
					label="title"
					:clearable="false"
					:placeholder="t('runbook', 'Controlling step')"
					@update:modelValue="(value) => onStepChange(row, value)" />
				<NcSelect
					:modelValue="selectedOperatorFor(row)"
					:options="operatorOptionsFor(row)"
					label="label"
					:clearable="false"
					@update:modelValue="(value) => { if (value !== null) row.operator = value.id }" />
				<NcTextField
					v-if="needsValueFor(row)"
					v-model="row.value"
					:label="t('runbook', 'Condition value')" />
				<NcButton variant="tertiary" :ariaLabel="t('runbook', 'Remove condition')" @click="removeCondition(index)">
					✕
				</NcButton>
			</div>
			<NcButton :disabled="busy" @click="addCondition">
				{{ t('runbook', 'Add condition') }}
			</NcButton>
		</div>

		<div class="runbook-section__save">
			<NcButton :disabled="busy" @click="submit">
				{{ t('runbook', 'Save section') }}
			</NcButton>
		</div>

		<div class="runbook-section__steps">
			<StepEditor
				v-for="(step, index) in steps"
				:key="step.id"
				:step="step"
				:busy="busy"
				:canMoveUp="index > 0"
				:canMoveDown="index < steps.length - 1"
				@save="(payload) => emit('stepSave', { stepId: step.id, payload })"
				@delete="emit('stepDelete', step.id)"
				@moveUp="emit('stepMove', { stepId: step.id, direction: 'up' })"
				@moveDown="emit('stepMove', { stepId: step.id, direction: 'down' })" />
			<NcButton :disabled="busy" @click="emit('addStep')">
				{{ t('runbook', 'Add step') }}
			</NcButton>
		</div>
	</div>
</template>

<style scoped>
.runbook-section {
	border: 1px solid var(--color-border-dark, #ccc);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 16px;
}

.runbook-section__header {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.runbook-section__actions {
	display: flex;
	gap: 4px;
	margin-bottom: 4px;
}

.runbook-section__save {
	margin: 8px 0;
}

.runbook-section__steps {
	margin-top: 8px;
}

.runbook-section__flow {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 8px;
}

.runbook-section__label {
	font-weight: bold;
}

.runbook-section__hint {
	margin: 0;
	color: var(--color-text-maxcontrast, #666);
}

.runbook-section__condition {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
}

.runbook-section__condition > * {
	flex: 1 1 160px;
}
</style>
