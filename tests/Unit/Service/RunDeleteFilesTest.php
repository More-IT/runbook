<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\FilesCleanup;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\TransactionRunner;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Fail-closed run deletion and durable managed-folder cleanup (issue #54).
 *
 * All Files behaviour is exercised through the in-memory view and a fault-
 * injecting transaction double; real concurrency, real mounts and real crash
 * recovery are not simulated here.
 */
class RunDeleteFilesTest extends RunTestBase {
	/**
	 * @return array{0: Run, 1: RunStep, 2: Folder, 3: Attachment}
	 */
	private function filesRunWithEvidence(string $owner = 'alice', ?string $assignee = null): array {
		[$run, $folder] = $this->addFilesRun($owner);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, $assignee ?? $owner);
		$attachment = $this->attachmentServiceFor($owner)->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'payload']);

		return [$run, $step, $folder, $attachment];
	}

	/**
	 * Replace the transaction double with one that rolls the in-memory rows back
	 * on failure, so a failed transaction is observable without a real database.
	 */
	private function installRollbackTransactions(): void {
		$this->transactionRunner = $this->createMock(TransactionRunner::class);
		$this->transactionRunner->method('run')->willReturnCallback(function (callable $operation): mixed {
			$runs = $this->runs;
			$attachments = $this->attachments;
			$cleanups = $this->filesCleanups;
			try {
				return $operation();
			} catch (\Throwable $exception) {
				$this->runs = $runs;
				$this->attachments = $attachments;
				$this->filesCleanups = $cleanups;
				throw $exception;
			}
		});
	}

	public function testTrackedFileIsDeletedAndTheEmptyFolderIsPreserved(): void {
		$this->addUser('alice');
		[$run, , $folder, ] = $this->filesRunWithEvidence();

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertCount(0, $folder->getDirectoryListing(), 'the tracked evidence is removed');
		self::assertNotContains($folder->getId(), $this->deletedFolderIds, 'the folder is never recursively deleted');
		self::assertCount(1, $this->filesCleanups, 'a preserved folder keeps a durable record');
		$record = array_values($this->filesCleanups)[0];
		self::assertSame(FilesCleanup::STATUS_BLOCKED, $record->getStatus());
		self::assertSame(FilesCleanup::REASON_REMOVAL_UNSUPPORTED, $record->getReason());
		self::assertSame($folder->getId(), $record->getFileId());
		self::assertSame('home::test', $record->getStorageId());
	}

	public function testMissingTrackedFileIsTreatedAsGone(): void {
		$this->addUser('alice');
		[$run, , $folder, ] = $this->filesRunWithEvidence();
		$folder->get('evidence.txt')->delete();

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertNotContains($folder->getId(), $this->deletedFolderIds, 'the folder is preserved, not recursively deleted');
		self::assertCount(1, $this->filesCleanups);
		self::assertSame(FilesCleanup::REASON_REMOVAL_UNSUPPORTED, array_values($this->filesCleanups)[0]->getReason());
	}

	public function testOutOfScopeFileIsPreservedAndNeverBlocks(): void {
		$this->addUser('alice');
		[$run, , $folder, ] = $this->filesRunWithEvidence();
		$managed = &$this->folderChildrenById[$folder->getId()];
		$moved = $managed['evidence.txt'];
		unset($managed['evidence.txt']);
		$this->userRootChildren['moved.txt'] = $moved;

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertArrayHasKey('moved.txt', $this->userRootChildren, 'an out-of-scope file is never managed evidence');
		self::assertNotContains($moved->getId(), $this->deletedFileIds, 'the out-of-scope node is untouched');
		self::assertNotContains($folder->getId(), $this->deletedFolderIds, 'the run folder is preserved, never recursively deleted');
	}

	public function testFileWithoutDeletePermissionBlocksTheWholeDeletion(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$file = $this->makeFileMock('locked.txt', 7001, $this->folderChildrenById[$folder->getId()], 'payload', true, false);
		$this->folderChildrenById[$folder->getId()]['locked.txt'] = $file;
		$attachment = $this->seedFilesAttachment($run, $step->getId(), $file, 'locked.txt', 'payload', 'alice');

		try {
			$this->runServiceFor('alice')->deleteRun($run->getId());
			self::fail('a file without delete permission must block the deletion');
		} catch (ConflictException $exception) {
			self::assertSame('run_delete_blocked', $exception->getReason());
		}

		self::assertArrayHasKey($run->getId(), $this->runs, 'the run is retained');
		self::assertArrayHasKey($attachment->getId(), $this->attachments, 'the attachment identity is retained');
		self::assertArrayHasKey('locked.txt', $this->folderChildrenById[$folder->getId()], 'the file is retained');
		self::assertNotContains($file->getId(), $this->deletedFileIds);
		self::assertSame([], $this->filesCleanups, 'no cleanup record on a pre-flight failure');
	}

	/**
	 * A present Files node whose deletability probe throws, as when the file or
	 * its storage became unreachable between reconciliation and the pre-flight.
	 * The node still resolves by identity and descriptor, but `isDeletable()`
	 * and `getPermissions()` fail.
	 *
	 * @return File&MockObject
	 */
	private function makeUnprobeableFile(string $name, int $id, int $folderId, string $content = 'payload'): File {
		/** @var IStorage&MockObject $storage */
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn('home::test');
		/** @var IMountPoint&MockObject $mount */
		$mount = $this->createMock(IMountPoint::class);
		$mount->method('getStorageRootId')->willReturn(7);
		$mount->method('getMountType')->willReturn('local');
		$mount->method('getMountProvider')->willReturn('OC\\Files\\Mount\\LocalHomeMountProvider');
		$mount->method('getMountId')->willReturn(null);
		$mount->method('getNumericStorageId')->willReturn(1);

		/** @var File&MockObject $file */
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($name);
		$file->method('getName')->willReturn($name);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getMountPoint')->willReturn($mount);
		$file->method('isDeletable')->willThrowException(new FilesNotFoundException($name));
		$file->method('getPermissions')->willThrowException(new FilesNotFoundException($name));
		$file->method('getSize')->willReturn(strlen($content));
		$file->method('getContent')->willReturn($content);
		$file->method('delete')->willReturnCallback(function () use ($name, $id, $folderId): void {
			$this->deletedFileIds[] = $id;
			unset($this->folderChildrenById[$folderId][$name]);
		});

		return $file;
	}

	public function testUnprobeableTrackedFileBlocksTheWholeDeletion(): void {
		// A Files node whose delete capability cannot be read (unreachable
		// storage, race deletion, unexpected Files error) must fail closed with
		// the stable `run_delete_blocked` reason and never be assumed deletable.
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$file = $this->makeUnprobeableFile('probe.txt', 7002, $folder->getId());
		$this->folderChildrenById[$folder->getId()]['probe.txt'] = $file;
		$attachment = $this->seedFilesAttachment($run, $step->getId(), $file, 'probe.txt', 'payload', 'alice');

		try {
			$this->runServiceFor('alice')->deleteRun($run->getId());
			self::fail('a file whose delete capability cannot be probed must block the deletion');
		} catch (ConflictException $exception) {
			self::assertSame('run_delete_blocked', $exception->getReason());
		}

		self::assertArrayHasKey($run->getId(), $this->runs, 'the run is retained');
		self::assertArrayHasKey($attachment->getId(), $this->attachments, 'the attachment identity is retained');
		self::assertArrayHasKey('probe.txt', $this->folderChildrenById[$folder->getId()], 'the file is retained');
		self::assertNotContains($file->getId(), $this->deletedFileIds);
		self::assertSame([], $this->filesCleanups, 'no cleanup record on a pre-flight failure');
	}

	public function testExistingManagedFolderIsPreservedWithABlockedRecord(): void {
		$this->addUser('alice');
		// Folder node permissions are irrelevant now: Runbook never deletes it.
		[$run, $folder] = $this->addFilesRun('alice', deletable: false);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'payload']);

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertCount(1, $this->filesCleanups, 'a durable cleanup record is retained');
		$record = array_values($this->filesCleanups)[0];
		self::assertSame($run->getRunFolderFileId(), $record->getFileId());
		self::assertSame($run->getRunFolderStorageId(), $record->getStorageId());
		self::assertSame('alice', $record->getViewUid(), 'the record stores the owner-resolved view');
		self::assertSame(FilesCleanup::STATUS_BLOCKED, $record->getStatus());
		self::assertSame(FilesCleanup::REASON_REMOVAL_UNSUPPORTED, $record->getReason());
		self::assertSame(1, $record->getAttempts());
		self::assertNotContains($folder->getId(), $this->deletedFolderIds, 'the folder is never recursively deleted');
	}

	public function testUntrackedContentPreventsFolderRemovalAndIsNeverDeleted(): void {
		$this->addUser('alice');
		[$run, , $folder, ] = $this->filesRunWithEvidence();
		$folder->newFile('notes.txt', 'keep me');

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertCount(1, $this->filesCleanups);
		$record = array_values($this->filesCleanups)[0];
		self::assertSame(FilesCleanup::STATUS_BLOCKED, $record->getStatus());
		self::assertSame(FilesCleanup::REASON_NOT_EMPTY, $record->getReason());
		self::assertNotContains($folder->getId(), $this->deletedFolderIds);
		self::assertArrayHasKey('notes.txt', $this->folderChildrenById[$folder->getId()], 'untracked content is preserved');
		$notes = $folder->get('notes.txt');
		self::assertInstanceOf(\OCP\Files\File::class, $notes);
		self::assertSame('keep me', $notes->getContent());
	}

	public function testUnrelatedFoldersAndFilesAreNeverDeleted(): void {
		$this->addUser('alice');
		$this->addUserFolder('Runbook', 9000);
		$otherChildren = [];
		$this->userRootChildren['OtherRun'] = $this->makeFolderMock('OtherRun', 9001, $otherChildren);
		$unrelated = $this->addUserFile('original.txt', 9002, 'keep');
		[$run, , $folder, ] = $this->filesRunWithEvidence();

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertArrayHasKey('Runbook', $this->userRootChildren, 'the base folder is never deleted');
		self::assertArrayHasKey('OtherRun', $this->userRootChildren, 'another folder is never deleted');
		self::assertArrayHasKey('original.txt', $this->userRootChildren, 'an unrelated file is never deleted');
		self::assertNotContains(9000, $this->deletedFolderIds);
		self::assertNotContains(9001, $this->deletedFolderIds);
		self::assertNotContains($unrelated->getId(), $this->deletedFileIds);
		self::assertNotContains($folder->getId(), $this->deletedFolderIds, 'the run folder is preserved, never recursively deleted');
	}

	public function testCopiedEvidenceDeletionLeavesTheOriginalUntouched(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$original = $this->addUserFile('original.txt', 9003, 'original');
		$this->attachmentServiceFor('alice')->copyFromFiles($step->getId(), ['sourcePath' => 'original.txt']);

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertArrayHasKey('original.txt', $this->userRootChildren, 'the copied-from original is never deleted');
		self::assertSame('original', $original->getContent());
		self::assertNotContains($original->getId(), $this->deletedFileIds);
	}

	public function testTransactionFailureKeepsRunAndCreatesNoCleanupRecord(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->filesRunWithEvidence();
		$this->installRollbackTransactions();
		$this->runMapper->method('delete')->willThrowException(new \RuntimeException('boom'));

		try {
			$this->runServiceFor('alice')->deleteRun($run->getId());
			self::fail('a failed transaction must propagate');
		} catch (\RuntimeException) {
		}

		self::assertArrayHasKey($run->getId(), $this->runs, 'the run row is retained');
		self::assertArrayHasKey($attachment->getId(), $this->attachments, 'the attachment rows are retained');
		self::assertSame([], $this->filesCleanups, 'no orphan cleanup record is written');
	}

	public function testRetryClosesRecordWhenFolderIsAlreadyGone(): void {
		$this->addUser('alice');
		$record = new FilesCleanup();
		$record->setKind(FilesCleanup::KIND_FOLDER);
		$record->setStatus(FilesCleanup::STATUS_PENDING);
		$record->setViewUid('alice');
		$record->setStorageId('home::test');
		$record->setFileId(424242);
		$record->setAttempts(1);
		$record->setCreatedAt($this->now);
		$this->filesCleanupMapper->insert($record);

		$processed = $this->filesCleanupService()->processPending();

		self::assertSame(1, $processed);
		self::assertSame([], $this->filesCleanups, 'an already-gone folder closes the record');
	}

	public function testRetryPreservesTheFolderAndFinalizesTheRecordIdempotently(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$record = $this->filesCleanupService()->buildRecord($run);
		self::assertNotNull($record);
		$this->filesCleanupMapper->insert($record);

		$this->filesCleanupService()->processPending();
		self::assertCount(1, $this->filesCleanups, 'the record is finalized, not deleted');
		$processed = array_values($this->filesCleanups)[0];
		self::assertSame(FilesCleanup::STATUS_BLOCKED, $processed->getStatus());
		self::assertSame(FilesCleanup::REASON_REMOVAL_UNSUPPORTED, $processed->getReason());
		self::assertSame(1, $processed->getAttempts());
		self::assertNotContains($folder->getId(), $this->deletedFolderIds, 'the folder is preserved');

		// A blocked record is terminal: a second pass does not touch it again.
		self::assertSame(0, $this->filesCleanupService()->processPending());
		self::assertCount(1, $this->filesCleanups);
		self::assertSame(1, $processed->getAttempts());
	}

	public function testUntrackedFileAppearingAfterTheCheckIsNeverDeleted(): void {
		// The public Files API only offers recursive folder deletion, so Runbook
		// must never call it after a non-atomic emptiness check. Simulate a user
		// adding a file immediately AFTER the check (the listing reports empty,
		// then a file exists) and assert the folder and file survive.
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$folderId = 7000;
		$listing = [];
		$this->folderChildrenById[$folderId] = [];
		$raceFile = $this->makeFileMock('race.txt', 7100, $this->folderChildrenById[$folderId], 'user data');
		$this->folderChildrenById[$folderId]['race.txt'] = $raceFile;

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($folderId);
		$folder->method('getName')->willReturn('RunFolder');
		$folder->method('getPath')->willReturn('RunFolder');
		$folder->method('getStorage')->willReturn($this->userFilesFolder->getStorage());
		$folder->method('getDirectoryListing')->willReturnCallback(function () use (&$listing, $raceFile): array {
			$snapshot = $listing; // the check sees an empty folder
			$listing[] = $raceFile; // a user writes a file immediately afterwards

			return $snapshot;
		});
		$folder->method('delete')->willReturnCallback(function () use ($folderId): void {
			$this->deletedFolderIds[] = $folderId;
		});
		$this->userRootChildren['RunFolder'] = $folder;

		$run->setDestinationViewUid('alice');
		$run->setRunFolderFileId($folderId);
		$run->setRunFolderStorageId('home::test');
		$this->runs[$run->getId()] = $run;

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertNotContains($folderId, $this->deletedFolderIds, 'recursive deletion must never be issued');
		self::assertArrayHasKey('race.txt', $this->folderChildrenById[$folderId], 'the concurrently written file survives');
		self::assertSame('user data', $raceFile->getContent());
		self::assertCount(1, $this->filesCleanups);
		$record = array_values($this->filesCleanups)[0];
		self::assertSame(FilesCleanup::STATUS_BLOCKED, $record->getStatus());
		self::assertSame(FilesCleanup::REASON_REMOVAL_UNSUPPORTED, $record->getReason());
	}

	public function testRepeatedDeletionDoesNotDuplicateOrDamageCleanupState(): void {
		$this->addUser('alice');
		[$run, ] = $this->addFilesRun('alice', deletable: false);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'payload']);

		$this->runServiceFor('alice')->deleteRun($run->getId());
		self::assertCount(1, $this->filesCleanups);
		$record = array_values($this->filesCleanups)[0];
		$recordId = $record->getId();

		try {
			$this->runServiceFor('alice')->deleteRun($run->getId());
			self::fail('a second delete of a missing run must be rejected');
		} catch (NotFoundException) {
		}

		self::assertCount(1, $this->filesCleanups, 'no duplicate cleanup record is created');
		self::assertArrayHasKey($recordId, $this->filesCleanups);
		self::assertSame($run->getRunFolderFileId(), $record->getFileId(), 'the identity is preserved');
	}

	public function testUnauthorizedDeletionIsDeniedAndChangesNothing(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		[$run, , $folder, $attachment] = $this->filesRunWithEvidence('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);

		try {
			$this->runServiceFor('bob')->deleteRun($run->getId());
			self::fail('a viewer must not delete a run');
		} catch (NotFoundException) {
		}

		self::assertArrayHasKey($run->getId(), $this->runs);
		self::assertArrayHasKey($attachment->getId(), $this->attachments);
		self::assertArrayHasKey('evidence.txt', $this->folderChildrenById[$folder->getId()]);
		self::assertSame([], $this->filesCleanups);
	}

	public function testLegacyAppDataDeletionStaysCompatibleAndIsNotMigrated(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$this->seedAppDataAttachment($run, $step->getId(), 'legacy.txt', 'legacy', 'alice');
		self::assertNotSame([], $this->evidenceFiles);

		$this->runServiceFor('alice')->deleteRun($run->getId());

		self::assertArrayNotHasKey($run->getId(), $this->runs);
		self::assertSame([], $this->evidenceFiles, 'legacy AppData evidence is removed as before');
		self::assertSame([], $this->filesCleanups, 'a legacy run has no managed folder to clean up');
	}
}
