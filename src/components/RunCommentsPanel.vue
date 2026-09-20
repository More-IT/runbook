<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { RunCommentItem } from '../models/run.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import ConfirmDialog from './ConfirmDialog.vue'
import * as api from '../services/runs.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

interface ScopeOption {
	id: number | null
	title: string
}

const props = defineProps<{
	runId: number
	uid: string
	canComment: boolean
	active: boolean
	steps: Array<{ id: number, title: string }>
}>()

const items = ref<RunCommentItem[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const draft = ref('')
const editingId = ref<number | null>(null)
const editDraft = ref('')
const pendingDelete = ref<number | null>(null)

const scopeOptions = computed<ScopeOption[]>(() => [
	{ id: null, title: t('runbook', 'Whole run') },
	...props.steps.map((step) => ({ id: step.id, title: step.title })),
])
const scope = ref<ScopeOption | null>(scopeOptions.value[0] ?? null)

/**
 * Load the comments of the run.
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		items.value = await api.listComments(props.runId)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.runId, load)

/**
 * Human readable label of the scope a comment belongs to.
 *
 * @param stepId Step identifier or null for run comments.
 */
function scopeLabel(stepId: number | null): string {
	if (stepId === null) {
		return t('runbook', 'Whole run')
	}

	return props.steps.find((step) => step.id === stepId)?.title ?? t('runbook', 'Step {id}', { id: stepId })
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
 * Submit a new comment.
 */
async function submit(): Promise<void> {
	if (draft.value.trim() === '') {
		return
	}

	saving.value = true
	error.value = null
	try {
		await api.createComment(props.runId, { body: draft.value, stepId: scope.value?.id ?? null })
		draft.value = ''
		await load()
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		saving.value = false
	}
}

/**
 * Start editing a comment.
 *
 * @param item Comment to edit.
 */
function beginEdit(item: RunCommentItem): void {
	editingId.value = item.comment.id
	editDraft.value = item.comment.body
}

/**
 * Save the comment being edited.
 */
async function saveEdit(): Promise<void> {
	if (editingId.value === null || editDraft.value.trim() === '') {
		return
	}

	saving.value = true
	error.value = null
	try {
		await api.updateComment(editingId.value, editDraft.value)
		editingId.value = null
		editDraft.value = ''
		await load()
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		saving.value = false
	}
}

/**
 * Delete the pending comment.
 */
async function confirmDelete(): Promise<void> {
	if (pendingDelete.value === null) {
		return
	}

	saving.value = true
	error.value = null
	try {
		await api.deleteComment(pendingDelete.value)
		pendingDelete.value = null
		await load()
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<section class="runbook-comments">
		<h3>{{ t('runbook', 'Comments') }}</h3>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-comments__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<template v-else>
			<p v-if="items.length === 0" class="runbook-comments__hint">
				{{ t('runbook', 'No comments yet.') }}
			</p>

			<div v-for="item in items" :key="item.comment.id" class="runbook-comments__item">
				<div class="runbook-comments__meta">
					<span class="runbook-comments__author">{{ item.authorDisplayName }}</span>
					<span class="runbook-comments__scope">{{ scopeLabel(item.comment.stepId) }}</span>
					<span class="runbook-comments__time">{{ formatDate(item.comment.createdAt) }}</span>
				</div>

				<template v-if="editingId === item.comment.id">
					<NcTextArea v-model="editDraft" :label="t('runbook', 'Comment')" />
					<div class="runbook-comments__actions">
						<NcButton variant="primary" :disabled="saving || editDraft.trim() === ''" @click="saveEdit">
							{{ t('runbook', 'Save comment') }}
						</NcButton>
						<NcButton @click="editingId = null">
							{{ t('runbook', 'Cancel') }}
						</NcButton>
					</div>
				</template>

				<template v-else>
					<p class="runbook-comments__body">
						{{ item.comment.body }}
					</p>
					<p v-if="item.mentions.length > 0" class="runbook-comments__mentions">
						{{ t('runbook', 'Mentions: {mentions}', { mentions: item.mentions.map((mention) => `@${mention.uid}`).join(', ') }) }}
					</p>
					<div v-if="active && canComment && item.comment.authorUid === uid" class="runbook-comments__actions">
						<NcButton variant="tertiary" @click="beginEdit(item)">
							{{ t('runbook', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="pendingDelete = item.comment.id">
							{{ t('runbook', 'Delete') }}
						</NcButton>
					</div>
				</template>
			</div>

			<div v-if="active && canComment" class="runbook-comments__compose">
				<NcSelect
					v-model="scope"
					:options="scopeOptions"
					label="title"
					:clearable="false" />
				<NcTextArea
					v-model="draft"
					:label="t('runbook', 'Comment')"
					:placeholder="t('runbook', 'Mention people with @userid')" />
				<NcButton variant="primary" :disabled="saving || draft.trim() === ''" @click="submit">
					{{ t('runbook', 'Add comment') }}
				</NcButton>
			</div>

			<p v-else-if="!canComment" class="runbook-comments__hint">
				{{ t('runbook', 'You may read comments but not add them.') }}
			</p>
		</template>

		<ConfirmDialog
			v-if="pendingDelete !== null"
			:name="t('runbook', 'Delete comment')"
			:message="t('runbook', 'Deleting a comment cannot be undone.')"
			:confirmLabel="t('runbook', 'Delete')"
			:busy="saving"
			@confirm="confirmDelete"
			@cancel="pendingDelete = null" />
	</section>
</template>

<style scoped>
.runbook-comments {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
}

.runbook-comments__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-comments__center {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.runbook-comments__item {
	border-bottom: 1px solid var(--color-border, #ededed);
	padding: 8px 0;
}

.runbook-comments__meta {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: center;
}

.runbook-comments__author {
	font-weight: bold;
}

.runbook-comments__scope,
.runbook-comments__time {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-comments__body {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	margin: 4px 0;
}

.runbook-comments__mentions {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-comments__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 4px;
}

.runbook-comments__compose {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin-top: 12px;
}
</style>
