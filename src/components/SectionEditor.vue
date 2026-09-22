<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ConditionOperator, SectionPayload, StepCondition, StepPayload, TemplateSection, TemplateStep } from '../models/template.ts'
import type { ConditionDraftRow, ConditionStepOption, SectionDraft } from '../utils/templateAuthoring.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import StepEditor from './StepEditor.vue'
import { conditionOperatorsForType } from '../models/template.ts'
import { conditionOperatorLabel, ruleSummary } from '../utils/sectionFlow.ts'
import {
	descriptionPreview,
	incompleteConditionRows,
	isSectionDraftDirty,
	savedConditions,
} from '../utils/templateAuthoring.ts'

interface ConditionOperatorOption {
	id: ConditionOperator
	label: string
}

const props = defineProps<{
	section: TemplateSection
	steps: TemplateStep[]
	position: number
	siblingSections?: Array<{ id: number, title: string }>
	conditionSteps?: ConditionStepOption[]
	busy?: boolean
	canEdit?: boolean
	editingStepId?: number | null
	stepError?: string | null
	resetToken?: number
	canMoveUp: boolean
	canMoveDown: boolean
}>()

const emit = defineEmits<{
	save: [payload: SectionPayload]
	delete: []
	moveUp: []
	moveDown: []
	addStep: []
	stepEdit: [stepId: number]
	stepCancel: []
	stepDirty: [dirty: boolean]
	stepSave: [event: { stepId: number, payload: StepPayload }]
	stepDelete: [stepId: number]
	stepMove: [event: { stepId: number, direction: 'up' | 'down' }]
	'update:dirty': [dirty: boolean]
}>()

const editing = ref(false)
const validationMessage = ref<string | null>(null)
const title = ref(props.section.title)
const description = ref(props.section.description)
const notes = ref(props.section.notes)
const dependsOnValue = ref<Array<{ id: number, title: string }>>([])
const conditionRows = ref<ConditionDraftRow[]>([])

const savedRuleText = computed<string>(() => ruleSummary(t, {
	dependsOnTitles: (props.siblingSections ?? [])
		.filter((candidate) => props.section.dependsOn.includes(candidate.id))
		.map((candidate) => candidate.title),
	conditions: savedConditions(props.section).map((condition) => ({
		stepTitle: stepTitleForCondition(condition.stepId),
		operator: condition.operator,
		value: condition.value,
	})),
}))

const conditionOptionById = computed(() => new Map((props.conditionSteps ?? []).map((option) => [option.id, option])))

/**
 * Saved title of a step referenced by a condition.
 *
 * @param stepId Run/template step id.
 */
function stepTitleForCondition(stepId: number): string {
	return conditionOptionById.value.get(stepId)?.title ?? ''
}

/**
 * Selected condition step option for a row.
 *
 * @param row Draft condition row.
 */
function conditionOptionFor(row: ConditionDraftRow): ConditionStepOption | null {
	return row.stepId === null ? null : (conditionOptionById.value.get(row.stepId) ?? null)
}

/**
 * Operator choices for a condition row, based on the selected step type.
 *
 * @param row Draft condition row.
 */
function operatorOptionsFor(row: ConditionDraftRow): ConditionOperatorOption[] {
	const type = conditionOptionFor(row)?.type ?? 'TEXT'
	return conditionOperatorsForType(type).map((operator) => ({ id: operator, label: conditionOperatorLabel(t, operator) }))
}

/**
 * Selected operator option for a condition row.
 *
 * @param row Draft condition row.
 */
function selectedOperatorFor(row: ConditionDraftRow): ConditionOperatorOption | null {
	return operatorOptionsFor(row).find((option) => option.id === row.operator) ?? null
}

/**
 * Whether a condition row needs a comparison value.
 *
 * @param row Draft condition row.
 */
function needsValue(row: ConditionDraftRow): boolean {
	const type = conditionOptionFor(row)?.type
	return type !== undefined && type !== 'CHECK' && type !== 'CONFIRMATION'
}

/**
 * Apply the selected controlling step and keep the operator compatible.
 *
 * @param row Draft condition row.
 * @param option Selected step option.
 */
