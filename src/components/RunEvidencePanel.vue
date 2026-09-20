<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunAttachment } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { attachmentDownloadUrl } from '../services/runs.ts'

const props = defineProps<{
	attachments: RunAttachment[]
	stepTitles: Record<number, string>
	uid: string
	isOwner: boolean
	active: boolean
}>()

const emit = defineEmits<{
	remove: [id: number]
}>()

/**
 * Human readable file size.
 *
 * @param size Size in bytes.
 */
function formatSize(size: number): string {
	if (size < 1024) {
		return `${size} B`
	}
	if (size < 1024 * 1024) {
		return `${(size / 1024).toFixed(1)} KiB`
	}

	return `${(size / (1024 * 1024)).toFixed(1)} MiB`
}

/**
 * Format a Unix timestamp for display.
 *
 * @param timestamp Unix timestamp in seconds.
 */
function formatDate(timestamp: number): string {
	return new Date(timestamp * 1000).toLocaleString()
}

/**
 * Whether the current user may delete an attachment.
 *
 * @param attachment Attachment to check.
 */
function canDelete(attachment: RunAttachment): boolean {
	return props.active && (props.isOwner || attachment.uploaderUid === props.uid)
}

/**
 * Human readable label of the step an attachment belongs to.
 *
 * @param stepId Run step identifier.
 */
function stepTitle(stepId: number): string {
	return props.stepTitles[stepId] ?? t('runbook', 'Step {id}', { id: stepId })
}
</script>

<template>
	<section class="runbook-evidence">
		<h3>{{ t('runbook', 'Evidence') }}</h3>

		<p v-if="attachments.length === 0" class="runbook-evidence__hint">
			{{ t('runbook', 'No evidence uploaded yet.') }}
		</p>

		<div v-for="attachment in attachments" :key="attachment.id" class="runbook-evidence__row">
			<div class="runbook-evidence__info">
				<span class="runbook-evidence__name">{{ attachment.filename }}</span>
				<span class="runbook-evidence__meta">
					{{ stepTitle(attachment.stepId) }}
					·
					{{ formatSize(attachment.size) }}
					·
					{{ attachment.uploaderUid }}
					·
					{{ formatDate(attachment.createdAt) }}
				</span>
			</div>
			<NcButton :href="attachmentDownloadUrl(attachment.id)">
				{{ t('runbook', 'Download') }}
			</NcButton>
			<NcButton v-if="canDelete(attachment)" variant="error" @click="emit('remove', attachment.id)">
				{{ t('runbook', 'Delete') }}
			</NcButton>
		</div>
	</section>
</template>

<style scoped>
.runbook-evidence {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
}

.runbook-evidence__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-evidence__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	border-bottom: 1px solid var(--color-border, #ededed);
	padding: 6px 0;
}

.runbook-evidence__info {
	display: flex;
	flex-direction: column;
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.runbook-evidence__name {
	font-weight: bold;
}

.runbook-evidence__meta {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}
</style>
