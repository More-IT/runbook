<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AdminSettingsBounds, AdminSettingsValues, TemplateCreationPolicy } from '../models/adminSettings.ts'
import type { Principal } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, reactive, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { getAdminSettings, saveAdminSettings } from '../services/adminSettings.ts'
import { searchPrincipals } from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

interface PolicyOption {
	id: TemplateCreationPolicy
	label: string
	description: string
}

const MIB = 1048576

const loading = ref(true)
const saving = ref(false)
const error = ref<string | null>(null)
const saved = ref(false)

const form = reactive<AdminSettingsValues>({
	templateCreationPolicy: 'everyone',
	templateCreatorGroups: [],
	commentsEnabled: true,
	stepReopenEnabled: true,
	requireSkipReason: true,
	runReopenEnabled: true,
	maxAttachmentSize: 26214400,
	dashboardEnabled: true,
	notificationsEnabled: true,
	searchEnabled: true,
	retentionDays: 0,
})

const bounds = ref<AdminSettingsBounds>({
	minAttachmentSize: 1024,
	maxAttachmentSize: 1073741824,
	maxRetentionDays: 36500,
})

const groupOptions = ref<Principal[]>([])
const groupLoading = ref(false)
const selectedGroup = ref<Principal | null>(null)

const policyOptions: PolicyOption[] = [
	{ id: 'everyone', label: t('runbook', 'Everyone'), description: t('runbook', 'Any signed-in user can create templates.') },
	{ id: 'selected_groups', label: t('runbook', 'Selected groups'), description: t('runbook', 'Only members of the selected groups can create templates.') },
	{ id: 'admins', label: t('runbook', 'Administrators only'), description: t('runbook', 'Only administrators can create templates.') },
]

const selectedPolicy = computed<PolicyOption | null>(() => policyOptions.find((option) => option.id === form.templateCreationPolicy) ?? null)

const minAttachmentSizeMiB = computed<number>(() => Math.max(1, Math.ceil(bounds.value.minAttachmentSize / MIB)))
const maxAttachmentSizeMiB = computed<number>(() => Math.floor(bounds.value.maxAttachmentSize / MIB))

const attachmentSizeMiB = computed<number | string>({
	get: () => Math.round(form.maxAttachmentSize / MIB),
	set: (value) => {
		form.maxAttachmentSize = Math.round(Number(value) * MIB)
	},
})

const retentionDaysInput = computed<number | string>({
	get: () => form.retentionDays,
	set: (value) => {
		form.retentionDays = Number(value)
	},
})

/**
 * Load the current settings and bounds.
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		const data = await getAdminSettings()
		apply(data.settings, data.bounds)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

/**
 * Apply a settings payload to the form.
 *
 * @param settings Loaded settings.
 * @param newBounds Validation bounds.
 */
function apply(settings: AdminSettingsValues, newBounds: AdminSettingsBounds): void {
	form.templateCreationPolicy = settings.templateCreationPolicy
	form.templateCreatorGroups = [...settings.templateCreatorGroups]
	form.commentsEnabled = settings.commentsEnabled
	form.stepReopenEnabled = settings.stepReopenEnabled
	form.requireSkipReason = settings.requireSkipReason
	form.runReopenEnabled = settings.runReopenEnabled
	form.maxAttachmentSize = settings.maxAttachmentSize
	form.dashboardEnabled = settings.dashboardEnabled
	form.notificationsEnabled = settings.notificationsEnabled
	form.searchEnabled = settings.searchEnabled
	form.retentionDays = settings.retentionDays
	bounds.value = newBounds
}

onMounted(load)

/**
 * Update the policy when the select changes.
 *
 * @param option Selected policy option.
 */
function onPolicyChange(option: PolicyOption | null): void {
	if (option !== null) {
		form.templateCreationPolicy = option.id
	}
}

/**
 * Search Nextcloud groups to add to the policy.
 *
 * @param query Search term.
 */
async function searchGroups(query: string): Promise<void> {
	if (query.trim() === '') {
		groupOptions.value = []
		return
	}

	groupLoading.value = true
	try {
		const principals = await searchPrincipals(query)
		groupOptions.value = principals.filter((principal) => principal.principalType === 'GROUP')
	} finally {
		groupLoading.value = false
	}
}

/**
 * Add the selected group to the policy list.
 */
function addGroup(): void {
	const id = selectedGroup.value?.principalId
	if (id === undefined || id === '') {
		return
	}
	if (!form.templateCreatorGroups.includes(id)) {
		form.templateCreatorGroups.push(id)
	}
	selectedGroup.value = null
	groupOptions.value = []
}

/**
 * Remove a group from the policy list.
 *
 * @param id Group identifier.
 */
function removeGroup(id: string): void {
	form.templateCreatorGroups = form.templateCreatorGroups.filter((groupId) => groupId !== id)
}

/**
 * Validate and save the settings.
 */
