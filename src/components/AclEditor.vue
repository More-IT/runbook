<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type {
	AclEntryPayload,
	AssignableAclRole,
	Principal,
	TemplateAclEntry,
} from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AclEntryRow from './AclEntryRow.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { ASSIGNABLE_ACL_ROLES } from '../models/template.ts'
import * as api from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

interface RoleOption {
	id: AssignableAclRole
	label: string
}

const props = defineProps<{
	templateId: number
	owner: string
	readOnly?: boolean
}>()

const entries = ref<AclEntryPayload[]>([])
const originalPrincipalKeys = ref<string[]>([])
const originalSignatures = ref<string[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)

const principalOptions = ref<Principal[]>([])
const principalLoading = ref(false)
const selectedPrincipal = ref<Principal | null>(null)
const selectedRole = ref<RoleOption | null>(null)
const showConfirm = ref(false)

/**
 * Build a signature used to detect unsaved ACL changes.
 *
 * @param entry ACL entry payload.
 */
function signature(entry: AclEntryPayload): string {
	return `${keyOf(entry)}:${entry.role}`
}

/**
 * Human readable label for an assignable role.
 *
 * @param role Assignable ACL role.
 */
function roleLabel(role: AssignableAclRole): string {
	switch (role) {
		case 'EXECUTOR':
			return t('runbook', 'Executor')
		case 'EDITOR':
			return t('runbook', 'Editor')
		default:
			return t('runbook', 'Viewer')
	}
}

const roleOptions: RoleOption[] = ASSIGNABLE_ACL_ROLES.map((role) => ({ id: role, label: roleLabel(role) }))
selectedRole.value = roleOptions[0] ?? null

/**
 * Unique key of a principal.
 *
 * @param entry Entry carrying a principal type and identifier.
 */
function keyOf(entry: AclEntryPayload): string {
	return `${entry.principalType}:${entry.principalId}`
}

/**
 * Convert an ACL response entry into an editable payload.
 *
 * @param entry ACL entry returned by the API.
 */
function toPayload(entry: TemplateAclEntry): AclEntryPayload {
	return {
		principalType: entry.principalType,
		principalId: entry.principalId,
		role: entry.role === 'OWNER' ? 'VIEWER' : entry.role,
	}
}

/**
 * Apply ACL entries returned by the API to the local state.
 *
 * @param aclEntries ACL entries returned by the API.
 */
function applyEntries(aclEntries: TemplateAclEntry[]): void {
	entries.value = aclEntries.map(toPayload)
	originalPrincipalKeys.value = entries.value.map(keyOf)
	originalSignatures.value = entries.value.map(signature)
}

const dirty = computed<boolean>(() => {
	const current = entries.value.map(signature).slice().sort()
	const original = originalSignatures.value.slice().sort()
	if (current.length !== original.length) {
		return true
	}

	return current.some((value, index) => value !== original[index])
})

const removesExisting = computed<boolean>(() => originalPrincipalKeys.value
	.some((key) => !entries.value.some((entry) => keyOf(entry) === key)))

/**
 *
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		const acl = await api.getTemplateAcl(props.templateId)
		applyEntries(acl.entries)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.templateId, load)

/**
 * Search users and groups through the backend principal search.
 *
 * @param query Search term.
 */
async function searchPrincipals(query: string): Promise<void> {
	if (query.trim() === '') {
		principalOptions.value = []
		return
	}

	principalLoading.value = true
	try {
		principalOptions.value = await api.searchPrincipals(query)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		principalLoading.value = false
	}
}

/**
 *
 */
function addEntry(): void {
	if (selectedPrincipal.value === null || selectedRole.value === null) {
		return
	}

	const candidate: AclEntryPayload = {
		principalType: selectedPrincipal.value.principalType,
		principalId: selectedPrincipal.value.principalId,
		role: selectedRole.value.id,
	}

	if (entries.value.some((entry) => keyOf(entry) === keyOf(candidate))) {
		error.value = t('runbook', 'This user or group already has access.')
		return
	}

	entries.value.push(candidate)
	selectedPrincipal.value = null
	error.value = null
}

