<!--
  - SPDX-FileCopyrightText: 2026 More-IT
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { AssignableRunAclRole, RunAclEntry, RunAclEntryPayload } from '../models/run.ts'
import type { Principal, PrincipalType } from '../models/template.ts'

import { translate as t } from '@nextcloud/l10n'
import { computed, onMounted, ref, watch } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import ConfirmDialog from './ConfirmDialog.vue'
import { ASSIGNABLE_RUN_ACL_ROLES } from '../models/run.ts'
import * as api from '../services/runs.ts'
import { searchPrincipals } from '../services/templates.ts'
import { apiErrorMessage } from '../utils/apiError.ts'

interface RoleOption {
	id: AssignableRunAclRole
	label: string
}

const props = defineProps<{
	runId: number
	owner: string
	readOnly?: boolean
}>()

const entries = ref<RunAclEntryPayload[]>([])
const originalKeys = ref<string[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const showConfirm = ref(false)

const principalOptions = ref<Principal[]>([])
const principalLoading = ref(false)
const selectedPrincipal = ref<Principal | null>(null)
const selectedRole = ref<RoleOption | null>(null)

/**
 * Human readable label for an assignable run role.
 *
 * @param role Assignable run role.
 */
function roleLabel(role: AssignableRunAclRole): string {
	return role === 'PARTICIPANT' ? t('runbook', 'Participant') : t('runbook', 'Viewer')
}

const roleOptions: RoleOption[] = ASSIGNABLE_RUN_ACL_ROLES.map((role) => ({ id: role, label: roleLabel(role) }))
selectedRole.value = roleOptions[0] ?? null

/**
 * Unique key of a principal.
 *
 * @param entry Entry carrying a principal type and identifier.
 */
function keyOf(entry: RunAclEntryPayload): string {
	return `${entry.principalType}:${entry.principalId}`
}

/**
 * Convert an ACL response entry into an editable payload.
 *
 * @param entry ACL entry returned by the API.
 */
function toPayload(entry: RunAclEntry): RunAclEntryPayload {
	return {
		principalType: entry.principalType,
		principalId: entry.principalId,
		role: entry.role === 'OWNER' ? 'PARTICIPANT' : entry.role,
	}
}

/**
 * @param aclEntries ACL entries returned by the API.
 */
function applyEntries(aclEntries: RunAclEntry[]): void {
	entries.value = aclEntries.map(toPayload)
	originalKeys.value = entries.value.map(keyOf)
}

const dirty = computed<boolean>(() => {
	const current = entries.value.map((entry) => `${keyOf(entry)}:${entry.role}`).slice().sort()
	const original = originalKeys.value.slice().sort()
	if (current.length !== original.length) {
		return true
	}

	return current.some((value, index) => value !== original[index])
})

const removesExisting = computed<boolean>(() => originalKeys.value
	.some((key) => !entries.value.some((entry) => keyOf(entry) === key)))

/**
 *
 */
async function load(): Promise<void> {
	loading.value = true
	error.value = null
	try {
		const acl = await api.getRunAcl(props.runId)
		applyEntries(acl.entries)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		loading.value = false
	}
}

onMounted(load)
watch(() => props.runId, load)

/**
 * Search users and groups through the backend principal search.
 *
 * @param query Search term.
 */
async function search(query: string): Promise<void> {
	if (query.trim() === '') {
		principalOptions.value = []
		return
	}

	principalLoading.value = true
	try {
		principalOptions.value = await searchPrincipals(query)
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

	const candidate: RunAclEntryPayload = {
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
		const acl = await api.replaceRunAcl(props.runId, entries.value)
		applyEntries(acl.entries)
	} catch (caught) {
		error.value = apiErrorMessage(caught)
	} finally {
		saving.value = false
	}
}

/**
 * Human readable label for a role of a stored entry.
 *
 * @param type Principal type.
 */
function typeLabel(type: PrincipalType): string {
	return type === 'GROUP' ? t('runbook', 'Group') : t('runbook', 'User')
}
</script>

<template>
	<section class="runbook-run-acl">
		<h3>{{ t('runbook', 'Participants') }}</h3>
		<p class="runbook-run-acl__hint">
			{{ t('runbook', 'Participants can view the run and their assigned work.') }}
		</p>

		<div class="runbook-run-acl__owner">
			<span class="runbook-run-acl__owner-label">{{ t('runbook', 'Owner') }}</span>
			<span class="runbook-run-acl__owner-id">{{ owner }}</span>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="loading" class="runbook-run-acl__center">
			<NcLoadingIcon :name="t('runbook', 'Loading')" />
		</div>

		<template v-else>
			<p v-if="entries.length === 0" class="runbook-run-acl__hint">
				{{ t('runbook', 'No participants yet.') }}
			</p>

			<div v-for="(entry, index) in entries" :key="`${entry.principalType}:${entry.principalId}`" class="runbook-run-acl__row">
				<span class="runbook-run-acl__principal">{{ entry.principalId }}</span>
				<span class="runbook-run-acl__type">{{ typeLabel(entry.principalType) }}</span>
				<span class="runbook-run-acl__role">{{ roleLabel(entry.role) }}</span>
				<NcButton v-if="!(readOnly ?? false)" variant="error" @click="removeEntry(index)">
					{{ t('runbook', 'Remove') }}
				</NcButton>
			</div>

			<div v-if="!(readOnly ?? false)" class="runbook-run-acl__add">
				<div class="runbook-run-acl__add-principal">
					<NcSelect
						v-model="selectedPrincipal"
						:options="principalOptions"
						:loading="principalLoading"
						label="displayName"
						:clearable="true"
						:placeholder="t('runbook', 'Search users and groups')"
						@search="search" />
				</div>
				<div class="runbook-run-acl__add-role">
					<NcSelect
						v-model="selectedRole"
						:options="roleOptions"
						label="label"
						:clearable="false" />
				</div>
				<NcButton :disabled="selectedPrincipal === null" @click="addEntry">
					{{ t('runbook', 'Add participant') }}
				</NcButton>
			</div>

			<div v-if="!(readOnly ?? false)" class="runbook-run-acl__actions">
				<NcButton variant="primary" :disabled="!dirty || saving" @click="save">
					{{ t('runbook', 'Save participants') }}
				</NcButton>
			</div>
		</template>

		<ConfirmDialog
			v-if="showConfirm"
			:name="t('runbook', 'Replace participants')"
			:message="t('runbook', 'Saving will replace the participant list and revoke access from removed users or groups.')"
			:confirmLabel="t('runbook', 'Save participants')"
			:busy="saving"
			@confirm="persist"
			@cancel="showConfirm = false" />
	</section>
</template>

<style scoped>
.runbook-run-acl {
	border: 1px solid var(--color-border, #ededed);
	border-radius: var(--border-radius, 4px);
	padding: 12px;
	margin-bottom: 24px;
}

.runbook-run-acl__hint {
	color: var(--color-text-maxcontrast, #555);
}

.runbook-run-acl__owner {
	display: flex;
	align-items: center;
	gap: 8px;
	border-bottom: 1px solid var(--color-border, #ededed);
	padding-bottom: 6px;
	margin-bottom: 8px;
}

.runbook-run-acl__owner-label {
	font-weight: bold;
}

.runbook-run-acl__owner-id {
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.runbook-run-acl__center {
	display: flex;
	justify-content: center;
	padding: 16px;
}

.runbook-run-acl__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 8px;
	padding: 4px 0;
}

.runbook-run-acl__principal {
	font-weight: bold;
	flex: 1 1 auto;
	overflow-wrap: anywhere;
}

.runbook-run-acl__type,
.runbook-run-acl__role {
	color: var(--color-text-maxcontrast, #555);
	font-size: 0.85em;
}

.runbook-run-acl__add {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-end;
	gap: 8px;
	margin-top: 12px;
}

.runbook-run-acl__add-principal {
	flex: 1 1 220px;
}

.runbook-run-acl__add-role {
	flex: 0 0 160px;
}

.runbook-run-acl__actions {
	margin-top: 12px;
}
</style>
