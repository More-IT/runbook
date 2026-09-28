<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Attachment;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\LegacyMigrationService;
use OCP\Files\File;
use OCP\Files\Folder;

/**
 * Legacy AppData → Files evidence migration (issue #55).
 *
 * AppData, Files and mapper behaviour is exercised through the in-memory
 * doubles; real Nextcloud storage backends, crashes and concurrency are not
 * simulated.
 */
class LegacyMigrationServiceTest extends RunTestBase {
	/**
	 * @return array{0: Run, 1: RunStep}
	 */
	private function legacyRun(string $owner = 'alice', string $uuid = 'aaaa1111-0000-4000-8000-000000000000'): array {
		$this->addUser($owner);
		$run = $this->addRun($owner);
		$run->setUuid($uuid);
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, $owner);

		return [$run, $step];
	}

	private function managedFolder(Run $run): Folder {
		return $this->runDestinationResolver()->resolveSingleFolder($run->getOwner(), (int)$run->getRunFolderFileId());
	}

	public function testMigratesWithTheDefaultDestination(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state']);
		self::assertSame('default', $run->getDestinationSource());
		self::assertNotNull($run->getDestinationMigratedAt());
		self::assertNotNull($run->getRunFolderFileId());
		self::assertSame('alice', $run->getDestinationViewUid(), 'resolved in the run owner view');

		$stored = $this->attachments[$attachment->getId()];
		self::assertSame(Attachment::STORAGE_KIND_FILES, $stored->getStorageKind());
		self::assertSame('', $stored->getStorageKey());
		self::assertSame(LegacyMigrationService::ATTACHMENT_DONE, $stored->getMigrationState());
		self::assertSame([], $this->evidenceFiles, 'the AppData source is deleted after metadata');

		$folder = $this->managedFolder($run);
		$name = $this->legacyMigrationService()->migrationTargetName($stored);
		$node = $folder->get($name);
		self::assertInstanceOf(File::class, $node);
		self::assertSame('payload', $node->getContent());
		self::assertSame($node->getId(), $stored->getFileId());
	}

	public function testTemplateDestinationTakesPrecedence(): void {
		$this->addUser('alice');
		$templateFolder = $this->addUserFolder('TemplateDest', 7001);
		$template = $this->addTemplate('alice');
		$this->setTemplateDestination($template, 'home::test', 7001, '/TemplateDest', 'alice');
		[$run, $step] = $this->legacyRun();
		$run->setTemplateId($template->getId());
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state']);
		self::assertSame('template', $run->getDestinationSource());
		self::assertSame(7001, $run->getDestinationFileId());
		self::assertTrue($templateFolder->nodeExists((string)$run->getRunFolderPath()));
	}

	public function testAdminDestinationUsedWhenNoTemplateDestination(): void {
		$this->addUser('alice');
		$adminFolder = $this->addUserFolder('AdminDest', 7002);
		$this->setAdminDestination('home::test', 7002, '/AdminDest', 'admin');
		[$run, $step] = $this->legacyRun();
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state']);
		self::assertSame('admin', $run->getDestinationSource());
		self::assertSame(7002, $run->getDestinationFileId());
		self::assertTrue($adminFolder->nodeExists((string)$run->getRunFolderPath()));
	}

	public function testInvalidConfiguredDestinationBlocksWithoutFallback(): void {
		$this->addUser('alice');
		// A configured admin reference that cannot resolve in the owner's view.
		$this->setAdminDestination('home::test', 999999, '/Missing', 'admin');
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $result['state']);
		self::assertSame('destination_no_access', $result['reason']);
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$attachment->getId()]->getStorageKind(), 'source kept');
		self::assertNotSame([], $this->evidenceFiles, 'the AppData bytes are preserved');
		self::assertNull($run->getRunFolderFileId(), 'no folder is created');
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'never falls back to the default folder');
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $run->getMigrationState());
	}

	public function testCorruptDestinationBlocks(): void {
		[$run, $step] = $this->legacyRun();
		// A coordinate set while the source is NULL is corrupt, never legacy.
		$run->setDestinationFileId(123);
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $result['state']);
		self::assertSame('destination_invalid_config', $result['reason']);
	}

	public function testMissingSourceBlocksAndKeepsTheRowAppData(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->evidenceFiles = [];

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $result['state']);
		self::assertSame(LegacyMigrationService::REASON_SOURCE_MISSING, $result['reason']);
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$attachment->getId()]->getStorageKind());
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $run->getMigrationState());
		// The destination is frozen before the per-attachment work, so a retry can
		// resume against the same managed folder.
		self::assertNotNull($run->getRunFolderFileId());
	}

	public function testUnreadableSourceBlocksAndPreservesTheSource(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->failAppDataRead = true;

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $result['state']);
		self::assertSame(LegacyMigrationService::REASON_SOURCE_UNREADABLE, $result['reason']);
		self::assertNotSame([], $this->evidenceFiles, 'the AppData source is preserved');
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$attachment->getId()]->getStorageKind());
	}

	public function testVerificationFailureBlocksAndRetainsTheCopyAndSource(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$attachment->setChecksum('deadbeef');
		$attachment->setSize(999);
		$this->attachmentMapper->update($attachment);

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $result['state']);
		self::assertSame(LegacyMigrationService::REASON_VERIFY_FAILED, $result['reason']);
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$attachment->getId()]->getStorageKind());
		self::assertNotSame([], $this->evidenceFiles, 'the AppData source is preserved');
		// The unverified copy is retained (never mistaken for verified evidence).
		$folder = $this->managedFolder($run);
		self::assertTrue($folder->nodeExists($this->legacyMigrationService()->migrationTargetName($attachment)));
	}

	public function testMetadataFailureResumesWithoutDuplicatingOrDeleting(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->failAttachmentUpdate = true;

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $result['state']);
		self::assertSame(LegacyMigrationService::REASON_METADATA_FAILED, $result['reason']);
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$attachment->getId()]->getStorageKind(), 'source row is untouched');
		self::assertNotSame([], $this->evidenceFiles, 'the AppData source is not deleted before the metadata update');

		$this->failAttachmentUpdate = false;
		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state']);
		self::assertSame(Attachment::STORAGE_KIND_FILES, $this->attachments[$attachment->getId()]->getStorageKind());
		self::assertSame([], $this->evidenceFiles, 'the source is deleted once metadata succeeds');

		$folder = $this->managedFolder($run);
		$nonMarker = array_filter(
			$folder->getDirectoryListing(),
			static fn ($node): bool => $node->getName() !== '.runbook-run.json',
		);
		self::assertCount(1, $nonMarker, 'the interrupted copy is resumed, not duplicated');
	}

	public function testSourceDeleteFailureKeepsFilesRowAndRecordsReason(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->failAppDataDelete = true;

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state'], 'the bytes are safely in Files');
		$stored = $this->attachments[$attachment->getId()];
		self::assertSame(Attachment::STORAGE_KIND_FILES, $stored->getStorageKind());
		self::assertSame(LegacyMigrationService::ATTACHMENT_DONE, $stored->getMigrationState());
		self::assertSame(LegacyMigrationService::REASON_SOURCE_DELETE_FAILED, $stored->getMigrationReason());
		self::assertNotSame([], $this->evidenceFiles, 'the leftover source is retained for manual review');
		self::assertNotSame('', $stored->getStorageKey(), 'its identity is retained to locate it');
	}

	public function testFilenameCollisionsGetDistinctSafeNames(): void {
		[$run, $step] = $this->legacyRun();
		$first = $this->seedAppDataAttachment($run, $step->getId(), 'report.txt', 'one', 'alice');
		$second = $this->seedAppDataAttachment($run, $step->getId(), 'report.txt', 'two', 'alice');
		$first->setUuid('11111111-1111-4111-8111-111111111111');
		$second->setUuid('22222222-2222-4222-8222-222222222222');
		$this->attachmentMapper->update($first);
		$this->attachmentMapper->update($second);

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state']);
		$nameA = $this->legacyMigrationService()->migrationTargetName($first);
		$nameB = $this->legacyMigrationService()->migrationTargetName($second);
		self::assertNotSame($nameA, $nameB);
		self::assertStringContainsString($first->getUuid(), $nameA, 'the name embeds the stable attachment uuid');
		$folder = $this->managedFolder($run);
		$contentA = $folder->get($nameA);
		$contentB = $folder->get($nameB);
		self::assertInstanceOf(File::class, $contentA);
		self::assertInstanceOf(File::class, $contentB);
		self::assertSame('one', $contentA->getContent());
		self::assertSame('two', $contentB->getContent());
	}

	public function testFrozenDestinationAndManagedFolderAreReused(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'FILE', false, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'alice');
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$folderId = $run->getRunFolderFileId();
		$destinationFileId = $run->getDestinationFileId();

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state']);
		self::assertSame($folderId, $run->getRunFolderFileId(), 'the frozen managed folder is reused');
		self::assertSame($destinationFileId, $run->getDestinationFileId(), 'the frozen destination is unchanged');
		self::assertTrue($folder->nodeExists($this->legacyMigrationService()->migrationTargetName($attachment)));
		self::assertSame([], $this->evidenceFiles);
	}

	public function testStatusReportsRemainingBlockedAndCompletedRuns(): void {
		[$doneRun, $doneStep] = $this->legacyRun('alice', '11111111-1111-4111-8111-111111111111');
		$this->seedAppDataAttachment($doneRun, $doneStep->getId(), 'a.txt', 'a', 'alice');
		[$blockedRun, $blockedStep] = $this->legacyRun('alice', '22222222-2222-4222-8222-222222222222');
		$this->seedAppDataAttachment($blockedRun, $blockedStep->getId(), 'b.txt', 'b', 'alice');

		$this->legacyMigrationService()->migrateRun($doneRun->getId());
		$this->failAppDataRead = true;
		$this->legacyMigrationService()->migrateRun($blockedRun->getId());
		$this->failAppDataRead = false;

		$status = $this->legacyMigrationService()->status();

		self::assertSame(1, $status['remainingAppDataAttachments']);
		self::assertCount(1, $status['runs']);
		self::assertSame($blockedRun->getId(), $status['runs'][0]['id']);
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $status['runs'][0]['state']);
		self::assertSame(1, $status['runs'][0]['appDataAttachments']);
	}

	public function testAppDataEvidenceIsReadableBeforeAndAfterMigration(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		self::assertSame('payload', $this->attachmentServiceFor('alice')->download($attachment->getId())['content'], 'readable before migration');

		$this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame('payload', $this->attachmentServiceFor('alice')->download($attachment->getId())['content'], 'readable after migration');
	}

	public function testWriteNamedNeverOverwritesAnExistingTarget(): void {
		$this->addUser('alice');
		[$run, $folder] = $this->addFilesRun('alice');
		$folder->newFile('report.txt (abc).txt', 'user data');

		try {
			$this->filesAttachmentStorage()->writeNamed($run, 'report.txt (abc).txt', 'new data');
			self::fail('an existing target must never be overwritten');
		} catch (\OCA\Runbook\Service\ConflictException $exception) {
			self::assertSame('migration_target_exists', $exception->getReason());
		}

		$existing = $folder->get('report.txt (abc).txt');
		self::assertInstanceOf(File::class, $existing);
		self::assertSame('user data', $existing->getContent(), 'the existing file is unchanged');
	}

	public function testBatchesMakeForwardProgressPastBlockedRuns(): void {
		[$runA, $stepA] = $this->legacyRun('alice', '11111111-1111-4111-8111-111111111111');
		$this->seedAppDataAttachment($runA, $stepA->getId(), 'a.txt', 'a', 'alice');
		[$runB, $stepB] = $this->legacyRun('alice', '22222222-2222-4222-8222-222222222222');
		$this->seedAppDataAttachment($runB, $stepB->getId(), 'b.txt', 'b', 'alice');
		[$runC, $stepC] = $this->legacyRun('alice', '33333333-3333-4333-8333-333333333333');
		$this->seedAppDataAttachment($runC, $stepC->getId(), 'c.txt', 'c', 'alice');
		$service = $this->legacyMigrationService();

		// Batch 1: the first two (oldest, never attempted) are attempted and block.
		$this->failAppDataRead = true;
		$batch1 = $service->migrateAll(2);
		self::assertSame(2, $batch1['processed']);
		self::assertSame(2, $batch1['blocked']);
		self::assertSame(3, $batch1['remaining']);

		// The cause is fixed; batch 2 must not re-select only the blocked pair.
		$this->failAppDataRead = false;
		$batch2 = $service->migrateAll(2);
		self::assertSame(2, $batch2['processed']);
		self::assertSame(2, $batch2['migrated'], 'the un-attempted later run and one blocked run migrate');
		self::assertSame(1, $batch2['remaining']);

		self::assertSame(LegacyMigrationService::RUN_DONE, $runC->getMigrationState(), 'the later run was reached');
		self::assertSame(LegacyMigrationService::RUN_DONE, $runA->getMigrationState(), 'a blocked run is retried after its cause is fixed');
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $runB->getMigrationState(), 'the still-unprocessed blocked run stays visible and retryable');

		// Batch 3 reaches the remaining blocked run.
		$batch3 = $service->migrateAll(2);
		self::assertSame(1, $batch3['processed']);
		self::assertSame(1, $batch3['migrated']);
		self::assertSame(0, $batch3['remaining']);
		self::assertSame(LegacyMigrationService::RUN_DONE, $runB->getMigrationState());
	}

	public function testBatchesNeitherDuplicateNorLoseEligibleRuns(): void {
		$runs = [];
		for ($i = 0; $i < 5; $i++) {
			[$run, $step] = $this->legacyRun('alice', sprintf('%08d-0000-4000-8000-000000000000', $i + 1));
			$this->seedAppDataAttachment($run, $step->getId(), 'e' . $i . '.txt', 'data' . $i, 'alice');
			$runs[] = $run;
		}
		$service = $this->legacyMigrationService();

		// Two batches of 3 over 5 runs ⇒ three then two, no repeats and no losses.
		$batch1 = $service->migrateAll(3);
		$batch2 = $service->migrateAll(3);

		self::assertSame(3, $batch1['processed']);
		self::assertSame(2, $batch2['processed']);
		self::assertSame(0, $batch2['remaining']);
		foreach ($runs as $run) {
			self::assertSame(LegacyMigrationService::RUN_DONE, $run->getMigrationState());
		}
	}

	public function testFailedSourceDeleteIsSurfacedAsResidualCleanup(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->failAppDataDelete = true;

		$result = $this->legacyMigrationService()->migrateRun($run->getId());

		self::assertSame(LegacyMigrationService::RUN_DONE, $result['state'], 'the evidence migration is complete');
		$status = $this->legacyMigrationService()->status();
		self::assertSame(0, $status['remainingAppDataAttachments'], 'no active AppData dependency');
		self::assertSame([], $status['runs'], 'the run is not an active-migration run');
		self::assertSame(1, $status['residualCleanupAttachments'], 'the residual cleanup is visible');
		self::assertCount(1, $status['cleanup']);
		self::assertSame($run->getId(), $status['cleanup'][0]['id']);
		self::assertSame(1, $status['cleanup'][0]['residualAttachments']);
		self::assertSame(LegacyMigrationService::REASON_SOURCE_DELETE_FAILED, $this->attachments[$attachment->getId()]->getMigrationReason());
	}

	public function testResidualCleanupIsRetriedAndClearedWithoutTouchingTheFilesCopy(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->failAppDataDelete = true;
		$this->legacyMigrationService()->migrateRun($run->getId());
		self::assertNotSame([], $this->evidenceFiles, 'the leftover AppData source is retained');
		$fileId = $this->attachments[$attachment->getId()]->getFileId();

		$this->failAppDataDelete = false;
		$batch = $this->legacyMigrationService()->migrateAll();

		self::assertSame(0, $batch['remaining']);
		self::assertSame([], $this->evidenceFiles, 'the residual source is deleted on retry');
		$status = $this->legacyMigrationService()->status();
		self::assertSame(0, $status['residualCleanupAttachments']);
		self::assertSame([], $status['cleanup']);
		self::assertSame('', $this->attachments[$attachment->getId()]->getStorageKey());
		self::assertSame($fileId, $this->attachments[$attachment->getId()]->getFileId(), 'the verified Files copy is untouched');
	}

	public function testSuccessfulMigrationReportsNoResidualCleanup(): void {
		[$run, $step] = $this->legacyRun();
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$this->legacyMigrationService()->migrateRun($run->getId());

		$status = $this->legacyMigrationService()->status();
		self::assertSame(0, $status['remainingAppDataAttachments']);
		self::assertSame(0, $status['residualCleanupAttachments']);
		self::assertSame([], $status['runs']);
		self::assertSame([], $status['cleanup']);
	}

	public function testBatchesRotateUnderEqualAttemptTimestamps(): void {
		[$a, $stepA] = $this->legacyRun('alice', '11111111-1111-4111-8111-111111111111');
		$this->seedAppDataAttachment($a, $stepA->getId(), 'a.txt', 'a', 'alice');
		[$b, $stepB] = $this->legacyRun('alice', '22222222-2222-4222-8222-222222222222');
		$this->seedAppDataAttachment($b, $stepB->getId(), 'b.txt', 'b', 'alice');
		[$c, $stepC] = $this->legacyRun('alice', '33333333-3333-4333-8333-333333333333');
		$this->seedAppDataAttachment($c, $stepC->getId(), 'c.txt', 'c', 'alice');
		[$d, $stepD] = $this->legacyRun('alice', '44444444-4444-4444-8444-444444444444');
		$this->seedAppDataAttachment($d, $stepD->getId(), 'd.txt', 'd', 'alice');
		$service = $this->legacyMigrationService();

		// Every attempt in this test uses the same fixed clock, so all
		// `migration_attempted_at` values are equal by construction.
		$this->failAppDataRead = true;
		$service->migrateAll(2);
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $a->getMigrationState());
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $b->getMigrationState());
		self::assertNull($c->getMigrationState(), 'c was not attempted in the first batch');

		// The cause is fixed; the batch must rotate to the later pair, not
		// re-select the tied lower ids.
		$this->failAppDataRead = false;
		$service->migrateAll(2);
		self::assertSame(LegacyMigrationService::RUN_DONE, $c->getMigrationState(), 'the later candidate was reached');
		self::assertSame(LegacyMigrationService::RUN_DONE, $d->getMigrationState());
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $a->getMigrationState());
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $b->getMigrationState());

		// The cursor wraps and retries the previously blocked pair.
		$service->migrateAll(2);
		self::assertSame(LegacyMigrationService::RUN_DONE, $a->getMigrationState());
		self::assertSame(LegacyMigrationService::RUN_DONE, $b->getMigrationState());
	}

	public function testBatchesAreDeterministicForTheSamePersistedState(): void {
		[$a, $stepA] = $this->legacyRun('alice', '11111111-1111-4111-8111-111111111111');
		$this->seedAppDataAttachment($a, $stepA->getId(), 'a.txt', 'a', 'alice');
		[$b, $stepB] = $this->legacyRun('alice', '22222222-2222-4222-8222-222222222222');
		$this->seedAppDataAttachment($b, $stepB->getId(), 'b.txt', 'b', 'alice');
		[$c, $stepC] = $this->legacyRun('alice', '33333333-3333-4333-8333-333333333333');
		$this->seedAppDataAttachment($c, $stepC->getId(), 'c.txt', 'c', 'alice');
		$service = $this->legacyMigrationService();

		$this->failAppDataRead = true;
		$service->migrateAll(2);
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $a->getMigrationState());
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $b->getMigrationState());
		self::assertNull($c->getMigrationState());

		// Replay from the same durable cursor state: the same candidates are
		// selected and the untouched candidate stays untouched.
		$this->appConfigValues['migration_cursor'] = 0;
		$service->migrateAll(2);
		self::assertNull($c->getMigrationState(), 'the same state yields the same selection');
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $a->getMigrationState());
		self::assertSame(LegacyMigrationService::RUN_BLOCKED, $b->getMigrationState());
	}

	public function testResidualCleanupOnlyCandidateParticipatesInRotation(): void {
		[$x, $stepX] = $this->legacyRun('alice', '11111111-1111-4111-8111-111111111111');
		$this->seedAppDataAttachment($x, $stepX->getId(), 'x.txt', 'x', 'alice');
		$this->failAppDataDelete = true;
		$this->legacyMigrationService()->migrateRun($x->getId());
		$this->failAppDataDelete = false;

		[$y, $stepY] = $this->legacyRun('alice', '22222222-2222-4222-8222-222222222222');
		$yAttachment = $this->seedAppDataAttachment($y, $stepY->getId(), 'y.txt', 'y', 'alice');

		$service = $this->legacyMigrationService();
		$this->appConfigValues['migration_cursor'] = 0;

		// First batch selects the residual-cleanup-only candidate and clears it.
		$first = $service->migrateAll(1);
		self::assertSame(1, $first['processed']);
		self::assertSame(0, $service->status()['residualCleanupAttachments']);
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$yAttachment->getId()]->getStorageKind(), 'the other candidate is untouched in this batch');

		// Second batch rotates to the active AppData candidate.
		$second = $service->migrateAll(1);
		self::assertSame(1, $second['processed']);
		self::assertSame(LegacyMigrationService::RUN_DONE, $y->getMigrationState());
	}

	/** Stable lock key used by LegacyMigrationService::migrateAll(). */
	private const LOCK_KEY = 'runbook/legacy-migration';

	public function testContendedBatchReturnsBusyWithoutSelectingOrRecording(): void {
		[$run, $step] = $this->legacyRun();
		$attachment = $this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		// Another worker owns the migration lock.
		$this->heldLocks[self::LOCK_KEY] = \OCP\Lock\ILockingProvider::LOCK_EXCLUSIVE;

		$result = $this->legacyMigrationService()->migrateAll(1);

		self::assertTrue($result['busy']);
		self::assertSame(0, $result['processed']);
		self::assertNull($run->getMigrationState(), 'a contended batch must not attempt any run');
		self::assertSame(Attachment::STORAGE_KIND_APPDATA, $this->attachments[$attachment->getId()]->getStorageKind());
		self::assertArrayNotHasKey('migration_cursor', $this->appConfigValues, 'the cursor is not advanced while contended');
	}

	public function testLockIsReleasedAfterASuccessfulBatch(): void {
		[$run, $step] = $this->legacyRun();
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');

		$result = $this->legacyMigrationService()->migrateAll(1);

		self::assertFalse($result['busy']);
		self::assertSame(1, $result['processed']);
		self::assertArrayNotHasKey(self::LOCK_KEY, $this->heldLocks, 'the lock is released after success');
	}

	public function testLockIsReleasedAfterAThrownFailure(): void {
		[$run, $step] = $this->legacyRun();
		$this->seedAppDataAttachment($run, $step->getId(), 'evidence.txt', 'payload', 'alice');
		$this->attachmentMapper->method('countByStorageKind')->willThrowException(new \RuntimeException('boom'));

		try {
			$this->legacyMigrationService()->migrateAll(1);
			self::fail('the thrown failure must propagate');
		} catch (\RuntimeException) {
		}

		self::assertArrayNotHasKey(self::LOCK_KEY, $this->heldLocks, 'the lock is released on failure');
	}

	public function testRotationContinuesAcrossSeparateBatchesAndLockIsFreed(): void {
		[$a, $stepA] = $this->legacyRun('alice', '11111111-1111-4111-8111-111111111111');
		$this->seedAppDataAttachment($a, $stepA->getId(), 'a.txt', 'a', 'alice');
		[$b, $stepB] = $this->legacyRun('alice', '22222222-2222-4222-8222-222222222222');
		$this->seedAppDataAttachment($b, $stepB->getId(), 'b.txt', 'b', 'alice');
		[$c, $stepC] = $this->legacyRun('alice', '33333333-3333-4333-8333-333333333333');
		$this->seedAppDataAttachment($c, $stepC->getId(), 'c.txt', 'c', 'alice');
		$service = $this->legacyMigrationService();

		$first = $service->migrateAll(2);
		self::assertSame(2, $first['processed']);
		self::assertArrayNotHasKey(self::LOCK_KEY, $this->heldLocks);

		$second = $service->migrateAll(2);
		self::assertSame(1, $second['processed'], 'the second batch selects the remaining candidate');
		self::assertSame(0, $second['remaining']);
		self::assertSame(LegacyMigrationService::RUN_DONE, $c->getMigrationState());
		self::assertArrayNotHasKey(self::LOCK_KEY, $this->heldLocks);
	}
}