function onStepChange(row: ConditionDraftRow, option: ConditionStepOption | null): void {
	row.stepId = option?.id ?? null
	const options = operatorOptionsFor(row)
	if (!options.some((candidate) => candidate.id === row.operator)) {
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
 * Build condition payloads, skipping incomplete rows (submission is blocked
 * while any row is incomplete).
 */
function buildConditions(): StepCondition[] {
	const conditions: StepCondition[] = []
	for (const row of conditionRows.value) {
		if (row.stepId === null) {
			continue
		}
		const condition: StepCondition = { stepId: row.stepId, operator: row.operator }
		if (needsValue(row)) {
			condition.value = conditionOptionFor(row)?.type === 'NUMBER' ? Number(row.value) : row.value
		}
		conditions.push(condition)
	}

	return conditions
}

const incompleteRows = computed<number[]>(() => incompleteConditionRows(conditionRows.value, props.conditionSteps ?? []))
const draft = computed<SectionDraft>(() => ({
	title: title.value,
	description: description.value,
	notes: notes.value,
	dependsOn: dependsOnValue.value.map((candidate) => candidate.id),
	conditions: buildConditions(),
}))
const dirty = computed<boolean>(() => incompleteRows.value.length > 0 || isSectionDraftDirty(draft.value, props.section))
const draftRuleText = computed<string>(() => ruleSummary(t, {
	dependsOnTitles: dependsOnValue.value.map((candidate) => candidate.title),
	conditions: buildConditions().map((condition) => ({
		stepTitle: conditionOptionById.value.get(condition.stepId)?.title ?? '',
		operator: condition.operator,
		value: condition.value,
	})),
}))

watch(dirty, (value) => emit('update:dirty', value))

/**
 * Reset the local form state from the current section value.
 */
function reset(): void {
	title.value = props.section.title
	description.value = props.section.description
	notes.value = props.section.notes
	dependsOnValue.value = (props.siblingSections ?? []).filter((candidate) => props.section.dependsOn.includes(candidate.id))
	conditionRows.value = savedConditions(props.section).map((condition) => ({
		stepId: condition.stepId,
		operator: condition.operator,
		value: condition.value !== undefined ? String(condition.value) : '',
	}))
	validationMessage.value = null
	editing.value = false
	emit('update:dirty', false)
}

watch(() => props.section, reset, { deep: true })
watch(() => props.resetToken, () => reset())
reset()

/**
 * Leave the editing state, dropping the draft.
 */
function cancel(): void {
	reset()
}

/**
 * Emit the current section fields for saving.
 */
function submit(): void {
	if (incompleteRows.value.length > 0) {
		validationMessage.value = t('runbook', 'Complete or remove every condition row before saving.')
		return
	}
	validationMessage.value = null
	const conditions = buildConditions()
	emit('save', {
		title: title.value,
		description: description.value,
		notes: notes.value,
		dependsOn: dependsOnValue.value.map((candidate) => candidate.id),
		conditions,
		condition: conditions[0] ?? null,
	})
	editing.value = false
}
</script>

<template>
	<article class="runbook-section-editor">
		<header class="runbook-section-editor__summary">
			<div class="runbook-section-editor__summary-main">
				<h3 class="runbook-section-editor__title">
					<span class="runbook-section-editor__position">{{ position }}</span>
					{{ section.title || t('runbook', 'Untitled section') }}
				</h3>
				<p class="runbook-section-editor__meta">
					{{ steps.length > 0 ? t('runbook', '{count} steps', { count: steps.length }) : t('runbook', 'No steps') }}
				</p>
				<p v-if="section.description" class="runbook-section-editor__preview">
					{{ descriptionPreview(section.description) }}
				</p>
				<p class="runbook-section-editor__rule">
					{{ savedRuleText }}
				</p>
			</div>
			<div v-if="canEdit ?? true" class="runbook-section-editor__summary-actions">
				<NcButton v-if="!editing" :disabled="busy" @click="editing = true">
					{{ t('runbook', 'Edit section') }}
				</NcButton>
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
			</div>
		</header>

		<form v-if="editing" class="runbook-section-editor__form" @submit.prevent="submit">
			<h4 class="runbook-section-editor__group">
				{{ t('runbook', 'Basics') }}
			</h4>
			<NcTextField v-model="title" :label="t('runbook', 'Section title')" />
			<NcTextArea v-model="description" :label="t('runbook', 'Section description')" />
			<NcTextArea v-model="notes" :label="t('runbook', 'Section notes')" />

			<h4 class="runbook-section-editor__group">
				{{ t('runbook', 'Opening rules') }}
			</h4>
			<p class="runbook-section-editor__guidance">
				{{ t('runbook', 'A section opens when all of its conditions match and all prerequisite sections are resolved. Conditions in one section are combined with AND; create alternative paths as separate conditional sections.') }}
			</p>

			<div v-if="(siblingSections ?? []).length > 0" class="runbook-section-editor__field">
				<span class="runbook-section-editor__label">{{ t('runbook', 'Depends on sections') }}</span>
				<NcSelect
					v-model="dependsOnValue"
					:options="siblingSections ?? []"
					:multiple="true"
					label="title"
					:placeholder="t('runbook', 'Select prerequisite sections')" />
			</div>

			<div class="runbook-section-editor__field">
				<span class="runbook-section-editor__label">{{ t('runbook', 'Conditions (all must match)') }}</span>
				<p v-if="conditionRows.length === 0" class="runbook-section-editor__hint">
					{{ t('runbook', 'This section has no condition.') }}
				</p>
				<div
					v-for="(row, index) in conditionRows"
					:key="index"
					class="runbook-section-editor__condition"
					:class="{ 'runbook-section-editor__condition--incomplete': incompleteRows.includes(index) }">
					<NcSelect
						:modelValue="conditionOptionFor(row)"
						:options="conditionSteps ?? []"
						label="label"
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
						v-if="needsValue(row)"
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

			<p v-if="dirty" class="runbook-section-editor__preview-rule">
				{{ t('runbook', 'Preview (unsaved changes): {summary}', { summary: draftRuleText }) }}
			</p>

			<NcNoteCard v-if="validationMessage" type="error">
				{{ validationMessage }}
			</NcNoteCard>

			<div class="runbook-section-editor__form-actions">
				<NcButton variant="primary" type="submit" :disabled="busy">
					{{ t('runbook', 'Save section') }}
				</NcButton>
				<NcButton :disabled="busy" @click="cancel">
					{{ t('runbook', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="error"
					class="runbook-section-editor__delete"
					:disabled="busy"
					@click="emit('delete')">
					{{ t('runbook', 'Delete section') }}
				</NcButton>
			</div>
		</form>

		<section class="runbook-section-editor__steps">
			<h4 class="runbook-section-editor__group">
				{{ t('runbook', 'Steps') }}
			</h4>
			<p v-if="steps.length === 0" class="runbook-section-editor__hint">
				{{ t('runbook', 'This section has no steps. Information-only sections are allowed; add a step when it needs to be executed.') }}
			</p>
			<StepEditor
				v-for="(step, index) in steps"
				:key="step.id"
				:step="step"
				:index="index + 1"
				:busy="busy"
				:canEdit="canEdit ?? true"
				:editing="editingStepId === step.id"
				:error="editingStepId === step.id ? (stepError ?? null) : null"
				:canMoveUp="index > 0"
				:canMoveDown="index < steps.length - 1"
				@edit="emit('stepEdit', step.id)"
				@cancel="emit('stepCancel')"
				@update:dirty="emit('stepDirty', $event)"
				@save="(payload) => emit('stepSave', { stepId: step.id, payload })"
				@delete="emit('stepDelete', step.id)"
				@moveUp="emit('stepMove', { stepId: step.id, direction: 'up' })"
				@moveDown="emit('stepMove', { stepId: step.id, direction: 'down' })" />
			<NcButton v-if="canEdit ?? true" :disabled="busy" @click="emit('addStep')">
				{{ t('runbook', 'Add step') }}
			</NcButton>
		</section>
	</article>
</template>

<style scoped>
.runbook-section-editor {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-3);
	padding: var(--runbook-space-3);
	border: 1px solid var(--runbook-border);
	border-radius: var(--runbook-radius);
	background-color: var(--runbook-surface);
}

.runbook-section-editor__summary {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	gap: var(--runbook-space-2);
}

.runbook-section-editor__summary-main {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
	min-width: 0;
}

.runbook-section-editor__title {
	display: flex;
	gap: var(--runbook-space-2);
	margin: 0;
	overflow-wrap: anywhere;
}

.runbook-section-editor__position {
	min-width: 1.4em;
	color: var(--runbook-text-muted);
	font-variant-numeric: tabular-nums;
}

.runbook-section-editor__meta,
.runbook-section-editor__preview,
.runbook-section-editor__hint,
.runbook-section-editor__guidance {
	margin: 0;
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
	overflow-wrap: anywhere;
}

.runbook-section-editor__rule,
.runbook-section-editor__preview-rule {
	margin: 0;
	padding: var(--runbook-space-1) var(--runbook-space-3);
	border-inline-start: 3px solid var(--runbook-border-strong);
	background-color: var(--runbook-surface-subtle);
	border-radius: var(--runbook-radius);
	color: var(--runbook-text-muted);
	font-size: var(--runbook-font-small);
	overflow-wrap: anywhere;
}

.runbook-section-editor__summary-actions,
.runbook-section-editor__form-actions {
	display: flex;
	flex-wrap: wrap;
	gap: var(--runbook-space-2);
	align-items: flex-start;
}

.runbook-section-editor__delete {
	margin-inline-start: auto;
}

.runbook-section-editor__form {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	padding-top: var(--runbook-space-2);
	border-top: 1px solid var(--runbook-border);
}

.runbook-section-editor__group {
	margin: var(--runbook-space-2) 0 0;
}

.runbook-section-editor__field {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-1);
}

.runbook-section-editor__label {
	font-weight: 600;
}

.runbook-section-editor__condition {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: var(--runbook-space-2);
}

.runbook-section-editor__condition > * {
	flex: 1 1 160px;
}

.runbook-section-editor__condition--incomplete {
	outline: 1px dashed var(--runbook-accent-danger);
	outline-offset: 2px;
}

.runbook-section-editor__steps {
	display: flex;
	flex-direction: column;
	gap: var(--runbook-space-2);
	padding-top: var(--runbook-space-2);
	border-top: 1px solid var(--runbook-border);
}
</style>
