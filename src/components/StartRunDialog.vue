<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { FilePickerClosed, FilePickerType, getFilePickerBuilder } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import * as api from '../services/runs.ts'
import { selectedPathFromPicker } from '../utils/adminDestination.ts'
import { apiErrorMessage } from '../utils/apiError.ts'
import { buildStartRunPayload, normalizeRunDestinationPath, startRunDestinationStatusText } from '../utils/startRun.ts'
import { createStartRunSubmission } from '../utils/startRunSubmission.ts'

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
// Optional one-time destination (#49); null = inherit template/admin/default.
const destinationPath = ref<string | null>(null)
const busy = ref(false)
const error = ref<string | null>(null)

const hasDestination = computed<boolean>(() => normalizeRunDestinationPath(destinationPath.value) !== null)
const destinationStatusText = computed<string>(() => startRunDestinationStatusText(t, destinationPath.value))

// Centralized submission lifecycle: while `busy` a dismissal is ignored and the
// dialog stays mounted, so a still-running request can never look cancelled.
const submission = createStartRunSubmission({
	startRun: () => api.startRun(props.templateId, buildStartRunPayload({
		title: title.value,
		description: description.value,
		dueDate: dueDate.value,
		destinationPath: destinationPath.value,
	})),
	onStarted: (runId) => emit('started', runId),
	onClose: () => emit('close'),
	describeError: (caught) => apiErrorMessage(caught),
	onBusyChange: (value) => {
		busy.value = value
	},
	onErrorChange: (value) => {
		error.value = value
	},
})

/**
 * Open the Nextcloud Files picker and remember the selected folder for this run
 * only. Cancelling the picker changes nothing.
 */
async function selectDestination(): Promise<void> {
	let picked: unknown
	try {
		picked = await getFilePickerBuilder(t('runbook', 'Select destination folder'))
			.setMultiSelect(false)
			.allowDirectories(true)
			.setType(FilePickerType.Choose)
			.build()
			.pick()
	} catch (caught) {
		if (caught instanceof FilePickerClosed) {
			return
		}
		error.value = apiErrorMessage(caught)
		return
	}

	const path = selectedPathFromPicker(picked)
	if (path !== null) {
		destinationPath.value = path
	}
}

/**
 * Return to the inherited destination (template, administration, or default).
 */
function useInheritedDestination(): void {
	destinationPath.value = null
}
</script>

<template>
	<NcDialog
		:name="t('runbook', 'Start run')"
		:noClose="busy"
		:closeOnClickOutside="!busy"
		@closing="submission.requestClose()">
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

		<div class="runbook-start-run__destination">
			<span class="runbook-start-run__destination-label">{{ t('runbook', 'Destination folder') }}</span>
			<span class="runbook-start-run__destination-path">
				{{ hasDestination ? destinationPath : t('runbook', 'Inherited destination') }}
			</span>
			<div class="runbook-start-run__destination-actions">
				<NcButton :disabled="busy" @click="selectDestination">
					{{ hasDestination ? t('runbook', 'Change folder') : t('runbook', 'Select folder') }}
				</NcButton>
				<NcButton
					v-if="hasDestination"
					variant="tertiary"
					:disabled="busy"
					@click="useInheritedDestination">
					{{ t('runbook', 'Use inherited destination') }}
				</NcButton>
			</div>
			<p class="runbook-start-run__hint">
				{{ destinationStatusText }}
			</p>
		</div>

		<template #actions>
			<NcButton :disabled="busy" @click="submission.requestClose()">
				{{ t('runbook', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="busy || title.trim() === ''" @click="submission.submit()">
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

.runbook-start-run__destination {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 12px;
}

.runbook-start-run__destination-label {
	font-weight: bold;
}

.runbook-start-run__destination-path {
	overflow-wrap: anywhere;
}

.runbook-start-run__destination-actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 4px;
}

.runbook-start-run__hint {
	color: var(--color-text-maxcontrast, #555);
	margin: 0;
}
</style>
