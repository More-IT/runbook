<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;
use OCP\Files\File;
use OCP\Files\Folder;

class AttachmentServiceTest extends RunTestBase {
	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep, 2: \OCP\Files\Folder}
	 */
	private function runWithStep(string $owner, string $assigneeId): array {
		[$run, $folder] = $this->addFilesRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, $assigneeId);

		return [$run, $step, $folder];
	}

	/**
	 * @return list<ActivityEvent>
	 */
	private function activitiesFor(int $runId): array {
		return array_values(array_filter(
			$this->activityEvents,
			static fn (ActivityEvent $event): bool => $event->getRunId() === $runId,
		));
	}

	public function testAssignedUserCanUploadEvidence(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step, $folder] = $this->runWithStep('alice', 'bob');

		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), [
			'name' => 'evidence.txt',
			'content' => 'hello',
		]);

		self::assertSame('evidence.txt', $attachment->getFilename());
		self::assertSame('text/plain', $attachment->getMimeType());
		self::assertSame(5, $attachment->getSize());
		self::assertSame(hash('sha256', 'hello'), $attachment->getChecksum());
		self::assertSame($run->getId(), $attachment->getRunId());
		self::assertSame($step->getId(), $attachment->getStepId());
		self::assertSame(Attachment::STORAGE_KIND_FILES, $attachment->getStorageKind());
		self::assertArrayNotHasKey('storageKey', $attachment->toArray());
		self::assertCount(1, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles, 'new evidence must never be written to AppData');
		self::assertSame(ActivityType::AttachmentUploaded->value, $this->activitiesFor($run->getId())[0]->getEventType());
	}

	public function testNonExecutorCannotUpload(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		[$run, $step] = $this->runWithStep('alice', 'bob');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Viewer->value);

		$this->expectException(ForbiddenException::class);
		$this->attachmentServiceFor('carol')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
	}

	public function testUploadToUnknownStepIsRejected(): void {
		$this->addUser('alice');

		$this->expectException(NotFoundException::class);
		$this->attachmentServiceFor('alice')->upload(999, ['name' => 'e.txt', 'content' => 'x']);
	}

	public function testEmptyUploadIsRejected(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');

		$this->expectException(ValidationException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => '']);
	}

	public function testOversizedUploadIsRejected(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');

		$this->expectException(ValidationException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), [
			'name' => 'big.bin',
			'content' => str_repeat('a', AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE + 1),
		]);
	}

	public function testConfiguredAttachmentSizeIsEnforced(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->setAppConfig(AdminSettings::KEY_MAX_ATTACHMENT_SIZE, 2048);

		$this->expectException(ValidationException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), [
			'name' => 'e.txt',
			'content' => str_repeat('a', 2049),
		]);
	}

	public function testUploadWithinConfiguredSizeSucceeds(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->setAppConfig(AdminSettings::KEY_MAX_ATTACHMENT_SIZE, 2048);

		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), [
			'name' => 'e.txt',
			'content' => str_repeat('a', 1000),
		]);

		self::assertSame(1000, $attachment->getSize());
	}

	public function testDisallowedMimeTypeIsRejectedAndNothingStored(): void {
		$this->addUser('alice');
		[$run, $step, $folder] = $this->runWithStep('alice', 'alice');
		$this->forcedMimeType = 'application/x-msdownload';

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.exe', 'content' => 'MZ']);
			self::fail('Expected validation failure');
		} catch (ValidationException) {
		}

		self::assertSame([], $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles);
	}

	public function testInvalidFilenameIsRejected(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');

		$this->expectException(ValidationException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => '../evil.txt', 'content' => 'x']);
	}

	public function testListForRunRequiresAccess(): void {
		$this->addUser('alice');
		$this->addUser('stranger');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);

		self::assertCount(1, $this->attachmentServiceFor('alice')->listForRun($run->getId()));

		$this->expectException(NotFoundException::class);
		$this->attachmentServiceFor('stranger')->listForRun($run->getId());
	}

	public function testDownloadReturnsStoredContent(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'payload']);

		$result = $this->attachmentServiceFor('alice')->download($attachment->getId());

		self::assertSame('payload', $result['content']);
		self::assertSame('e.txt', $result['attachment']->getFilename());
	}

	public function testDownloadRejectsUnrelatedUser(): void {
		$this->addUser('alice');
		$this->addUser('stranger');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'payload']);

		$this->expectException(NotFoundException::class);
		$this->attachmentServiceFor('stranger')->download($attachment->getId());
	}

	public function testUploaderCanDeleteOwnEvidence(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step, $folder] = $this->runWithStep('alice', 'bob');
		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);

		$this->attachmentServiceFor('bob')->delete($attachment->getId());

		self::assertSame([], $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles);
		self::assertSame(ActivityType::AttachmentDeleted->value, $this->activitiesFor($run->getId())[1]->getEventType());
	}

	public function testOwnerCanDeleteOthersEvidenceButParticipantCannot(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('carol');
		[$run, $step] = $this->runWithStep('alice', 'bob');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Participant->value);
		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);

		$this->expectException(ForbiddenException::class);
		$this->attachmentServiceFor('carol')->delete($attachment->getId());
	}

	public function testCannotUploadToStepOfInactiveRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');

		$this->expectException(ConflictException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
	}

	public function testCannotUploadToCancelledRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Cancelled->value);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');

		$this->expectException(ConflictException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
	}

	public function testMetadataFailureCleansUpStoredFile(): void {
		$this->addUser('alice');
		[$run, $step, $folder] = $this->runWithStep('alice', 'alice');
		$this->failAttachmentInsert = true;

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
			self::fail('Expected metadata insert failure');
		} catch (\OCP\DB\Exception) {
		}

		self::assertSame([], $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing(), 'Orphan file must be removed when metadata insert fails');
		self::assertSame([], $this->evidenceFiles);
	}

	public function testRepeatedDeletionIsRejected(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);

		$this->attachmentServiceFor('alice')->delete($attachment->getId());

		$this->expectException(NotFoundException::class);
		$this->attachmentServiceFor('alice')->delete($attachment->getId());
	}

	public function testDownloadWithMissingStoredFileFailsSafely(): void {
		$this->addUser('alice');
		// A pre-existing AppData attachment (compatibility path).
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'e.txt', 'x', 'alice');
		$this->evidenceFiles = [];

		$this->expectException(\OCP\Files\NotFoundException::class);
		$this->attachmentServiceFor('alice')->download($attachment->getId());
	}

	public function testMimeContentMismatchIsRejected(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		// A file named .png whose detected content type is not on the allowlist.
		$this->forcedMimeType = 'image/svg+xml';

		$this->expectException(ValidationException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'picture.png', 'content' => '<svg/>']);
	}

	public function testFilenameWithControlCharactersIsSanitized(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');

		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => "e\x00vil.txt", 'content' => 'x']);

		self::assertSame('evil.txt', $attachment->getFilename());
	}

	public function testOverlongFilenameIsRejected(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');

		$this->expectException(ValidationException::class);
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => str_repeat('a', 256) . '.txt', 'content' => 'x']);
	}

	public function testAttachmentSerializationNeverExposesStorageKey(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);

		$serialized = $attachment->toArray();
		self::assertArrayNotHasKey('storageKey', $serialized);
		self::assertArrayNotHasKey('storage_key', $serialized);
		self::assertArrayNotHasKey('path', $serialized);
	}

	public function testFilesAttachmentHasNoLegacyStorageKey(): void {
		$this->addUser('alice');
		[, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'secret.txt', 'content' => 'x']);

		self::assertSame(Attachment::STORAGE_KIND_FILES, $attachment->getStorageKind());
		self::assertSame('', $attachment->getStorageKey());
		self::assertNotNull($attachment->getFileId());
	}

	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep, 2: \OCP\Files\Folder}
	 */
	private function runWithFileStep(string $owner, bool $required): array {
		[$run, $folder] = $this->addFilesRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', $required, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, $owner);

		return [$run, $step, $folder];
	}

	public function testCannotDeleteLastEvidenceFromCompletedRequiredFileStep(): void {
		$this->addUser('alice');
		[$run, $step, $folder] = $this->runWithFileStep('alice', true);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'x']);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);

		try {
			$this->attachmentServiceFor('alice')->delete($attachment->getId());
			self::fail('Deleting the last evidence of a completed required FILE step must be rejected');
		} catch (ConflictException $exception) {
			self::assertSame('last_file_evidence_required', $exception->getReason());
		}

		self::assertCount(1, $this->attachments, 'the evidence must be kept');
		self::assertCount(1, $folder->getDirectoryListing(), 'the stored file must be kept');
	}

	public function testReplacementEvidenceAllowsDeletingTheOldAttachment(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithFileStep('alice', true);
		$first = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'old.txt', 'content' => 'old']);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);
		$replacement = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'new.txt', 'content' => 'new']);

		// With a replacement persisted, the old attachment may be removed.
		$this->attachmentServiceFor('alice')->delete($first->getId());
		self::assertCount(1, $this->attachments);
		self::assertSame($replacement->getId(), array_key_first($this->attachments));

		// The replacement is now the last one and cannot be removed.
		$this->expectException(ConflictException::class);
		$this->attachmentServiceFor('alice')->delete($replacement->getId());
	}

	public function testCanDeleteEvidenceFromCompletedOptionalFileStep(): void {
		$this->addUser('alice');
		[$run, $step, $folder] = $this->runWithFileStep('alice', false);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'x']);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);

		$this->attachmentServiceFor('alice')->delete($attachment->getId());

		self::assertSame([], $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles);
	}

	public function testCanDeleteEvidenceFromCompletedRequiredNonFileStep(): void {
		$this->addUser('alice');
		[$run] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'x']);
		$this->runStepServiceFor('alice')->complete($step->getId(), ['response' => true]);

		$this->attachmentServiceFor('alice')->delete($attachment->getId());

		self::assertSame([], $this->attachments);
	}

	public function testConcurrentDeletionIsRejectedWhileTheStepEvidenceLockIsHeld(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithFileStep('alice', true);
		$first = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'old.txt', 'content' => 'old']);
		$replacement = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'new.txt', 'content' => 'new']);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);

		// Simulate a second request already inside the critical section.
		$path = $this->evidenceLock->path($run->getId(), $step->getId());
		$this->heldLocks[$path] = \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE;
		try {
			$this->attachmentServiceFor('alice')->delete($first->getId());
			self::fail('A concurrent deletion must not enter while the step evidence lock is held');
		} catch (ConflictException $exception) {
			self::assertSame('evidence_locked', $exception->getReason());
		}
		self::assertCount(2, $this->attachments, 'the concurrent deletion must not have removed anything');

		// With the lock free again, one deletion is allowed and the last one is
		// still protected.
		unset($this->heldLocks[$path]);
		$this->attachmentServiceFor('alice')->delete($first->getId());
		self::assertCount(1, $this->attachments);

		try {
			$this->attachmentServiceFor('alice')->delete($replacement->getId());
			self::fail('The last attachment must stay protected');
		} catch (ConflictException $exception) {
			self::assertSame('last_file_evidence_required', $exception->getReason());
		}
		self::assertCount(1, $this->attachments);
	}

	/**
	 * @return array{0: Run, 1: RunStep, 2: Folder}
	 */
	private function filesRunWithStep(string $owner): array {
		return $this->runWithStep($owner, $owner);
	}

	public function testUploadToFilesRunStoresEvidenceInManagedFolder(): void {
		$this->addUser('alice');
		[$run, $step, $folder] = $this->filesRunWithStep('alice');

		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);

		self::assertSame(Attachment::STORAGE_KIND_FILES, $attachment->getStorageKind());
		self::assertSame('', $attachment->getStorageKey(), 'Files rows do not use the legacy key');
		self::assertSame('home::test', $attachment->getStorageId());
		self::assertNotNull($attachment->getFileId());
		self::assertNotNull($attachment->getPath());
		self::assertSame($run->getId(), $attachment->getRunId());
		self::assertSame([], $this->evidenceFiles, 'Files-backed evidence must never touch AppData');
		self::assertCount(1, $folder->getDirectoryListing());

		$stored = $folder->get('evidence.txt');
		self::assertInstanceOf(File::class, $stored);
		self::assertSame('hello', $stored->getContent());
		self::assertSame($stored->getId(), $attachment->getFileId());
	}

	public function testUploadToFilesRunUsesAUniquePhysicalName(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesRunWithStep('alice');
		$service = $this->attachmentServiceFor('alice');

		$first = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'one']);
		$second = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'two']);

		self::assertNotSame($first->getFileId(), $second->getFileId());
		self::assertCount(2, $folder->getDirectoryListing());
		self::assertSame('evidence.txt', $first->getFilename());
		self::assertSame('evidence.txt', $second->getFilename());
	}

	public function testUploadRejectsTheReservedOwnershipMarkerName(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesRunWithStep('alice');

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => '.runbook-run.json', 'content' => 'x']);
			self::fail('the ownership marker must not be uploadable as evidence');
		} catch (ValidationException $exception) {
			self::assertSame('attachment_invalid_filename', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles);
	}

	public function testUploadRejectsTheReservedOwnershipMarkerNameCaseInsensitively(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesRunWithStep('alice');

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => '.RUNBOOK-RUN.JSON', 'content' => 'x']);
			self::fail('the ownership marker must be rejected regardless of case');
		} catch (ValidationException $exception) {
			self::assertSame('attachment_invalid_filename', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles);
	}

	public function testUploadToFilesRunFailsClosedWhenManagedFolderIsMissing(): void {
		$this->addUser('alice');
		[$run, $step] = $this->filesRunWithStep('alice');
		// Simulate an out-of-band folder deletion.
		unset($this->userRootChildren['RunFolder']);

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);
			self::fail('a missing managed folder must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertSame([], $this->evidenceFiles, 'a missing managed folder must not fall back to AppData');
	}

	public function testLegacyRunUploadFailsClosedAndNeverTouchesAppData(): void {
		$this->addUser('alice');
		// A legacy run has no destination identity at all.
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
			self::fail('a legacy run without a managed folder must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}

		self::assertCount(0, $this->attachments, 'no attachment row is created');
		self::assertSame([], $this->evidenceFiles, 'no new evidence may be written to AppData');
	}

	public function testPartialManagedFolderIdentityFailsClosedWithoutAppDataFallback(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		// A managed-folder identity must be complete; a partial one is corrupt.
		$run->setRunFolderFileId(7000);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
			self::fail('a partial managed-folder identity must fail closed');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertSame([], $this->evidenceFiles);
	}

	public function testDestinationSourceWithoutManagedFolderFailsClosed(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		// Destination metadata present but no managed folder: corrupt, not legacy.
		$run->setDestinationSource('default');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
			self::fail('a corrupt destination identity must fail closed');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertSame([], $this->evidenceFiles);
	}

	public function testExistingAppDataAttachmentsRemainReadableAndDeletable(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'legacy.txt', 'legacy-content', 'alice');

		$result = $this->attachmentServiceFor('alice')->download($attachment->getId());
		self::assertSame('legacy-content', $result['content']);

		$this->attachmentServiceFor('alice')->delete($attachment->getId());
		self::assertCount(0, $this->attachments);
		self::assertSame([], $this->evidenceFiles);
	}

	public function testDownloadOutOfScopeFilesAttachmentFailsClosedWithoutContent(): void {
		$this->addUser('alice');
		[, $step] = $this->runWithStep('alice', 'alice');
		$service = $this->attachmentServiceFor('alice');
		$attachment = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'secret']);

		// The tracked identity now points at a file outside the managed folder.
		$children = [];
		$outside = $this->makeFileMock('outside.txt', 9001, $children, 'secret');
		$this->userRootChildren['outside.txt'] = $outside;
		$attachment->setFileId(9001);
		$attachment->setStorageId('home::test');

		try {
			$service->download($attachment->getId());
			self::fail('out-of-scope evidence must not be downloadable');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_out_of_scope', $exception->getReason());
		}
	}

	public function testDownloadFilesAttachmentReadsFromFiles(): void {
		$this->addUser('alice');
		[, $step] = $this->filesRunWithStep('alice');
		$service = $this->attachmentServiceFor('alice');
		$attachment = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);

		$result = $service->download($attachment->getId());

		self::assertSame($attachment->getId(), $result['attachment']->getId());
		self::assertSame('hello', $result['content']);
	}

	public function testDownloadMissingFilesAttachmentFailsClosed(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesRunWithStep('alice');
		$service = $this->attachmentServiceFor('alice');
		$attachment = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);

		$folder->get('evidence.txt')->delete();

		try {
			$service->download($attachment->getId());
			self::fail('a missing tracked file must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_missing', $exception->getReason());
		}
	}

	public function testDeleteFilesAttachmentRemovesTheTrackedFile(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesRunWithStep('alice');
		$service = $this->attachmentServiceFor('alice');
		$attachment = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);

		$service->delete($attachment->getId());

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing(), 'the tracked Files evidence is removed');
	}

	public function testDeleteFilesAttachmentOutOfScopeFailsClosed(): void {
		$this->addUser('alice');
		[, $step] = $this->filesRunWithStep('alice');
		$service = $this->attachmentServiceFor('alice');
		$attachment = $service->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);

		// An unrelated file elsewhere in the view, reused as the tracked identity.
		$children = [];
		$outside = $this->makeFileMock('outside.txt', 9001, $children);
		$this->userRootChildren['outside.txt'] = $outside;
		$attachment->setFileId(9001);
		$attachment->setStorageId('home::test');

		try {
			$service->delete($attachment->getId());
			self::fail('out-of-scope evidence must not be deleted');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_out_of_scope', $exception->getReason());
		}

		self::assertCount(1, $this->attachments, 'the attachment row is preserved');
		self::assertNotNull($outside->getId());
	}

	public function testFilesMetadataInsertFailureRemovesTheOrphanFile(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesRunWithStep('alice');
		$this->failAttachmentInsert = true;

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'hello']);
			self::fail('a metadata failure must abort the upload');
		} catch (\OCP\DB\Exception) {
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing(), 'the orphaned Files evidence is removed');
	}

	// --- #51: attachment permission matrix (owner/participant/assignee/viewer/unauthorized) ---

	public function testOwnerCanDownloadAndDeleteAnotherUsersEvidence(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		// The step is assigned to bob; the owner may still manage its evidence.
		[$run, $step] = $this->runWithStep('alice', 'bob');
		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'b.txt', 'content' => 'b']);

		self::assertSame('b', $this->attachmentServiceFor('alice')->download($attachment->getId())['content']);

		$this->attachmentServiceFor('alice')->delete($attachment->getId());
		self::assertCount(0, $this->attachments);
	}

	public function testAssignedUserCanUploadDownloadAndDeleteOwnEvidence(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step, $folder] = $this->runWithStep('alice', 'bob');
		$bob = $this->attachmentServiceFor('bob');

		$attachment = $bob->upload($step->getId(), ['name' => 'b.txt', 'content' => 'b']);
		self::assertSame('bob', $attachment->getUploaderUid());
		self::assertSame('b', $bob->download($attachment->getId())['content']);

		$bob->delete($attachment->getId());
		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
	}

	public function testGroupAssigneeCanUploadEvidence(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('ops');
		$this->joinGroup('bob', 'ops');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::Group->value, 'ops');

		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'b.txt', 'content' => 'b']);

		self::assertSame('bob', $attachment->getUploaderUid());
		self::assertCount(1, $folder->getDirectoryListing());
	}

	public function testParticipantWithoutStepAssignmentCanDownloadButCannotUploadOrDelete(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		// Step assigned to the owner; bob is an ACL participant, not an assignee.
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'a.txt', 'content' => 'a']);
		$bob = $this->attachmentServiceFor('bob');

		self::assertSame('a', $bob->download($attachment->getId())['content'], 'a participant may read run evidence');

		try {
			$bob->upload($step->getId(), ['name' => 'b.txt', 'content' => 'b']);
			self::fail('a participant without step assignment must not upload');
		} catch (ForbiddenException) {
		}

		try {
			$bob->delete($attachment->getId());
			self::fail('a participant must not delete another user\'s evidence');
		} catch (ForbiddenException) {
		}
		self::assertCount(1, $this->attachments);
	}

	public function testParticipantAssignedToTheStepCanUploadAndDeleteOwnEvidence(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->runWithStep('alice', 'bob');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);
		$bob = $this->attachmentServiceFor('bob');

		$attachment = $bob->upload($step->getId(), ['name' => 'b.txt', 'content' => 'b']);
		$bob->delete($attachment->getId());
		self::assertCount(0, $this->attachments);
	}

	public function testViewerCanDownloadButCannotUploadOrDelete(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Viewer->value);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'a.txt', 'content' => 'a']);
		$carol = $this->attachmentServiceFor('carol');

		self::assertSame('a', $carol->download($attachment->getId())['content'], 'a viewer may read run evidence');

		try {
			$carol->upload($step->getId(), ['name' => 'c.txt', 'content' => 'c']);
			self::fail('a viewer must not upload');
		} catch (ForbiddenException) {
		}

		try {
			$carol->delete($attachment->getId());
			self::fail('a viewer must not delete evidence');
		} catch (ForbiddenException) {
		}
		self::assertCount(1, $this->attachments);
	}

	public function testUnauthorizedUserCannotUploadDownloadOrDeleteEvidence(): void {
		$this->addUser('alice');
		$this->addUser('mallory');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'a.txt', 'content' => 'a']);
		$mallory = $this->attachmentServiceFor('mallory');

		try {
			$mallory->upload($step->getId(), ['name' => 'm.txt', 'content' => 'm']);
			self::fail('an unauthorized user must not upload');
		} catch (NotFoundException) {
		}

		try {
			$mallory->download($attachment->getId());
			self::fail('an unauthorized user must not download');
		} catch (NotFoundException) {
		}

		try {
			$mallory->delete($attachment->getId());
			self::fail('an unauthorized user must not delete');
		} catch (NotFoundException) {
		}
		self::assertCount(1, $this->attachments);
	}

	public function testParticipantUploadIsWrittenToTheOwnerFolderAndRecordsTheUploader(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step, $folder] = $this->runWithStep('alice', 'bob');

		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'bob.txt', 'content' => 'bob-evidence']);

		// The assignee does not need Files access: the evidence is written
		// server-side into the run owner's managed folder, with the uploader kept.
		self::assertSame('bob', $attachment->getUploaderUid());
		self::assertSame(Attachment::STORAGE_KIND_FILES, $attachment->getStorageKind());
		self::assertCount(1, $folder->getDirectoryListing());
		self::assertSame('bob-evidence', $this->attachmentServiceFor('alice')->download($attachment->getId())['content']);
	}

	public function testGroupAssignedUserCanDownloadEvidenceForTheirGroupStep(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('ops');
		$this->joinGroup('bob', 'ops');
		[$run] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::Group->value, 'ops');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'a.txt', 'content' => 'a']);

		// Group membership grants run access, so the assignee may read the evidence.
		self::assertSame('a', $this->attachmentServiceFor('bob')->download($attachment->getId())['content']);
	}

	public function testGroupAssignedUserCanDeleteOwnUploadButNotAnotherUsers(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addUser('dave');
		$this->addGroup('ops');
		$this->joinGroup('bob', 'ops');
		$this->joinGroup('dave', 'ops');
		[$run] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::Group->value, 'ops');
		$bobUpload = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'bob.txt', 'content' => 'b']);
		$daveUpload = $this->attachmentServiceFor('dave')->upload($step->getId(), ['name' => 'dave.txt', 'content' => 'd']);

		// Another group member's upload may not be deleted…
		try {
			$this->attachmentServiceFor('bob')->delete($daveUpload->getId());
			self::fail('a group assignee must not delete another user\'s upload');
		} catch (ForbiddenException) {
		}
		self::assertCount(2, $this->attachments);

		// …but the uploader may delete their own.
		$this->attachmentServiceFor('bob')->delete($bobUpload->getId());
		self::assertCount(1, $this->attachments);
		self::assertArrayHasKey($daveUpload->getId(), $this->attachments);
	}

	public function testOwnerCanUploadEvidenceToAStepAssignedToAnotherUser(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[, $step, $folder] = $this->runWithStep('alice', 'bob');

		// The owner bypasses step assignment and uploads to bob's step.
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'a.txt', 'content' => 'a']);

		self::assertSame('alice', $attachment->getUploaderUid());
		self::assertCount(1, $folder->getDirectoryListing());
		self::assertSame('a', $this->attachmentServiceFor('bob')->download($attachment->getId())['content']);
	}
}
