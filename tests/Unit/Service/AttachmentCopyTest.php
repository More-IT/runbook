<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AttachmentReconciliationService;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException as FilesForbiddenException;

/**
 * Copying an existing Files item into a run as evidence (issue #53).
 *
 * The source is resolved in the acting user's view and copied into the run
 * owner's managed folder; the original must never be changed, and every failure
 * path must leave no attachment behind (and never fall back to AppData).
 */
class AttachmentCopyTest extends RunTestBase {
	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep, 2: Folder}
	 */
	private function filesStep(string $owner, ?string $assigneeId = null, bool $creatable = true): array {
		[$run, $folder] = $this->addFilesRun($owner, RunStatus::Active->value, $creatable);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep(
			$section->getId(),
			'CHECK',
			true,
			RunStepStatus::Pending->value,
			0,
			[],
			PrincipalType::User->value,
			$assigneeId ?? $owner,
		);

		return [$run, $step, $folder];
	}

	public function testOwnerCanCopyAnExistingFileIntoTheRunFolder(): void {
		$this->addUser('alice');
		[$run, $step, $folder] = $this->filesStep('alice');
		$source = $this->addUserFile('source.txt', 5001, 'payload');

		$attachment = $this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);

		self::assertSame('source.txt', $attachment->getFilename());
		self::assertSame('text/plain', $attachment->getMimeType());
		self::assertSame(7, $attachment->getSize());
		self::assertSame(hash('sha256', 'payload'), $attachment->getChecksum());
		self::assertSame(Attachment::STORAGE_KIND_FILES, $attachment->getStorageKind());
		self::assertSame('', $attachment->getStorageKey());
		self::assertSame($run->getId(), $attachment->getRunId());
		self::assertSame($step->getId(), $attachment->getStepId());
		self::assertSame('alice', $attachment->getUploaderUid());
		self::assertSame([], $this->evidenceFiles, 'copy must never use AppData');

		// The copy lives in the run folder and the attachment points at it.
		$copy = $folder->get('source.txt');
		self::assertInstanceOf(File::class, $copy);
		self::assertSame('payload', $copy->getContent());
		self::assertSame($copy->getId(), $attachment->getFileId());
		self::assertNotSame($source->getId(), $attachment->getFileId());

		// The original is untouched.
		self::assertSame(5001, $source->getId());
		self::assertSame('payload', $source->getContent());
		self::assertArrayHasKey('source.txt', $this->userRootChildren);
	}

	public function testCopiedAttachmentReconcilesAsPresent(): void {
		$this->addUser('alice');
		[$run, $step] = $this->filesStep('alice');
		$this->addUserFile('source.txt', 5002, 'payload');

		$attachment = $this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);

		self::assertSame(
			AttachmentReconciliationService::PRESENT,
			$this->attachmentReconciliationService()->stateFor($run, $attachment),
		);
	}

	public function testCopiedAttachmentSatisfiesARequiredFileStep(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$this->addUserFile('report.pdf', 5003, 'pdf-bytes');

		$attachment = $this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'report.pdf']);
		self::assertSame('report.pdf', $attachment->getFilename());

		$completed = $this->runStepServiceFor('alice')->complete($step->getId(), []);
		self::assertSame(RunStepStatus::Completed->value, $completed->getStatus());
	}

	public function testAssigneeCanCopyFromTheirOwnFilesIntoTheOwnersFolder(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step, $folder] = $this->filesStep('alice', 'bob');
		$this->addUserFile('bob.txt', 5004, 'bob-data');

		$attachment = $this->attachmentServiceFor('bob')->copyFromFiles($step->getId(), ['sourcePath' => 'bob.txt']);

		self::assertSame('bob', $attachment->getUploaderUid());
		self::assertCount(1, $folder->getDirectoryListing());
		self::assertSame('bob-data', $this->attachmentServiceFor('alice')->download($attachment->getId())['content']);
	}

	public function testViewerCannotCopy(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		[$run, $step] = $this->filesStep('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Viewer->value);
		$this->addUserFile('source.txt', 5005, 'payload');

		$this->expectException(ForbiddenException::class);
		$this->attachmentServiceFor('carol')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
	}

	public function testUnrelatedUserCannotCopy(): void {
		$this->addUser('alice');
		$this->addUser('mallory');
		[, $step] = $this->filesStep('alice');
		$this->addUserFile('source.txt', 5006, 'payload');

		$this->expectException(NotFoundException::class);
		$this->attachmentServiceFor('mallory')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
	}

	public function testUnassignedParticipantCannotCopy(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, $step] = $this->filesStep('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);
		$this->addUserFile('source.txt', 5007, 'payload');

		$this->expectException(ForbiddenException::class);
		$this->attachmentServiceFor('bob')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
	}

	public function testCopyToABlockedSectionIsRejected(): void {
		$this->addUser('alice');
		[$run] = $this->addFilesRun('alice');
		$sectionA = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($sectionA->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$sectionB = $this->addRunSection($run->getId(), 1);
		$sectionB->setDependsOnIds([$sectionA->getId()]);
		$blockedStep = $this->addRunStep($sectionB->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$this->addUserFile('source.txt', 5008, 'payload');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($blockedStep->getId(), ['sourcePath' => 'source.txt']);
			self::fail('a blocked section must reject a copy');
		} catch (ConflictException $exception) {
			self::assertSame('section_not_available', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
	}

	public function testMissingSourceFailsClosedWithoutCreatingAnAttachment(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'nope.txt']);
			self::fail('a missing source must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_source_missing', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame([], $this->evidenceFiles);
	}

	public function testEmptySourcePathIsRejected(): void {
		$this->addUser('alice');
		[, $step] = $this->filesStep('alice');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => '   ']);
			self::fail('an empty source path must be rejected');
		} catch (ValidationException $exception) {
			self::assertSame('attachment_source_required', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
	}

	public function testNonStringSourcePathIsRejected(): void {
		$this->addUser('alice');
		[, $step] = $this->filesStep('alice');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 42]);
			self::fail('a non-string source path must be rejected');
		} catch (ValidationException $exception) {
			self::assertSame('invalid_field', $exception->getReason());
		}
	}

	public function testFolderSourceIsRejected(): void {
		$this->addUser('alice');
		[, $step] = $this->filesStep('alice');
		$this->addUserFolder('a-folder', 5009);

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'a-folder']);
			self::fail('only files may be copied');
		} catch (ValidationException $exception) {
			self::assertSame('attachment_source_invalid', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
	}

	public function testUnreadableSourceFailsClosed(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$this->addUserFile('locked.txt', 5010, 'secret', false);

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'locked.txt']);
			self::fail('an unreadable source must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_source_no_access', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
	}

	public function testAmbiguousSourceFailsClosed(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$this->addUserFile('dup-a.txt', 5011, 'a');
		// A second node with the exact same identity in the same view.
		$this->userRootChildren['dup-b.txt'] = $this->makeFileMock('dup-b.txt', 5011, $this->userRootChildren, 'b');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'dup-a.txt']);
			self::fail('an ambiguous source must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_source_ambiguous', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
	}

	public function testReservedOwnershipMarkerCannotBeCopied(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$this->addUserFile('.runbook-run.json', 5012, '{}');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => '.runbook-run.json']);
			self::fail('the ownership marker must not be copyable as evidence');
		} catch (ValidationException $exception) {
			self::assertSame('attachment_invalid_filename', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
	}

	public function testMissingManagedFolderFailsClosedWithoutAppDataFallback(): void {
		$this->addUser('alice');
		[, $step] = $this->filesStep('alice');
		$source = $this->addUserFile('source.txt', 5013, 'payload');
		unset($this->userRootChildren['RunFolder']);

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
			self::fail('a missing managed folder must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertSame([], $this->evidenceFiles, 'no AppData fallback');
		self::assertArrayNotHasKey('RunFolder', $this->userRootChildren, 'the folder is never recreated');
		self::assertSame('payload', $source->getContent(), 'the source is untouched');
	}

	public function testUnwritableManagedFolderFailsClosed(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice', null, false);
		$source = $this->addUserFile('source.txt', 5014, 'payload');

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
			self::fail('an unwritable managed folder must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('destination_not_writable', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing());
		self::assertSame('payload', $source->getContent());
	}

	public function testNameCollisionDoesNotOverwriteExistingEvidence(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$source = $this->addUserFile('report.txt', 5015, 'v1');
		$service = $this->attachmentServiceFor('alice');

		$first = $service->copyFromFiles($step->getId(), ['sourcePath' => 'report.txt']);
		$source->putContent('v2');
		$second = $service->copyFromFiles($step->getId(), ['sourcePath' => 'report.txt']);

		self::assertNotSame($first->getFileId(), $second->getFileId());
		self::assertCount(2, $folder->getDirectoryListing());
		// The first copy is preserved; the second is written under a new name.
		$firstCopy = $folder->get('report.txt');
		self::assertInstanceOf(File::class, $firstCopy);
		self::assertSame('v1', $firstCopy->getContent());
		self::assertSame('report.txt', $first->getFilename());
		self::assertSame('report.txt', $second->getFilename());
	}

	public function testMetadataFailureRemovesOnlyTheCopyAndKeepsTheSource(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$source = $this->addUserFile('source.txt', 5016, 'payload');
		$this->failAttachmentInsert = true;

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
			self::fail('a metadata failure must abort the copy');
		} catch (\OCP\DB\Exception) {
		}

		self::assertCount(0, $this->attachments);
		self::assertCount(0, $folder->getDirectoryListing(), 'only the copy created by this attempt is removed');
		self::assertSame('payload', $source->getContent(), 'the original is never deleted');
		self::assertArrayHasKey('source.txt', $this->userRootChildren);
	}

	public function testClientSuppliedIdentityCannotRedirectTheCopy(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$this->addUserFile('source.txt', 5017, 'from-source');

		$attachment = $this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), [
			'sourcePath' => 'source.txt',
			'fileId' => 999999,
			'storageId' => 'attacker::storage',
			'storageRootId' => 4242,
		]);

		$copy = $folder->get('source.txt');
		self::assertInstanceOf(File::class, $copy);
		self::assertSame($copy->getId(), $attachment->getFileId(), 'identity comes from the resolved destination');
		self::assertSame('home::test', $attachment->getStorageId());
		self::assertNotSame(999999, $attachment->getFileId());
		self::assertSame('from-source', $copy->getContent());
	}

	public function testForbiddenSourceLookupMapsToSourceNoAccess(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$source = $this->addUserFile('source.txt', 5018, 'payload');
		// `OCP\Files\ForbiddenException` is a sibling of NotPermittedException and
		// is not declared on Folder::get(); it must map to a source error.
		$this->userFilesFolder->method('get')->willThrowException(new FilesForbiddenException('denied', false));

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
			self::fail('a forbidden source lookup must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_source_no_access', $exception->getReason());
		}

		self::assertCount(0, $this->attachments, 'no attachment row on source denial');
		self::assertCount(0, $folder->getDirectoryListing(), 'nothing is copied on source denial');
		self::assertSame([], $this->evidenceFiles, 'nothing is written to AppData');
		self::assertSame('payload', $source->getContent(), 'the original is untouched');
	}

	public function testForbiddenSourceReadMapsToSourceNoAccess(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->filesStep('alice');
		$this->addUserFile('source.txt', 5019, 'payload');
		$this->userRootChildren['source.txt']->method('getContent')->willThrowException(new FilesForbiddenException('denied', false));

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
			self::fail('a forbidden source read must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_source_no_access', $exception->getReason());
		}

		self::assertCount(0, $this->attachments, 'no attachment row on source denial');
		self::assertCount(0, $folder->getDirectoryListing(), 'nothing is copied on source denial');
		self::assertSame([], $this->evidenceFiles, 'nothing is written to AppData');
		self::assertArrayHasKey('source.txt', $this->userRootChildren, 'the original is untouched');
	}

	public function testForbiddenDestinationWriteKeepsDestinationError(): void {
		$this->addUser('alice');
		[, $step] = $this->filesStep('alice');
		$this->addUserFile('source.txt', 5020, 'payload');
		// A denied destination write must keep the destination-specific error and
		// must not be reported as a source error.
		$this->userRootChildren['RunFolder']->method('newFile')->willThrowException(new FilesForbiddenException('denied', false));

		try {
			$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'source.txt']);
			self::fail('a forbidden destination write must fail closed');
		} catch (ConflictException $exception) {
			self::assertSame('destination_not_writable', $exception->getReason());
		}

		self::assertCount(0, $this->attachments);
		self::assertSame([], $this->evidenceFiles);
	}
}
