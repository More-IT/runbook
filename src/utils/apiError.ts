/**
 * SPDX-FileCopyrightText: 2026 More-IT
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

interface ApiErrorBody {
	error?: string
	reason?: string
	message?: string
}

const REASON_MESSAGES: Record<string, string> = {
	template_title_required: t('runbook', 'A template needs a title before it can be published.'),
	title_required: t('runbook', 'A title is required.'),
	title_too_long: t('runbook', 'The title is too long.'),
	description_too_long: t('runbook', 'The description is too long.'),
	notes_too_long: t('runbook', 'The notes are too long.'),
	section_title_required: t('runbook', 'A section needs a title.'),
	step_title_required: t('runbook', 'A step needs a title.'),
	invalid_step_type: t('runbook', 'The selected step type is not supported.'),
	step_type_required: t('runbook', 'A step type is required.'),
	select_options_required: t('runbook', 'A selection step needs at least one option.'),
	invalid_select_option: t('runbook', 'The selection is invalid.'),
	invalid_unit: t('runbook', 'The unit is invalid.'),
	config_too_large: t('runbook', 'The step configuration is too large.'),
	template_archived: t('runbook', 'Archived templates cannot be edited.'),
	template_already_archived: t('runbook', 'This template is already archived.'),
	template_not_archived: t('runbook', 'This template is not archived.'),
	not_owner: t('runbook', 'You are not allowed to perform this action.'),
	not_allowed: t('runbook', 'You are not allowed to perform this action.'),
	section_dependency_cycle: t('runbook', 'Sections cannot depend on each other in a cycle.'),
	section_dependency_unknown: t('runbook', 'A selected prerequisite section does not exist.'),
	section_self_dependency: t('runbook', 'A section cannot depend on itself.'),
	invalid_condition: t('runbook', 'The condition is invalid.'),
	invalid_condition_operator: t('runbook', 'The condition operator is not valid for this step.'),
	invalid_condition_value: t('runbook', 'The condition value is not valid for this step.'),
	condition_step_not_found: t('runbook', 'The controlling step was not found.'),
	condition_references_own_section: t('runbook', 'A section cannot use a condition on one of its own steps.'),
	duplicate_section_condition: t('runbook', 'Two sections cannot share the same condition.'),
	conflicting_section_conditions: t('runbook', 'This section contains conditions that can never both be true.'),
	section_not_available: t('runbook', 'This section is not available yet.'),
	return_reason_required: t('runbook', 'A return reason is required.'),
	return_reason_too_long: t('runbook', 'The return reason is too long.'),
	section_has_no_resolved_steps: t('runbook', 'This section has no resolved steps to return.'),
	unknown_user: t('runbook', 'The selected user does not exist.'),
	unknown_group: t('runbook', 'The selected group does not exist.'),
	duplicate_principal: t('runbook', 'The same user or group was added more than once.'),
	owner_role_not_allowed: t('runbook', 'Ownership cannot be assigned through access control.'),
	invalid_principal_type: t('runbook', 'The selected principal type is not supported.'),
	invalid_principal_id: t('runbook', 'The selected user or group is invalid.'),
	empty_principal_id: t('runbook', 'Select a user or group first.'),
	invalid_role: t('runbook', 'The selected access role is not supported.'),
	invalid_acl_entries: t('runbook', 'The access list is invalid.'),
	invalid_acl_entry: t('runbook', 'The access list is invalid.'),
	invalid_due_offset: t('runbook', 'The due offset must be a non-negative number of minutes.'),
	due_date_in_past: t('runbook', 'The due date must be in the future.'),
	step_due_after_run_due: t('runbook', 'A step due date cannot be later than the run due date.'),
	invalid_filter: t('runbook', 'The selected filter is invalid.'),
	template_not_published: t('runbook', 'Only published templates can be used to start a run.'),
	run_title_required: t('runbook', 'A run needs a title.'),
	run_not_found: t('runbook', 'The run was not found.'),
	run_delete_blocked: t('runbook', 'The run could not be deleted because some evidence files cannot be removed. Fix them and try again.'),
	run_not_active: t('runbook', 'This run is read-only.'),
	run_not_completed: t('runbook', 'Only completed runs can be reopened.'),
	required_steps_unresolved: t('runbook', 'Some required steps are still unresolved.'),
	invalid_step_transition: t('runbook', 'This step action is not allowed in the current state.'),
	run_step_not_found: t('runbook', 'The run step was not found.'),
	run_section_not_found: t('runbook', 'The run section was not found.'),
	response_required: t('runbook', 'A response is required before this step can be completed.'),
	invalid_boolean_response: t('runbook', 'This step expects a yes or no response.'),
	invalid_text_response: t('runbook', 'This step expects a text response.'),
	invalid_number_response: t('runbook', 'This step expects a numeric response.'),
	invalid_select_response: t('runbook', 'This step expects one of the configured options.'),
	invalid_date_response: t('runbook', 'This step expects a valid date.'),
	invalid_user_response: t('runbook', 'This step expects a valid Nextcloud user.'),
	response_too_long: t('runbook', 'The response is too long.'),
	skip_reason_required: t('runbook', 'A skip reason is required.'),
	skip_reason_too_long: t('runbook', 'The skip reason is too long.'),
	file_upload_not_supported: t('runbook', 'This step does not accept a response value; attach evidence instead.'),
	invalid_file_response: t('runbook', 'This step does not accept a response value; attach evidence instead.'),
	file_evidence_required: t('runbook', 'Attach at least one file before completing this step.'),
	file_evidence_missing: t('runbook', 'The attached evidence is no longer available in Files. Upload a replacement before completing this step.'),
	last_file_evidence_required: t('runbook', 'This step must keep at least one attached file. Upload a replacement before deleting the last one.'),
	evidence_locked: t('runbook', 'This step is busy. Please try again.'),
	destination_owner_missing: t('runbook', 'The Files destination is not available for this account.'),
	destination_invalid: t('runbook', 'The configured Files destination is not a folder.'),
	destination_no_access: t('runbook', 'You do not have access to the configured Files destination.'),
	destination_not_writable: t('runbook', 'The Files destination is not writable.'),
	destination_unavailable: t('runbook', 'The configured Files destination is not available.'),
	destination_ambiguous: t('runbook', 'The Files destination is ambiguous. Use a folder that is shared from a single location.'),
	destination_quota_exceeded: t('runbook', 'There is not enough storage space to create the run folder.'),
	destination_locked: t('runbook', 'The Files destination is busy. Please try again.'),
	destination_invalid_name: t('runbook', 'The run folder name is not allowed in this destination.'),
	destination_invalid_config: t('runbook', 'The configured Files destination is incomplete. Select the folder again.'),
	destination_ownership_conflict: t('runbook', 'A folder with this run\'s name already exists but is not owned by this run. Rename or remove it, then try again.'),
	not_authenticated: t('runbook', 'You need to be signed in.'),
	template_not_found: t('runbook', 'The template was not found.'),
	section_not_found: t('runbook', 'The section was not found.'),
	step_not_found: t('runbook', 'The step was not found.'),
	invalid_position: t('runbook', 'The requested position is invalid.'),
	invalid_field: t('runbook', 'Some of the submitted data is invalid.'),
	invalid_id: t('runbook', 'The submitted identifier is invalid.'),
	template_creation_forbidden: t('runbook', 'You are not allowed to create templates.'),
	comments_disabled: t('runbook', 'Comments are disabled by your administrator.'),
	step_reopen_disabled: t('runbook', 'Reopening steps is disabled by your administrator.'),
	run_reopen_disabled: t('runbook', 'Reopening runs is disabled by your administrator.'),
	attachment_too_large: t('runbook', 'The file is larger than the allowed attachment size.'),
	attachment_missing: t('runbook', 'This evidence file is no longer available in Files.'),
	attachment_out_of_scope: t('runbook', 'This evidence file is no longer inside the run folder and cannot be changed here.'),
	attachment_invalid_filename: t('runbook', 'This file name is not allowed. Rename the file and try again.'),
	attachment_name_collision: t('runbook', 'The file could not be saved because its name is already taken. Rename the file and try again.'),
	attachment_source_required: t('runbook', 'Select a file in Files to copy.'),
	attachment_source_invalid: t('runbook', 'The selected item is not a file that can be copied.'),
	attachment_source_missing: t('runbook', 'The selected file is no longer available in Files.'),
	attachment_source_no_access: t('runbook', 'You do not have permission to read the selected file.'),
	attachment_source_ambiguous: t('runbook', 'The selected file is ambiguous. Choose a file that is shared from a single location.'),
	invalid_attachment_size: t('runbook', 'The maximum attachment size is out of range.'),
	invalid_retention_days: t('runbook', 'The retention period is out of range.'),
	invalid_template_creation_policy: t('runbook', 'The selected template creation policy is not supported.'),
	invalid_template_creator_groups: t('runbook', 'The selected group list is invalid.'),
	too_many_template_creator_groups: t('runbook', 'Too many groups were selected.'),
	invalid_boolean: t('runbook', 'A boolean setting has an invalid value.'),
	export_reference_unresolved: t('runbook', 'This template cannot be exported because a referenced section or step is missing or inconsistent.'),
	invalid_import_format: t('runbook', 'This file is not a Runbook template export.'),
	unsupported_import_schema_version: t('runbook', 'This template file uses an unsupported schema version.'),
	invalid_import_document: t('runbook', 'This template file is malformed and cannot be imported.'),
	invalid_import_reference: t('runbook', 'This template refers to a section or step that does not exist in the file.'),
	duplicate_import_reference: t('runbook', 'This template reuses the same section or step reference more than once.'),
	invalid_import_assignee: t('runbook', 'A default assignee in this template does not exist on this instance. Edit the file to set it to null and try again.'),
	import_document_too_large: t('runbook', 'This template file is too large to import.'),
	import_too_many_sections: t('runbook', 'This template has too many sections to import.'),
	import_too_many_steps: t('runbook', 'This template has too many steps to import.'),
	import_too_many_dependencies: t('runbook', 'This template has too many section dependencies to import.'),
	import_too_many_conditions: t('runbook', 'This template has too many conditions to import.'),
}

/**
 * Extract the error body from an unknown thrown value.
 *
 * @param error Unknown value thrown by the API client.
 */
function extractBody(error: unknown): ApiErrorBody | undefined {
	if (typeof error !== 'object' || error === null) {
		return undefined
	}

	const candidate = error as { response?: { data?: unknown } }
	const data = candidate.response?.data
	if (typeof data !== 'object' || data === null) {
		return undefined
	}

	return data as ApiErrorBody
}

/**
 * Turn an unknown API error into a translated, user-facing message.
 *
 * @param error Unknown value thrown by the API client.
 */
export function apiErrorMessage(error: unknown): string {
	const body = extractBody(error)
	if (body?.reason !== undefined) {
		const known = REASON_MESSAGES[body.reason]
		if (known !== undefined) {
			return known
		}
	}

	if (body?.message !== undefined && body.message !== '') {
		return body.message
	}

	return t('runbook', 'Something went wrong. Please try again.')
}