/**
 * Change the role of a pending entry.
 *
 * @param index Entry index.
 * @param role New role.
 */
function updateEntry(index: number, role: AssignableAclRole): void {
	const entry = entries.value[index]
	if (entry !== undefined) {
		entries.value[index] = { ...entry, role }
	}
}

/**
 * Remove a pending entry.
 *
 * @param index Entry index.
 */
function removeEntry(index: number): void {
	entries.value.splice(index, 1)
}

/**
 *
 */
function save(): void {
	if (removesExisting.value) {
		showConfirm.value = true
		return
	}

	void persist()
}

/**
 *
 */
async function persist(): Promise<void> {
	showConfirm.value = false
	saving.value = true
	error.value = null
	try {
		const acl = await api.replaceTemplateAcl(props.templateId, entries.value)
		applyEntries(acl.entries)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		saving.value = false
	}
}
</script>

<template>
	<section class="runbook-acl">
		<h3>{{ t('runbook', 'Access') }}</h3>
		<p class="runbook-acl__hint">
			{{ t('runbook', 'Share this template with individual users or whole groups.') }}
		</p>

		<div class="runbook-acl__owner">
			<span class="runbook-acl__owner-label">{{ t('runbook', 'Owner') }}</span>
			<span class="runbook-acl__owner-id">{{ owner }}</span>
			<span class="runbook-acl__owner-role">{{ t('runbook', 'Owner') }}</span>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-acl__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<template v-else>
			<p v-if="entries.length === 0" class="runbook-acl__hint">
				{{ t('runbook', 'Only you currently have access to this template.') }}
			</p>

			<AclEntryRow
				v-for="(entry, index) in entries"
				:key="`${entry.principalType}:${entry.principalId}`"
				:entry="entry"
				:readOnly="readOnly ?? false"
				@update="(role) => updateEntry(index, role)"
				@remove="removeEntry(index)" />

			<div v-if="!(readOnly ?? false)" class="runbook-acl__add">
				<div class="runbook-acl__add-principal">
					<NcSelect
						v-model="selectedPrincipal"
						:options="principalOptions"
						:loading="principalLoading"
						label="displayName"
						:clearable="true"
						:placeholder="t('runbook', 'Search users and groups')"
						@search="searchPrincipals" />
				</div>
				<div class="runbook-acl__add-role">
					<NcSelect
						v-model="selectedRole"
						:options="roleOptions"
						label="label"
						:clearable="false" />
				</div>
				<NcButton :disabled="selectedPrincipal === null" @click="addEntry">
					{{ t('runbook', 'Add access') }}
				</NcButton>
			</div>

			<div v-if="!(readOnly ?? false)" class="runbook-acl__actions">
				<NcButton variant="primary" :disabled="!dirty || saving" @click="save">
					{{ t('runbook', 'Save access') }}
				</NcButton>
			</div>
		</template>

		<ConfirmDialog
			v-if="showConfirm"
			:name="t('runbook', 'Replace access')"
			:message="t('runbook', 'Saving will replace the access list and revoke access from removed users or groups.')"
			:confirmLabel="t('runbook', 'Save access')"
			:busy="saving"
			@confirm="persist"
			@cancel="showConfirm = false" />
	</section>
</template>

<style scoped>
.runbook-acl {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
}

.runbook-acl__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-acl__owner {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border, #ededed);
	margin-bottom: 8px;
}

.runbook-acl__owner-label {
	font-weight: bold;
}

.runbook-acl__owner-id {
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.runbook-acl__owner-role {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-acl__center {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.runbook-acl__add {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.runbook-acl__add-principal {
	flex: 1 1 220px;
}

.runbook-acl__add-role {
	flex: 0 0 160px;
}

.runbook-acl__actions {
	margin-top: 12px;
}
</style>
