<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AppFeatures } from '../models/adminSettings.ts'
import type { Template } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ConfirmDialog from '../components/ConfirmDialog.vue'
import TemplateStatusBadge from '../components/TemplateStatusBadge.vue'
import { useTemplateList } from '../composables/useTemplateList.ts'
import { getFeatures } from '../services/adminSettings.ts'

const props = withDefaults(defineProps<{
	archivedOnly?: boolean
}>(), {
	archivedOnly: false,
})

const emit = defineEmits<{
	open: [id: number]
}>()

const { templates, loading, error, refresh, create, remove, duplicate, unarchive } = useTemplateList()

const features = ref<AppFeatures | null>(null)
const showCreate = ref(false)
const createTitle = ref('')
const createDescription = ref('')
const creating = ref(false)
const pendingDelete = ref<Template | null>(null)
const pendingUnarchive = ref<Template | null>(null)
const deleting = ref(false)
const unarchiving = ref(false)
const duplicatingId = ref<number | null>(null)

const visibleTemplates = computed<Template[]>(() => props.archivedOnly
	? templates.value.filter((template) => template.status === 'ARCHIVED')
	: templates.value)

const canCreate = computed<boolean>(() => features.value?.canCreateTemplates ?? true)

/**
 * Whether the current user may duplicate a template.
 *
 * Only the template owner or a Nextcloud administrator may duplicate; the
 * server enforces this again, the UI simply hides the action.
 *
 * @param template Template to check.
 */
function canDuplicate(template: Template): boolean {
	if (features.value === null) {
		return false
	}

	return features.value.uid === template.owner || features.value.isAdmin
}

onMounted(() => {
	void refresh()
	void loadFeatures()
})

/**
 * Load the feature flags that affect template creation.
 */
async function loadFeatures(): Promise<void> {
	try {
		features.value = await getFeatures()
	} catch {
		// Fall back to the default (creation allowed); the server still enforces.
	}
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
 *
 */
async function submitCreate(): Promise<void> {
	creating.value = true
	const template = await create({ title: createTitle.value, description: createDescription.value })
	creating.value = false
	if (template !== null) {
		showCreate.value = false
		createTitle.value = ''
		createDescription.value = ''
		emit('open', template.id)
	}
}

/**
 *
 */
async function confirmDelete(): Promise<void> {
	if (pendingDelete.value === null) {
		return
	}
	deleting.value = true
	const success = await remove(pendingDelete.value.id)
	deleting.value = false
	if (success) {
		pendingDelete.value = null
	}
}

/**
 * Restore the pending archived template.
 */
async function confirmUnarchive(): Promise<void> {
	if (pendingUnarchive.value === null) {
		return
	}
	unarchiving.value = true
	const restored = await unarchive(pendingUnarchive.value.id)
	unarchiving.value = false
	if (restored !== null) {
		pendingUnarchive.value = null
	}
}

/**
 * Duplicate a template and open the copy.
 *
 * @param template Template to duplicate.
 */
async function duplicateTemplate(template: Template): Promise<void> {
	duplicatingId.value = template.id
	const copy = await duplicate(template.id)
	duplicatingId.value = null
	if (copy !== null) {
		emit('open', copy.id)
	}
}
</script>

<template>
	<section class="runbook-view">
		<header class="runbook-view__header">
			<h2>{{ archivedOnly ? t('runbook', 'Archived templates') : t('runbook', 'Templates') }}</h2>
			<NcButton v-if="canCreate" variant="primary" @click="showCreate = true">
				{{ t('runbook', 'New template') }}
			</NcButton>
		</header>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<NcNoteCard v-if="!canCreate" type="info">
			{{ t('runbook', 'Only certain users may create templates. Contact your administrator if you need to create one.') }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-view__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<NcEmptyContent
			v-else-if="visibleTemplates.length === 0"
			:name="archivedOnly ? t('runbook', 'No archived templates') : t('runbook', 'No templates yet')"
			:description="t('runbook', 'Create your first runbook template to get started.')">
			<template v-if="canCreate" #action>
				<NcButton variant="primary" @click="showCreate = true">
					{{ t('runbook', 'New template') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<ul v-else class="runbook-list">
			<li v-for="template in visibleTemplates" :key="template.id" class="runbook-list__item">
				<div class="runbook-list__main">
					<button type="button" class="runbook-list__title" @click="emit('open', template.id)">
						{{ template.title || t('runbook', 'Untitled template') }}
					</button>
					<div class="runbook-list__meta">
						<TemplateStatusBadge :status="template.status" />
						<span>v{{ template.version }}</span>
						<span>{{ formatDate(template.updatedAt) }}</span>
					</div>
				</div>
				<div class="runbook-list__actions">
					<NcButton @click="emit('open', template.id)">
						{{ t('runbook', 'Open') }}
					</NcButton>
					<NcButton v-if="canDuplicate(template)" :disabled="duplicatingId === template.id" @click="duplicateTemplate(template)">
						{{ t('runbook', 'Duplicate') }}
					</NcButton>
					<NcButton v-if="template.status === 'ARCHIVED'" @click="pendingUnarchive = template">
						{{ t('runbook', 'Unarchive') }}
					</NcButton>
					<NcButton variant="error" @click="pendingDelete = template">
						{{ t('runbook', 'Delete') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<NcDialog v-if="showCreate" :name="t('runbook', 'New template')" @closing="showCreate = false">
			<NcTextField v-model="createTitle" :label="t('runbook', 'Title')" />
			<NcTextArea v-model="createDescription" :label="t('runbook', 'Description')" />
			<template #actions>
				<NcButton @click="showCreate = false">
					{{ t('runbook', 'Cancel') }}
				</NcButton>
				<NcButton variant="primary" :disabled="creating" @click="submitCreate">
					{{ t('runbook', 'Create') }}
				</NcButton>
			</template>
		</NcDialog>

		<ConfirmDialog
			v-if="pendingDelete !== null"
			:name="t('runbook', 'Delete template')"
			:message="t('runbook', 'This permanently deletes the template, its sections and its steps.')"
			:confirmLabel="t('runbook', 'Delete')"
			:busy="deleting"
			@confirm="confirmDelete"
			@cancel="pendingDelete = null" />

		<ConfirmDialog
			v-if="pendingUnarchive !== null"
			:name="t('runbook', 'Unarchive template')"
			:message="t('runbook', 'Restore this template to the active list. New runs can be started once it is published.')"
			:confirmLabel="t('runbook', 'Unarchive')"
			:busy="unarchiving"
			@confirm="confirmUnarchive"
			@cancel="pendingUnarchive = null" />
	</section>
</template>

<style scoped>
.runbook-view {
	padding: 16px;
}

.runbook-view__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-bottom: 16px;
}

.runbook-view__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.runbook-list__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	padding: 12px;
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	margin-bottom: 8px;
}

.runbook-list__main {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.runbook-list__title {
	background: none;
	border: none;
	padding: 0;
	font-size: 1.1em;
	font-weight: bold;
	cursor: pointer;
	text-align: left;
}

.runbook-list__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-list__actions {
	display: flex;
	gap: 4px;
}
</style>