async function save(): Promise<void> {
	error.value = null
	saved.value = false

	if (form.maxAttachmentSize < bounds.value.minAttachmentSize || form.maxAttachmentSize > bounds.value.maxAttachmentSize) {
		error.value = t('runbook', 'The maximum attachment size is out of range.')
		return
	}
	if (!Number.isInteger(form.retentionDays) || form.retentionDays < 0 || form.retentionDays > bounds.value.maxRetentionDays) {
		error.value = t('runbook', 'The retention period is out of range.')
		return
	}

	saving.value = true
	try {
		const payload: AdminSettingsValues = {
			...form,
			templateCreatorGroups: [...form.templateCreatorGroups],
		}
		const data = await saveAdminSettings(payload)
		apply(data.settings, data.bounds)
		saved.value = true
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<div class="runbook-admin-settings">
		<h2>{{ t('runbook', 'Runbook') }}</h2>
		<p class="runbook-admin-settings__intro">
			{{ t('runbook', 'Configure how Runbook behaves for everyone on this instance.') }}
		</p>

		<div v-if="loading" class="runbook-admin-settings__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<template v-else>
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-else-if="saved" type="success">
				{{ t('runbook', 'Runbook settings saved.') }}
			</NcNoteCard>

			<section class="runbook-admin-settings__section">
				<h3>{{ t('runbook', 'Templates') }}</h3>
				<NcSelect
					:modelValue="selectedPolicy"
					:options="policyOptions"
					label="label"
					:clearable="false"
					@update:modelValue="onPolicyChange" />
				<p v-if="selectedPolicy" class="runbook-admin-settings__hint">
					{{ selectedPolicy.description }}
				</p>

				<div v-if="form.templateCreationPolicy === 'selected_groups'" class="runbook-admin-settings__groups">
					<div v-for="group in form.templateCreatorGroups" :key="group" class="runbook-admin-settings__group">
						<span class="runbook-admin-settings__group-name">{{ group }}</span>
						<NcButton variant="error" @click="removeGroup(group)">
							{{ t('runbook', 'Remove') }}
						</NcButton>
					</div>
					<p v-if="form.templateCreatorGroups.length === 0" class="runbook-admin-settings__hint">
						{{ t('runbook', 'No groups selected yet. Only members of these groups can create templates.') }}
					</p>
					<div class="runbook-admin-settings__add-group">
						<NcSelect
							v-model="selectedGroup"
							:options="groupOptions"
							:loading="groupLoading"
							label="displayName"
							:clearable="true"
							:placeholder="t('runbook', 'Search groups')"
							@search="searchGroups" />
						<NcButton :disabled="selectedGroup === null" @click="addGroup">
							{{ t('runbook', 'Add group') }}
						</NcButton>
					</div>
				</div>
			</section>

			<section class="runbook-admin-settings__section">
				<h3>{{ t('runbook', 'Execution') }}</h3>
				<NcCheckboxRadioSwitch v-model="form.commentsEnabled" type="checkbox">
					{{ t('runbook', 'Enable comments') }}
				</NcCheckboxRadioSwitch>
				<p class="runbook-admin-settings__hint">
					{{ t('runbook', 'When disabled, comments cannot be created, edited or deleted. Existing comments stay readable.') }}
				</p>
				<NcCheckboxRadioSwitch v-model="form.stepReopenEnabled" type="checkbox">
					{{ t('runbook', 'Allow reopening steps') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.runReopenEnabled" type="checkbox">
					{{ t('runbook', 'Allow reopening runs') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.requireSkipReason" type="checkbox">
					{{ t('runbook', 'Require a reason when skipping a step') }}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="runbook-admin-settings__section">
				<h3>{{ t('runbook', 'Evidence') }}</h3>
				<NcTextField
					v-model="attachmentSizeMiB"
					type="number"
					:min="minAttachmentSizeMiB"
					:max="maxAttachmentSizeMiB"
					:label="t('runbook', 'Maximum attachment size (MiB)')" />
				<p class="runbook-admin-settings__hint">
					{{ t('runbook', 'Applies to evidence uploads. Allowed range: {min} to {max} MiB.', { min: minAttachmentSizeMiB, max: maxAttachmentSizeMiB }) }}
				</p>
			</section>

			<section class="runbook-admin-settings__section">
				<h3>{{ t('runbook', 'Features') }}</h3>
				<NcCheckboxRadioSwitch v-model="form.dashboardEnabled" type="checkbox">
					{{ t('runbook', 'Enable the Dashboard widget') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.notificationsEnabled" type="checkbox">
					{{ t('runbook', 'Enable notifications') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.searchEnabled" type="checkbox">
					{{ t('runbook', 'Enable Unified Search results') }}
				</NcCheckboxRadioSwitch>
			</section>

			<section class="runbook-admin-settings__section">
				<h3>{{ t('runbook', 'Retention') }}</h3>
				<NcTextField
					v-model="retentionDaysInput"
					type="number"
					:min="0"
					:max="bounds.maxRetentionDays"
					:label="t('runbook', 'Retention period in days (0 = keep forever)')" />
				<NcNoteCard type="warning">
					{{ t('runbook', 'Retention is configuration only. Runbook does not delete runs automatically yet; enforcement is planned for a future release.') }}
				</NcNoteCard>
			</section>

			<div class="runbook-admin-settings__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ saving ? t('runbook', 'Saving…') : t('runbook', 'Save settings') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<style scoped>
.runbook-admin-settings {
	padding: 16px;
	max-width: 720px;
}

.runbook-admin-settings__intro,
.runbook-admin-settings__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-admin-settings__center {
	display: flex;
	justify-content: center;
	padding: 32px;
}

.runbook-admin-settings__section {
	margin-bottom: 24px;
}

.runbook-admin-settings__groups {
	margin-top: 8px;
}

.runbook-admin-settings__group {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.runbook-admin-settings__group-name {
	flex: 1 1 auto;
	font-weight: bold;
	overflow-wrap: anywhere;
}

.runbook-admin-settings__add-group {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
}

.runbook-admin-settings__actions {
	margin-top: 16px;
}
</style>
