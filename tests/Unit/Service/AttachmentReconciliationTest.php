<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AttachmentReconciliationService;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\FilesRootProvider;
use OCA\Runbook\Service\ValidationException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\Storage\IStorage;
use OCP\Files\StorageNotAvailableException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Out-of-band Files reconciliation for tracked evidence (issue #52).
 *
 * The Files view is the shared in-memory tree from {@see RunTestBase}, so the
 * documented transitions (rename/move/delete/storage change/access loss) can be
 * forced by mutating the tree between reconciliation calls.
 */
class AttachmentReconciliationTest extends RunTestBase {
	/**
	 * @return array{0: \OCA\Runbook\Db\Run, 1: \OCA\Runbook\Db\RunStep, 2: Folder, 3: \OCA\Runbook\Db\Attachment}
	 */
	private function presentEvidence(string $type = 'CHECK', bool $required = true): array {
		[$run, $step, $folder] = $this->filesRunWithStepOfType('alice', $type, $required);
		$attachment = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'payload']);

		return [$run, $step, $folder, $attachment];
	}

	private function stateFor(\OCA\Runbook\Db\Run $run, \OCA\Runbook\Db\Attachment $attachment): string {
		return $this->attachmentReconciliationService()->stateFor($run, $attachment);
	}

	public function testPresentFileReconcilesAsPresent(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();

		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $attachment));
	}

	public function testDeletedFileReconcilesAsMissing(): void {
		$this->addUser('alice');
		[$run, , $folder, $attachment] = $this->presentEvidence();
		$folder->get('evidence.txt')->delete();

		self::assertSame(AttachmentReconciliationService::MISSING, $this->stateFor($run, $attachment));
	}

	public function testDeletedManagedFolderReconcilesAsMissing(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		unset($this->userRootChildren['RunFolder']);

		self::assertSame(AttachmentReconciliationService::MISSING, $this->stateFor($run, $attachment));
	}

	public function testFileMovedWithinTheManagedFolderStaysPresent(): void {
		$this->addUser('alice');
		[$run, , $folder, $attachment] = $this->presentEvidence();
		$sub = $folder->newFolder('sub');
		$managed = &$this->folderChildrenById[$folder->getId()];
		$file = $managed['evidence.txt'];
		unset($managed['evidence.txt']);
		$subChildren = &$this->folderChildrenById[$sub->getId()];
		$subChildren['evidence.txt'] = $file;

		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $attachment));
	}

	public function testFileMovedOutsideTheManagedFolderIsOutOfScope(): void {
		$this->addUser('alice');
		[$run, , $folder, $attachment] = $this->presentEvidence();
		$managed = &$this->folderChildrenById[$folder->getId()];
		$file = $managed['evidence.txt'];
		unset($managed['evidence.txt']);
		$this->userRootChildren['moved.txt'] = $file;

		self::assertSame(AttachmentReconciliationService::OUT_OF_SCOPE, $this->stateFor($run, $attachment));
	}

	public function testFileOnAnotherStorageIsUnavailable(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$attachment->setStorageId('other::storage');

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testAmbiguousManagedFolderIsUnavailable(): void {
		$this->addUser('alice');
		[$run, , $folder, $attachment] = $this->presentEvidence();
		$children = [];
		$this->userRootChildren['RunFolderDuplicate'] = $this->makeFolderMock('RunFolderDuplicate', $folder->getId(), $children);

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testIncompleteManagedFolderIdentityIsUnavailable(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$run->setRunFolderStorageId('');

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testPresentFileRefreshesStaleAdvisoryMetadata(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$attachment->setPath('/stale/path');
		$attachment->setMountType('stale');

		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $attachment));
		self::assertSame('evidence.txt', $attachment->getPath());
		self::assertSame('local', $attachment->getMountType());
		self::assertSame('evidence.txt', $this->attachments[$attachment->getId()]->getPath());
	}

	public function testLegacyAppDataAttachmentReconcilesAsPresent(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'legacy.txt', 'x', 'alice');

		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $attachment));
	}

	public function testPresentEvidenceCanCompleteARequiredFileStep(): void {
		$this->addUser('alice');
		[, $step] = $this->presentEvidence('FILE', true);

		$completed = $this->runStepServiceFor('alice')->complete($step->getId(), []);

		self::assertSame(RunStepStatus::Completed->value, $completed->getStatus());
	}

	public function testMissingEvidenceCannotCompleteARequiredFileStep(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->presentEvidence('FILE', true);
		$folder->get('evidence.txt')->delete();

		try {
			$this->runStepServiceFor('alice')->complete($step->getId(), []);
			self::fail('missing evidence must not allow completion');
		} catch (ValidationException $exception) {
			self::assertSame('file_evidence_missing', $exception->getReason());
		}
	}

	public function testOutOfScopeEvidenceCannotCompleteARequiredFileStep(): void {
		$this->addUser('alice');
		[, $step, $folder] = $this->presentEvidence('FILE', true);
		$managed = &$this->folderChildrenById[$folder->getId()];
		$file = $managed['evidence.txt'];
		unset($managed['evidence.txt']);
		$this->userRootChildren['moved.txt'] = $file;

		try {
			$this->runStepServiceFor('alice')->complete($step->getId(), []);
			self::fail('out-of-scope evidence must not allow completion');
		} catch (ValidationException $exception) {
			self::assertSame('file_evidence_missing', $exception->getReason());
		}
	}

	public function testDownloadMissingEvidenceFailsClosed(): void {
		$this->addUser('alice');
		[, , $folder, $attachment] = $this->presentEvidence();
		$folder->get('evidence.txt')->delete();

		try {
			$this->attachmentServiceFor('alice')->download($attachment->getId());
			self::fail('missing evidence must not download');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_missing', $exception->getReason());
		}
	}

	public function testDownloadOutOfScopeEvidenceFailsClosed(): void {
		$this->addUser('alice');
		[, , $folder, $attachment] = $this->presentEvidence();
		$managed = &$this->folderChildrenById[$folder->getId()];
		$file = $managed['evidence.txt'];
		unset($managed['evidence.txt']);
		$this->userRootChildren['moved.txt'] = $file;

		try {
			$this->attachmentServiceFor('alice')->download($attachment->getId());
			self::fail('out-of-scope evidence must not download');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_out_of_scope', $exception->getReason());
		}
	}

	public function testDownloadUnavailableEvidenceFailsClosed(): void {
		$this->addUser('alice');
		[, , , $attachment] = $this->presentEvidence();
		$attachment->setStorageId('other::storage');

		try {
			$this->attachmentServiceFor('alice')->download($attachment->getId());
			self::fail('unavailable evidence must not download');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}
	}

	public function testRunDetailFlagsDegradedEvidenceOnlyWhenNonPresent(): void {
		$this->addUser('alice');
		[$run, , $folder] = $this->presentEvidence();

		self::assertFalse($this->runServiceFor('alice')->getRunDetail($run->getId())['evidenceDegraded']);

		$folder->get('evidence.txt')->delete();
		self::assertTrue($this->runServiceFor('alice')->getRunDetail($run->getId())['evidenceDegraded']);
	}

	public function testListingReportsFileStateAndDegraded(): void {
		$this->addUser('alice');
		[$run, , $folder] = $this->presentEvidence();
		$described = $this->attachmentServiceFor('alice')->describeForRun($run->getId());
		self::assertFalse($described['degraded']);
		self::assertSame('present', $described['attachments'][0]['fileState']);

		$folder->get('evidence.txt')->delete();
		$described = $this->attachmentServiceFor('alice')->describeForRun($run->getId());
		self::assertTrue($described['degraded']);
		self::assertSame('missing', $described['attachments'][0]['fileState']);
	}

	public function testAmbiguousInScopeFileIsUnavailable(): void {
		$this->addUser('alice');
		[$run, , $folder, $attachment] = $this->presentEvidence();
		// Two nodes with the tracked id inside the managed folder: fail closed.
		$childrenA = [];
		$childrenB = [];
		$managed = &$this->folderChildrenById[$folder->getId()];
		$managed['a.txt'] = $this->makeFileMock('a.txt', 7001, $childrenA, 'a');
		$managed['b.txt'] = $this->makeFileMock('b.txt', 7001, $childrenB, 'b');
		$attachment->setFileId(7001);
		$attachment->setStorageId('home::test');

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testAnInScopeNodeVisibleFromTheRootIsNotCountedTwice(): void {
		$this->addUser('alice');
		// The managed-folder lookup and the diagnostic root lookup would both see
		// the node; stage 1 alone is authoritative, so the state stays present
		// (not a false `unavailable`).
		[$run, , , $attachment] = $this->presentEvidence();

		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $attachment));
	}

	public function testMissingEvidenceFileCanBeReplacedWhileTheManagedFolderRemains(): void {
		$this->addUser('alice');
		[$run, $step, $folder, $attachment] = $this->presentEvidence('FILE', true);
		$folder->get('evidence.txt')->delete();
		self::assertSame(AttachmentReconciliationService::MISSING, $this->stateFor($run, $attachment));

		$replacement = $this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'replacement.txt', 'content' => 'new']);

		self::assertSame('replacement.txt', $replacement->getFilename());
		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $replacement));
	}

	public function testMissingManagedFolderBlocksUploadWithoutAnyWrite(): void {
		$this->addUser('alice');
		[, $step] = $this->filesRunWithStepOfType('alice', 'FILE', true);
		// The run still holds a complete folder identity, but the folder node is gone.
		unset($this->userRootChildren['RunFolder']);

		try {
			$this->attachmentServiceFor('alice')->upload($step->getId(), ['name' => 'evidence.txt', 'content' => 'payload']);
			self::fail('upload must fail closed when the managed folder is missing');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}

		self::assertCount(0, $this->attachments, 'no attachment row is created');
		self::assertSame([], $this->evidenceFiles, 'no AppData file is written');
		self::assertArrayNotHasKey('RunFolder', $this->userRootChildren, 'the managed folder is never silently recreated');
	}

	private function fileNodeWithStorage(string $name, int $id, string $storageId): Node&MockObject {
		/** @var File&MockObject $file */
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn($name);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn('');
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn($storageId);
		$file->method('getStorage')->willReturn($storage);

		return $file;
	}

	public function testMultipleSameStorageDiagnosticCandidatesAreUnavailable(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		// Stage 1 finds nothing in the managed folder; the diagnostic root lookup
		// then finds two nodes with the exact tracked identity: ambiguous, fail
		// closed (never pick a first result).
		$attachment->setFileId(9001);
		$attachment->setStorageId('home::test');
		$this->userRootChildren['dup-a.txt'] = $this->fileNodeWithStorage('dup-a.txt', 9001, 'home::test');
		$this->userRootChildren['dup-b.txt'] = $this->fileNodeWithStorage('dup-b.txt', 9001, 'home::test');

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testExactlyOneSameStorageDiagnosticCandidateIsOutOfScope(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$attachment->setFileId(9002);
		$attachment->setStorageId('home::test');
		$this->userRootChildren['moved.txt'] = $this->fileNodeWithStorage('moved.txt', 9002, 'home::test');

		self::assertSame(AttachmentReconciliationService::OUT_OF_SCOPE, $this->stateFor($run, $attachment));
	}

	public function testMixedStorageDiagnosticCandidatesWithSingleSameStorageAreOutOfScope(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		// One authoritative same-storage candidate + unrelated other-storage
		// nodes: the other-storage nodes are not candidates and never change the
		// classification.
		$attachment->setFileId(9003);
		$attachment->setStorageId('home::test');
		$this->userRootChildren['moved.txt'] = $this->fileNodeWithStorage('moved.txt', 9003, 'home::test');
		$this->userRootChildren['elsewhere.txt'] = $this->fileNodeWithStorage('elsewhere.txt', 9003, 'other::storage');

		self::assertSame(AttachmentReconciliationService::OUT_OF_SCOPE, $this->stateFor($run, $attachment));
	}

	public function testMixedStorageDiagnosticCandidatesWithMultipleSameStorageAreUnavailable(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$attachment->setFileId(9004);
		$attachment->setStorageId('home::test');
		$this->userRootChildren['dup-a.txt'] = $this->fileNodeWithStorage('dup-a.txt', 9004, 'home::test');
		$this->userRootChildren['dup-b.txt'] = $this->fileNodeWithStorage('dup-b.txt', 9004, 'home::test');
		$this->userRootChildren['elsewhere.txt'] = $this->fileNodeWithStorage('elsewhere.txt', 9004, 'other::storage');

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testOnlyAnotherStorageDiagnosticCandidatesAreUnavailable(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$attachment->setFileId(9005);
		$attachment->setStorageId('home::test');
		$this->userRootChildren['elsewhere.txt'] = $this->fileNodeWithStorage('elsewhere.txt', 9005, 'other::storage');

		self::assertSame(AttachmentReconciliationService::UNAVAILABLE, $this->stateFor($run, $attachment));
	}

	public function testNoDiagnosticCandidatesAreMissing(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$attachment->setFileId(9006);
		$attachment->setStorageId('home::test');

		self::assertSame(AttachmentReconciliationService::MISSING, $this->stateFor($run, $attachment));
	}

	public function testStageOneMatchWinsAndDiagnosticOverlapIsNotMerged(): void {
		$this->addUser('alice');
		[$run, , , $attachment] = $this->presentEvidence();
		$id = $attachment->getFileId();
		self::assertNotNull($id);
		// The node is visible both inside the managed folder (stage 1) and again
		// from the root (diagnostic). Stage 1 is authoritative, so it stays
		// present and is never double-counted into a false ambiguity.
		$this->userRootChildren['also-at-root.txt'] = $this->fileNodeWithStorage('also-at-root.txt', $id, 'home::test');

		self::assertSame(AttachmentReconciliationService::PRESENT, $this->stateFor($run, $attachment));
	}

	public function testManagedFolderStateDistinguishesAvailability(): void {
		$this->addUser('alice');
		$service = $this->attachmentReconciliationService();

		$legacy = $this->addRun('alice');
		self::assertSame(AttachmentReconciliationService::FOLDER_NOT_APPLICABLE, $service->managedFolderState($legacy));

		[$run, , , ] = $this->presentEvidence();
		self::assertSame(AttachmentReconciliationService::FOLDER_AVAILABLE, $service->managedFolderState($run));

		unset($this->userRootChildren['RunFolder']);
		self::assertSame(AttachmentReconciliationService::FOLDER_MISSING, $service->managedFolderState($run));
	}

	public function testRunDetailExposesManagedFolderState(): void {
		$this->addUser('alice');
		[$run, , , ] = $this->presentEvidence();

		self::assertSame(
			AttachmentReconciliationService::FOLDER_AVAILABLE,
			$this->runServiceFor('alice')->getRunDetail($run->getId())['managedFolderState'],
		);

		unset($this->userRootChildren['RunFolder']);
		self::assertSame(
			AttachmentReconciliationService::FOLDER_MISSING,
			$this->runServiceFor('alice')->getRunDetail($run->getId())['managedFolderState'],
		);
	}

	public function testManagedFolderStateUnavailableForIncompleteIdentity(): void {
		$this->addUser('alice');
		[$run] = $this->addFilesRun('alice');
		$service = $this->attachmentReconciliationService();

		// A set file id without a storage id (and vice versa) is an incomplete
		// identity: fail closed, never treated as legacy/not-applicable.
		$run->setRunFolderStorageId('');
		self::assertSame(AttachmentReconciliationService::FOLDER_UNAVAILABLE, $service->managedFolderState($run));

		$run->setRunFolderStorageId('home::test');
		$run->setRunFolderFileId(null);
		self::assertSame(AttachmentReconciliationService::FOLDER_UNAVAILABLE, $service->managedFolderState($run));
	}

	public function testManagedFolderStateUnavailableForAmbiguousFolder(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		// Two nodes with the exact folder identity: ambiguous, fail closed.
		$children = [];
		$this->userRootChildren['RunFolderDuplicate'] = $this->makeFolderMock('RunFolderDuplicate', $folder->getId(), $children);

		self::assertSame(AttachmentReconciliationService::FOLDER_UNAVAILABLE, $this->attachmentReconciliationService()->managedFolderState($run));
	}

	public function testManagedFolderStateUnavailableWhenFolderIsNotWritable(): void {
		$this->addUser('alice');
		[$run] = $this->addFilesRun('alice', creatable: false);

		self::assertSame(AttachmentReconciliationService::FOLDER_UNAVAILABLE, $this->attachmentReconciliationService()->managedFolderState($run));
	}

	public function testManagedFolderStateUnavailableOnStorageError(): void {
		$this->addUser('alice');
		[$run] = $this->addFilesRun('alice');
		$throwing = $this->createMock(Folder::class);
		/** @var StorageNotAvailableException&MockObject $unavailable */
		$unavailable = $this->getMockBuilder(StorageNotAvailableException::class)->disableOriginalConstructor()->getMock();
		$throwing->method('getById')->willThrowException($unavailable);
		$provider = $this->createMock(FilesRootProvider::class);
		$provider->method('getUserFolder')->willReturn($throwing);
		$service = new AttachmentReconciliationService($provider, $this->attachmentMapper, new NullLogger());

		self::assertSame(AttachmentReconciliationService::FOLDER_UNAVAILABLE, $service->managedFolderState($run));
	}
}
