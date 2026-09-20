<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

const props = defineProps<{
	templateId: number
	templateTitle: string
}>()

const emit = defineEmits<{
	started: [runId: number]
	close: []
}>()

const title = ref(props.templateTitle)
const description = ref('')
const dueDate = ref('')
const busy = ref(false)
const error = ref<string | null>(null)

/**
 *
 */
async function submit(): Promise<void> {
	busy.value = true
	error.value = null
	try {
		const run = await api.startRun(props.templateId, {
			title: title.value,
			description: description.value,
			dueAt: dueDate.value === '' ? null : Math.floor(new Date(`${dueDate.value}T00:00:00Z`).getTime() / 1000),
		})
		emit('started', run.id)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<NcDialog :name="t('runbook', 'Start run')" @closing="emit('close')">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
		<NcTextField v-model="title" :label="t('runbook', 'Title')" />
		<NcTextArea v-model="description" :label="t('runbook', 'Description')" />
		<label class="runbook-start-run__label" for="runbook-start-run-due">{{ t('runbook', 'Due date') }}</label>
		<input
			id="runbook-start-run-due"
			v-model="dueDate"
			type="date"
			class="runbook-start-run__date">
		<template #actions>
			<NcButton @click="emit('close')">
				{{ t('runbook', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy || title.trim() === ''" @click="submit">
				{{ t('runbook', 'Start run') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.runbook-start-run__label {
	display: block;
	margin-top: 8px;
	font-weight: bold;
}

.runbook-start-run__date {
	width: 100%;
	padding: 8px;
}
</style>
