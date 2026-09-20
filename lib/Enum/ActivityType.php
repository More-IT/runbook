<?php

declare(strict_types=1);

namespace OCA\Runbook\Enum;

/**
 * Types of append-only activity events.
 *
 * The stored value is a stable machine-readable identifier. User-facing labels
 * are produced in the frontend translation layer.
 */
enum ActivityType: string {
	case RunStarted = 'run_started';
	case RunCompleted = 'run_completed';
	case RunCancelled = 'run_cancelled';
	case RunReopened = 'run_reopened';
	case RunAclChanged = 'run_acl_changed';
	case RunAssignmentChanged = 'run_assignment_changed';
	case SectionNotesUpdated = 'section_notes_updated';
	case StepAssignmentChanged = 'step_assignment_changed';
	case StepStarted = 'step_started';
	case StepResponseUpdated = 'step_response_updated';
	case StepCompleted = 'step_completed';
	case StepSkipped = 'step_skipped';
	case StepReopened = 'step_reopened';
	case CommentAdded = 'comment_added';
	case CommentEdited = 'comment_edited';
	case CommentDeleted = 'comment_deleted';
	case AttachmentUploaded = 'attachment_uploaded';
	case AttachmentDeleted = 'attachment_deleted';

	/**
	 * @return list<string>
	 */
	public static function values(): array {
		return array_map(static fn (self $case): string => $case->value, self::cases());
	}
}
