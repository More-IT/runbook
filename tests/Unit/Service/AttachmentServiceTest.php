<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\ActivityEvent;
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

class AttachmentServiceTest extends RunTestBase {
	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep}
	 */
	private function runWithStep(string $owner, string $assigneeId): array {
		$run = $this->addRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, $assigneeId);

		return [$run, $step];
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
		[$run, $step] = $this->runWithStep('alice', 'bob');

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
		self::assertArrayNotHasKey('storageKey', $attachment->toArray());
		self::assertCount(1, $this->evidenceFiles);
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
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->forcedMimeType = 'application/x-msdownload';

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.exe', 'content' => 'MZ']);
			self::fail('Expected validation failure');
		} catch (ValidationException) {
		}

		self::assertSame([], $this->attachments);
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
		[$run, $step] = $this->runWithStep('alice', 'bob');
		$attachment = $this->attachmentServiceFor('bob')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);

		$this->attachmentServiceFor('bob')->delete($attachment->getId());

		self::assertSame([], $this->attachments);
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
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$this->failAttachmentInsert = true;

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
			self::fail('Expected metadata insert failure');
		} catch (\OCP\DB\Exception) {
		}

		self::assertSame([], $this->attachments);
		self::assertSame([], $this->evidenceFiles, 'Orphan file must be removed when metadata insert fails');
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
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'e.txt', 'content' => 'x']);
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

	public function testStoredStorageKeyIsRandomAndNotDerivedFromFilename(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithStep('alice', 'alice');
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'secret.txt', 'content' => 'x']);

		self::assertNotSame('secret.txt', $attachment->getStorageKey());
		self::assertSame(32, strlen($attachment->getStorageKey()));
	}

	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep}
	 */
	private function runWithFileStep(string $owner, bool $required): array {
		$run = $this->addRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', $required, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, $owner);

		return [$run, $step];
	}

	public function testCannotDeleteLastEvidenceFromCompletedRequiredFileStep(): void {
		$this->addUser('alice');
		[$run, $step] = $this->runWithFileStep('alice', true);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'x']);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);

		try {
			$this->attachmentServiceFor('alice')->delete($attachment->getId());
			self::fail('Deleting the last evidence of a completed required FILE step must be rejected');
		} catch (ConflictException $exception) {
			self::assertSame('last_file_evidence_required', $exception->getReason());
		}

		self::assertCount(1, $this->attachments, 'the evidence must be kept');
		self::assertCount(1, $this->evidenceFiles, 'the stored file must be kept');
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
		[$run, $step] = $this->runWithFileStep('alice', false);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'x']);
		$this->runStepServiceFor('alice')->complete($step->getId(), []);

		$this->attachmentServiceFor('alice')->delete($attachment->getId());

		self::assertSame([], $this->attachments);
		self::assertSame([], $this->evidenceFiles);
	}

	public function testCanDeleteEvidenceFromCompletedRequiredNonFileStep(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
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
}
