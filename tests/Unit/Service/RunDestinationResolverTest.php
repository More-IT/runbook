<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\DestinationReference;
use OCA\Runbook\Service\FilesRootProvider;
use OCA\Runbook\Service\ResolvedRunDestination;
use OCA\Runbook\Service\RunDestinationResolver;
use OCA\Runbook\Service\ValidationException;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Files\Storage\IStorage;
use OCP\Files\StorageNotAvailableException;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\NullLogger;

/**
 * Behaviour tests for the default Files destination resolver (issue #46).
 *
 * The Files view is an in-memory mock tree provided by {@see RunTestBase}; the
 * locking provider rejects concurrent acquisitions, matching Nextcloud's
 * semantics.
 */
class RunDestinationResolverTest extends RunTestBase {
	private function resolver(): RunDestinationResolver {
		return $this->runDestinationResolver();
	}

	/**
	 * @param class-string $class
	 */
	private function assertRejected(string $uid, string $uuid, string $title, string $expected, string $class = ConflictException::class): void {
		try {
			$this->resolver()->resolveForRun($uid, $uuid, $title);
			self::fail('expected rejection with ' . $expected);
		} catch (\Throwable $exception) {
			self::assertInstanceOf($class, $exception);
			if ($exception instanceof ConflictException || $exception instanceof ValidationException) {
				self::assertSame($expected, $exception->getReason());
			}
		}
	}

	public function testCreatesDefaultRunbookBaseFolderAndManagedRunFolder(): void {
		$this->addUser('alice');

		$destination = $this->resolver()->resolveForRun('alice', 'abcd1234-0000-4000-8000-000000000000', 'Deploy release');

		self::assertSame('default', $destination->source);
		self::assertSame('alice', $destination->viewUid);
		self::assertSame('home::test', $destination->storageId);
		self::assertSame('home::test', $destination->folderStorageId);
		self::assertArrayHasKey('Runbook', $this->userRootChildren);
		self::assertSame($this->userRootChildren['Runbook']->getId(), $destination->fileId);
		self::assertTrue($destination->folderCreated);
		self::assertSame('Deploy release (abcd1234-0000-4000-8000-000000000000)', $destination->folderPath);
		self::assertSame('local', $destination->mountType);
		self::assertSame(7, $destination->storageRootId);
		self::assertNull($destination->mountId);
		self::assertSame(1, $destination->numericStorageId);
		self::assertSame($this->heldLocks, [], 'locks are released after resolution');
		self::assertSame(
			['format' => RunDestinationResolver::MARKER_FORMAT, 'version' => RunDestinationResolver::MARKER_VERSION, 'uuid' => 'abcd1234-0000-4000-8000-000000000000'],
			json_decode($this->markerContent($destination), true),
			'a valid ownership marker is written into the managed folder',
		);
	}

	public function testReusesExistingRunbookBaseFolder(): void {
		$this->addUser('alice');

		$first = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'One');
		$second = $this->resolver()->resolveForRun('alice', 'bbbb2222-0000-4000-8000-000000000000', 'Two');

		self::assertSame($first->fileId, $second->fileId, 'the same base folder is reused');
		self::assertCount(2, $this->baseFolder()->getDirectoryListing());
	}

	public function testRejectsConflictingNonFolderNamedRunbook(): void {
		$this->addUser('alice');
		$this->userRootChildren['Runbook'] = $this->createMock(File::class);

		$this->assertRejected('alice', 'u', 'T', 'destination_invalid', ValidationException::class);
	}

	public function testCreatesDistinctFoldersPerRun(): void {
		$this->addUser('alice');

		$first = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');
		$second = $this->resolver()->resolveForRun('alice', 'bbbb2222-0000-4000-8000-000000000000', 'Deploy');

		self::assertNotSame($first->folderFileId, $second->folderFileId);
		self::assertNotSame($first->folderPath, $second->folderPath);
		self::assertCount(2, $this->baseFolder()->getDirectoryListing());
	}

	public function testRetriedStartReusesTheSameRunFolder(): void {
		$this->addUser('alice');

		$first = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');
		$retry = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');

		self::assertTrue($first->folderCreated);
		self::assertFalse($retry->folderCreated, 'a retry reuses the existing run folder');
		self::assertSame($first->folderFileId, $retry->folderFileId);
		self::assertCount(1, $this->baseFolder()->getDirectoryListing());
		self::assertSame('aaaa1111-0000-4000-8000-000000000000', json_decode($this->markerContent($retry), true)['uuid'] ?? null);
	}

	public function testMissingOwnerIsRejected(): void {
		$this->assertRejected('ghost', 'u', 'T', 'destination_owner_missing');
	}

	public function testUnavailableFilesViewIsRejected(): void {
		$this->addUser('alice');
		/** @var StorageNotAvailableException&MockObject $unavailable */
		$unavailable = $this->getMockBuilder(StorageNotAvailableException::class)
			->disableOriginalConstructor()
			->getMock();
		$provider = $this->createMock(FilesRootProvider::class);
		$provider->method('getUserFolder')->willThrowException($unavailable);
		$resolver = new RunDestinationResolver($provider, $this->lockingProvider, $this->userManager, $this->adminSettings, new NullLogger());

		try {
			$resolver->resolveForRun('alice', 'u', 'T');
			self::fail('expected destination_unavailable');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}
	}

	public function testBaseFolderMustBeCreatable(): void {
		$this->addUser('alice');
		$children = [];
		$this->userRootChildren['Runbook'] = $this->makeFolderMock('Runbook', 42, $children, false);

		$this->assertRejected('alice', 'u', 'T', 'destination_not_writable');
	}

	public function testQuotaFailureIsReported(): void {
		$this->addUser('alice');
		$this->userFilesFolder->method('newFolder')->willThrowException(new NotEnoughSpaceException('quota'));

		$this->assertRejected('alice', 'u', 'T', 'destination_quota_exceeded');
	}

	public function testAmbiguousIdResolutionFailsClosed(): void {
		$this->addUser('alice');
		$a = [];
		$b = [];
		$one = $this->makeFolderMock('one', 101, $a);
		$two = $this->makeFolderMock('two', 102, $b);
		$view = $this->createMock(Folder::class);
		$view->method('getById')->willReturn([$one, $two]);

		try {
			$this->resolverWithView($view)->resolveSingleFolder('alice', 101);
			self::fail('expected destination_ambiguous');
		} catch (ConflictException $exception) {
			self::assertSame('destination_ambiguous', $exception->getReason());
		}
	}

	public function testMissingIdResolutionIsUnavailable(): void {
		$this->addUser('alice');
		$view = $this->createMock(Folder::class);
		$view->method('getById')->willReturn([]);

		try {
			$this->resolverWithView($view)->resolveSingleFolder('alice', 999);
			self::fail('expected destination_unavailable');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}
	}

	private function resolverWithView(Folder $view): RunDestinationResolver {
		$provider = $this->createMock(FilesRootProvider::class);
		$provider->method('getUserFolder')->willReturn($view);

		return new RunDestinationResolver($provider, $this->lockingProvider, $this->userManager, $this->adminSettings, new NullLogger());
	}

	/**
	 * Create a `Runbook` base folder that already contains one unrelated
	 * subfolder with the given name, mimicking a folder left by another actor.
	 */
	private function plantBaseWithFolder(string $folderName): void {
		$baseChildren = [];
		$childChildren = [];
		$baseChildren[$folderName] = $this->makeFolderMock($folderName, 9101, $childChildren);
		$this->userRootChildren['Runbook'] = $this->makeFolderMock('Runbook', 9100, $baseChildren);
	}

	private function folderOf(ResolvedRunDestination $destination): Folder {
		$folder = $this->baseFolder()->get((string)$destination->folderPath);
		self::assertInstanceOf(Folder::class, $folder);

		return $folder;
	}

	private function markerFile(ResolvedRunDestination $destination): File {
		$marker = $this->folderOf($destination)->get(RunDestinationResolver::MARKER_FILE_NAME);
		self::assertInstanceOf(File::class, $marker);

		return $marker;
	}

	private function markerContent(ResolvedRunDestination $destination): string {
		return $this->markerFile($destination)->getContent();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function markerPayload(string $uuid, string $format = RunDestinationResolver::MARKER_FORMAT, int $version = RunDestinationResolver::MARKER_VERSION): array {
		return ['format' => $format, 'version' => $version, 'uuid' => $uuid];
	}

	public function testFolderNameIsSanitizedAndBounded(): void {
		$this->addUser('alice');
		$resolver = $this->resolver();
		$uuid = 'abcd1234-0000-4000-8000-000000000000';

		self::assertSame('Deploy (' . $uuid . ')', $resolver->folderName('Deploy', $uuid));
		self::assertSame('a-b (' . $uuid . ')', $resolver->folderName('a/b', $uuid));
		self::assertSame('Run (' . $uuid . ')', $resolver->folderName('', $uuid));
		self::assertSame('Run (' . $uuid . ')', $resolver->folderName('../..', $uuid));

		$long = $resolver->folderName(str_repeat('x', 400), $uuid);
		self::assertLessThanOrEqual(255, mb_strlen($long));
		self::assertStringEndsWith('(' . $uuid . ')', $long, 'the full-UUID discriminator is never truncated');
	}

	public function testDifferentUuidsWithSamePrefixAndTitleGetDistinctFolders(): void {
		$this->addUser('alice');

		$first = $this->resolver()->resolveForRun('alice', 'abcd1234-0000-4000-8000-000000000001', 'Deploy');
		$second = $this->resolver()->resolveForRun('alice', 'abcd1234-0000-4000-8000-000000000002', 'Deploy');

		self::assertNotSame($first->folderFileId, $second->folderFileId);
		self::assertNotSame($first->folderPath, $second->folderPath);
		self::assertSame('Deploy (abcd1234-0000-4000-8000-000000000001)', $first->folderPath);
		self::assertSame('Deploy (abcd1234-0000-4000-8000-000000000002)', $second->folderPath);
		self::assertCount(2, $this->baseFolder()->getDirectoryListing());
	}

	public function testPreExistingCollidingDisplayNameFolderIsNotAdopted(): void {
		$this->addUser('alice');

		// A base folder that already holds an unrelated folder using the old
		// short-UUID display name (`Deploy (abcd1234)`).
		$baseChildren = [];
		$unrelatedChildren = [];
		$baseChildren['Deploy (abcd1234)'] = $this->makeFolderMock('Deploy (abcd1234)', 9001, $unrelatedChildren);
		$this->userRootChildren['Runbook'] = $this->makeFolderMock('Runbook', 9000, $baseChildren);

		$destination = $this->resolver()->resolveForRun('alice', 'abcd1234-0000-4000-8000-000000000000', 'Deploy');

		self::assertNotSame(9001, $destination->folderFileId, 'the unrelated folder is not adopted');
		self::assertTrue($destination->folderCreated);
		self::assertSame('Deploy (abcd1234-0000-4000-8000-000000000000)', $destination->folderPath);
		self::assertArrayHasKey('Deploy (abcd1234)', $baseChildren, 'the unrelated folder is left untouched');
		self::assertArrayHasKey('Deploy (abcd1234-0000-4000-8000-000000000000)', $baseChildren);
	}

	public function testMatchingFolderWithoutMarkerIsRejectedAndLeftUntouched(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$name = $this->resolver()->folderName('Deploy', $uuid);
		$this->plantBaseWithFolder($name);

		$this->assertRejected('alice', $uuid, 'Deploy', 'destination_ownership_conflict');

		$planted = $this->baseFolder()->get($name);
		self::assertInstanceOf(Folder::class, $planted);
		self::assertCount(0, $planted->getDirectoryListing(), 'the unowned folder is not written to');
		self::assertSame([], $this->deletedFolderIds, 'the unowned folder is not removed');
		self::assertCount(1, $this->baseFolder()->getDirectoryListing(), 'no folder is adopted or added');
	}

	public function testMatchingFolderWithMalformedMarkerIsRejectedAndLeftUntouched(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$name = $this->resolver()->folderName('Deploy', $uuid);
		$this->plantBaseWithFolder($name);
		$planted = $this->baseFolder()->get($name);
		self::assertInstanceOf(Folder::class, $planted);
		$planted->newFile(RunDestinationResolver::MARKER_FILE_NAME, '{this is not valid json');

		$this->assertRejected('alice', $uuid, 'Deploy', 'destination_ownership_conflict');

		$marker = $planted->get(RunDestinationResolver::MARKER_FILE_NAME);
		self::assertInstanceOf(File::class, $marker);
		self::assertSame('{this is not valid json', $marker->getContent(), 'the malformed marker is not overwritten');
		self::assertSame([], $this->deletedFolderIds);
	}

	public function testMatchingFolderWithDifferentUuidMarkerIsRejectedAndLeftUntouched(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$name = $this->resolver()->folderName('Deploy', $uuid);
		$this->plantBaseWithFolder($name);
		$planted = $this->baseFolder()->get($name);
		self::assertInstanceOf(Folder::class, $planted);
		$other = json_encode($this->markerPayload('ffffffff-0000-4000-8000-000000000000'), JSON_THROW_ON_ERROR);
		$planted->newFile(RunDestinationResolver::MARKER_FILE_NAME, $other);

		$this->assertRejected('alice', $uuid, 'Deploy', 'destination_ownership_conflict');

		$marker = $planted->get(RunDestinationResolver::MARKER_FILE_NAME);
		self::assertInstanceOf(File::class, $marker);
		self::assertSame($other, $marker->getContent(), 'a foreign marker is not overwritten');
		self::assertSame([], $this->deletedFolderIds);
	}

	public function testMatchingFolderWithUnknownMarkerFormatIsRejected(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$name = $this->resolver()->folderName('Deploy', $uuid);
		$this->plantBaseWithFolder($name);
		$planted = $this->baseFolder()->get($name);
		self::assertInstanceOf(Folder::class, $planted);
		$planted->newFile(
			RunDestinationResolver::MARKER_FILE_NAME,
			json_encode($this->markerPayload($uuid, 'some-other-format', 99), JSON_THROW_ON_ERROR),
		);

		$this->assertRejected('alice', $uuid, 'Deploy', 'destination_ownership_conflict');
		self::assertSame([], $this->deletedFolderIds);
	}

	public function testPartiallyWrittenMarkerIsNotAccepted(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$name = $this->resolver()->folderName('Deploy', $uuid);
		$this->plantBaseWithFolder($name);
		$planted = $this->baseFolder()->get($name);
		self::assertInstanceOf(Folder::class, $planted);
		$planted->newFile(
			RunDestinationResolver::MARKER_FILE_NAME,
			'{"format":"runbook-run-folder","version":1,"uuid":"abcd1234-0000-4000-8000-0000',
		);

		$this->assertRejected('alice', $uuid, 'Deploy', 'destination_ownership_conflict');
		self::assertSame([], $this->deletedFolderIds);
	}

	public function testFailedStartLeavesValidMarkedFolderForRetry(): void {
		$this->addUser('alice');
		$uuid = 'aaaa1111-0000-4000-8000-000000000000';

		// Folder and marker left behind by a previous attempt that failed before
		// its database insert completed.
		$leftover = $this->resolver()->resolveForRun('alice', $uuid, 'Deploy');
		self::assertTrue($leftover->folderCreated);
		$marker = json_decode($this->markerContent($leftover), true);
		self::assertSame($uuid, $marker['uuid'] ?? null);

		$retry = $this->resolver()->resolveForRun('alice', $uuid, 'Deploy');

		self::assertFalse($retry->folderCreated, 'the valid marked folder is recovered');
		self::assertSame($leftover->folderFileId, $retry->folderFileId);
		self::assertCount(1, $this->baseFolder()->getDirectoryListing(), 'no duplicate folder is created');
		self::assertSame($uuid, json_decode($this->markerContent($retry), true)['uuid'] ?? null);
	}

	public function testContendedStartDoesNotAcceptAPartialFolder(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$name = $this->resolver()->folderName('Deploy', $uuid);
		$this->plantBaseWithFolder($name);

		$this->lockingProvider->acquireLock('runbook/run-folder/' . $uuid, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->resolver()->resolveForRun('alice', $uuid, 'Deploy');
			self::fail('expected destination_locked while the per-run lock is held');
		} catch (ConflictException $exception) {
			self::assertSame('destination_locked', $exception->getReason());
		} finally {
			$this->lockingProvider->releaseLock('runbook/run-folder/' . $uuid, ILockingProvider::LOCK_EXCLUSIVE);
		}

		// Even after the contention clears, the unmarked folder is not adopted.
		$this->assertRejected('alice', $uuid, 'Deploy', 'destination_ownership_conflict');
		self::assertSame([], $this->deletedFolderIds);
		self::assertCount(1, $this->baseFolder()->getDirectoryListing());
	}

	public function testConcurrentStartDoesNotShareOrCreateAFolderUnderContention(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';

		// Another concurrent start holds the per-run creation lock.
		$this->lockingProvider->acquireLock('runbook/run-folder/' . $uuid, ILockingProvider::LOCK_EXCLUSIVE);
		try {
			$this->resolver()->resolveForRun('alice', $uuid, 'Deploy');
			self::fail('expected destination_locked while the per-run lock is held');
		} catch (ConflictException $exception) {
			self::assertSame('destination_locked', $exception->getReason());
		} finally {
			$this->lockingProvider->releaseLock('runbook/run-folder/' . $uuid, ILockingProvider::LOCK_EXCLUSIVE);
		}

		// No folder was created by the losing attempt, so it cannot have shared
		// a folder with the winner.
		self::assertArrayHasKey('Runbook', $this->userRootChildren);
		self::assertCount(0, $this->baseFolder()->getDirectoryListing());

		$destination = $this->resolver()->resolveForRun('alice', $uuid, 'Deploy');
		self::assertTrue($destination->folderCreated);
		self::assertCount(1, $this->baseFolder()->getDirectoryListing());
	}

	public function testCompensationPreservesTheOwnedFolder(): void {
		$this->addUser('alice');
		$destination = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');

		$this->resolver()->compensate($destination);

		self::assertNotContains($destination->folderFileId, $this->deletedFolderIds, 'the folder is never recursively deleted');
		self::assertTrue($this->baseFolder()->nodeExists((string)$destination->folderPath), 'the folder is preserved for a retry');
		self::assertTrue($this->markerFile($destination)->getId() > 0, 'the owned marker is preserved');
	}

	public function testCompensationRefusesFolderWithMismatchedMarker(): void {
		$this->addUser('alice');
		$destination = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');
		$this->markerFile($destination)->putContent(
			json_encode($this->markerPayload('ffffffff-0000-4000-8000-000000000000'), JSON_THROW_ON_ERROR),
		);

		$this->resolver()->compensate($destination);

		self::assertNotContains($destination->folderFileId, $this->deletedFolderIds, 'a folder whose marker names another uuid is never removed');
		self::assertTrue($this->baseFolder()->nodeExists((string)$destination->folderPath));
	}

	public function testCompensationRefusesUnmarkedFolder(): void {
		$this->addUser('alice');
		$destination = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');
		$this->markerFile($destination)->delete();

		$this->resolver()->compensate($destination);

		self::assertNotContains($destination->folderFileId, $this->deletedFolderIds, 'a folder with no ownership marker is never removed');
		self::assertTrue($this->baseFolder()->nodeExists((string)$destination->folderPath));
	}

	public function testCompensationDoesNotDeleteANonEmptyFolder(): void {
		$this->addUser('alice');
		$destination = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');
		$folder = $this->baseFolder()->get((string)$destination->folderPath);
		self::assertInstanceOf(Folder::class, $folder);
		$folder->newFolder('evidence');

		$this->resolver()->compensate($destination);

		self::assertNotContains($destination->folderFileId, $this->deletedFolderIds);
		self::assertTrue($this->baseFolder()->nodeExists((string)$destination->folderPath));
	}

	public function testCompensationDoesNotDeleteAnotherRunsFolder(): void {
		$this->addUser('alice');
		$first = $this->resolver()->resolveForRun('alice', 'aaaa1111-0000-4000-8000-000000000000', 'Deploy');
		$second = $this->resolver()->resolveForRun('alice', 'bbbb2222-0000-4000-8000-000000000000', 'Deploy');

		$this->resolver()->compensate($second);

		self::assertNotContains($second->folderFileId, $this->deletedFolderIds, 'the folder is preserved, never recursively deleted');
		self::assertNotContains($first->folderFileId, $this->deletedFolderIds, 'another run\'s folder is never removed');
		self::assertTrue($this->baseFolder()->nodeExists((string)$first->folderPath));
		self::assertTrue($this->baseFolder()->nodeExists((string)$second->folderPath));
	}

	public function testCompensationNeverListsOrDeletesAFolder(): void {
		// A user item could appear between any listing and a recursive delete, so
		// compensation must not list or delete at all. The spies throw if either
		// is ever called; a user file added before compensation must survive.
		$this->addUser('alice');
		$uuid = 'aaaa1111-0000-4000-8000-000000000000';
		$managedChildren = [];
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren);
		$this->installManagedFolder($managed);
		$destination = $this->resolver()->resolveForRun('alice', $uuid, 'Deploy');

		$userFile = $managed->newFile('race.txt', 'user data');
		$managed->method('getDirectoryListing')->willThrowException(new \RuntimeException('compensation must not list'));
		$managed->method('delete')->willThrowException(new \RuntimeException('compensation must not delete'));

		$this->resolver()->compensate($destination);

		self::assertSame([], $this->deletedFolderIds);
		self::assertSame([], $this->deletedFileIds);
		self::assertTrue($managed->nodeExists('race.txt'), 'the user file survives');
		self::assertInstanceOf(File::class, $userFile);
	}

	public function testCompensationOfAReusedFolderIsANoOp(): void {
		// Resolver-level reuse for the *same* UUID (a real startRun retry uses a
		// new UUID and does not reuse — see RunServiceTest). Compensation is a
		// no-op because this attempt did not create the folder.
		$this->addUser('alice');
		$uuid = 'aaaa1111-0000-4000-8000-000000000000';
		$first = $this->resolver()->resolveForRun('alice', $uuid, 'Deploy');
		$retry = $this->resolver()->resolveForRun('alice', $uuid, 'Deploy');

		$this->resolver()->compensate($retry);

		self::assertFalse($retry->folderCreated);
		self::assertSame([], $this->deletedFolderIds, 'a reused folder is not owned by this attempt');
		self::assertTrue($this->baseFolder()->nodeExists((string)$first->folderPath));
	}

	public function testMarkerWriteFailureBeforeMarkerCreationPreservesTheFolder(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$managedChildren = [];
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren, true, true, null, static function (string $name, ?string $content = null): File {
			throw new NotPermittedException('denied');
		});
		$this->installManagedFolder($managed);

		$this->assertMarkerWriteRejected($uuid, 'destination_not_writable');

		self::assertSame([], $this->deletedFolderIds, 'the folder is preserved, never recursively deleted');
		self::assertSame([], $this->deletedFileIds, 'no file is deleted');
	}

	public function testMarkerWriteFailureNeverListsOrDeletesOnUntrackedContent(): void {
		// Simulate an untracked file that a user adds to the freshly created
		// folder. The folder lists (and could be deleted) only in the old unsafe
		// code; the safe path must neither list nor delete, so the spies throw if
		// either is called and the user file must survive.
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$managedChildren = [];
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren, true, true, null, static function (string $name, ?string $content = null): File {
			throw new NotPermittedException('denied');
		});
		$managedChildren['race.txt'] = $this->makeFileMock('race.txt', 9401, $managedChildren, 'user data');
		$managed->method('getDirectoryListing')->willThrowException(new \RuntimeException('must not list'));
		$managed->method('delete')->willThrowException(new \RuntimeException('must not delete'));
		$this->installManagedFolder($managed);

		$this->assertMarkerWriteRejected($uuid, 'destination_not_writable');

		self::assertSame([], $this->deletedFolderIds);
		self::assertSame([], $this->deletedFileIds);
		self::assertTrue($managed->nodeExists('race.txt'), 'the untracked user file survives');
	}

	public function testMarkerWriteFailureWithPartialMarkerPreservesMarkerAndFolder(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$partial = '{"format":"runbook-run-folder","version":1,"uuid":"abcd1234-0000-4000-8000-0000';
		$managedChildren = [];
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren, true, true, null, function (string $name, ?string $content = null) use (&$managedChildren, $partial): File {
			$file = $this->makeFileMock($name, 9401, $managedChildren, $partial);
			$managedChildren[$name] = $file;

			throw new NotPermittedException('denied');
		});
		$this->installManagedFolder($managed);

		$this->assertMarkerWriteRejected($uuid, 'destination_not_writable');

		self::assertSame([], $this->deletedFolderIds, 'the folder is not removed while a marker is present');
		self::assertSame([], $this->deletedFileIds, 'the partial marker is not deleted');
		self::assertTrue($managed->nodeExists(RunDestinationResolver::MARKER_FILE_NAME), 'the partial marker is preserved');
		$marker = $managed->get(RunDestinationResolver::MARKER_FILE_NAME);
		self::assertInstanceOf(File::class, $marker);
		self::assertSame($partial, $marker->getContent(), 'the partial marker is not overwritten');
	}

	public function testMarkerWriteFailureWithOwnedMarkerAndUnrelatedFilePreservesUnrelatedFile(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$managedChildren = [];
		$managedChildren['evidence.txt'] = $this->makeFileMock('evidence.txt', 9402, $managedChildren, 'user data');
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren, true, true, null, function (string $name, ?string $content = null) use (&$managedChildren): File {
			$file = $this->makeFileMock($name, 9401, $managedChildren, (string)$content);
			$managedChildren[$name] = $file;

			throw new NotPermittedException('denied');
		});
		$this->installManagedFolder($managed);

		$this->assertMarkerWriteRejected($uuid, 'destination_not_writable');

		self::assertSame([], $this->deletedFolderIds, 'the folder is kept because it still contains unrelated content');
		self::assertSame([9401], $this->deletedFileIds, 'only this attempt\'s marker is removed');
		self::assertTrue($managed->nodeExists('evidence.txt'), 'the unrelated file is preserved');
		$evidence = $managed->get('evidence.txt');
		self::assertInstanceOf(File::class, $evidence);
		self::assertSame('user data', $evidence->getContent(), 'the unrelated file is unchanged');
	}

	public function testMarkerWriteFailureWhenListingFailsLeavesFolderUntouched(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$managedChildren = [];
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren, true, true, null, static function (string $name, ?string $content = null): File {
			throw new NotPermittedException('denied');
		});
		$managed->method('getDirectoryListing')->willThrowException(new \RuntimeException('listing failed'));
		$this->installManagedFolder($managed);

		$this->assertMarkerWriteRejected($uuid, 'destination_not_writable');

		self::assertSame([], $this->deletedFolderIds, 'a failed listing leaves the folder untouched');
		self::assertSame([], $this->deletedFileIds, 'a failed listing deletes nothing');
	}

	public function testMarkerWriteFailureWithForeignMarkerNeverTouchesIt(): void {
		$this->addUser('alice');
		$uuid = 'abcd1234-0000-4000-8000-000000000000';
		$foreign = json_encode($this->markerPayload('ffffffff-0000-4000-8000-000000000000'), JSON_THROW_ON_ERROR);
		$managedChildren = [];
		$managed = $this->makeFolderMock($this->resolver()->folderName('Deploy', $uuid), 9300, $managedChildren);
		$marker = $managed->newFile(RunDestinationResolver::MARKER_FILE_NAME, $foreign);
		self::assertInstanceOf(File::class, $marker);
		$this->installManagedFolder($managed);

		$this->assertMarkerWriteRejected($uuid, 'destination_ownership_conflict');

		self::assertSame([], $this->deletedFolderIds, 'the foreign marker\'s folder is never removed');
		self::assertSame([], $this->deletedFileIds, 'the foreign marker is never deleted');
		self::assertSame($foreign, $marker->getContent(), 'the foreign marker is not overwritten');
	}

	/**
	 * Install a `Runbook` base folder whose `newFolder()` returns the given
	 * managed folder, so marker-write failures can be simulated precisely.
	 */
	private function installManagedFolder(Folder $managed): void {
		$baseChildren = [];
		$base = $this->makeFolderMock('Runbook', 9200, $baseChildren, true, true, static fn (string $name): Folder => $managed);
		$this->userRootChildren['Runbook'] = $base;
	}

	private function assertMarkerWriteRejected(string $uuid, string $reason): void {
		try {
			$this->resolver()->resolveForRun('alice', $uuid, 'Deploy');
			self::fail('expected the marker-write failure to abort the run start');
		} catch (ConflictException $exception) {
			self::assertSame($reason, $exception->getReason());
		}
	}

	public function testCaptureReferenceCapturesIdentityFromChooserView(): void {
		$this->addUser('admin');
		$folder = $this->addUserFolder('Shared', 7001);

		$reference = $this->resolver()->captureReference('admin', '/Shared');

		self::assertSame('home::test', $reference->storageId);
		self::assertSame(7001, $reference->fileId);
		self::assertSame('/Shared', $reference->path);
		self::assertSame('admin', $reference->configuredBy);
		self::assertSame(7001, $folder->getId());
	}

	public function testCaptureReferenceRejectsNonFolder(): void {
		$this->addUser('admin');
		$children = [];
		$this->userRootChildren['note.txt'] = $this->makeFileMock('note.txt', 7002, $children);

		$this->assertCaptureRejected('admin', '/note.txt', 'destination_invalid', ValidationException::class);
	}

	public function testCaptureReferenceRejectsMissingPath(): void {
		$this->addUser('admin');

		$this->assertCaptureRejected('admin', '/Nope', 'destination_invalid', ValidationException::class);
	}

	public function testCaptureReferenceRejectsUnwritableFolder(): void {
		$this->addUser('admin');
		$this->addUserFolder('ReadOnly', 7003, false);

		$this->assertCaptureRejected('admin', '/ReadOnly', 'destination_not_writable');
	}

	public function testCaptureReferenceRejectsInaccessibleFolder(): void {
		$this->addUser('admin');
		$this->userFilesFolder->method('get')->willThrowException(new NotPermittedException('denied'));

		$this->assertCaptureRejected('admin', '/Shared', 'destination_no_access');
	}

	public function testConfiguredAdminDestinationIsResolvedInRunOwnerView(): void {
		$this->addUser('alice');
		$configured = $this->addUserFolder('Shared', 7001);
		$this->setAdminDestination('home::test', 7001, '/Shared', 'admin');

		$destination = $this->resolver()->resolveForRun('alice', 'abcd1234-0000-4000-8000-000000000000', 'Deploy');

		self::assertSame('admin', $destination->source);
		self::assertSame('admin', $destination->configuredBy);
		self::assertSame(7001, $destination->fileId);
		self::assertSame('home::test', $destination->storageId);
		self::assertTrue($destination->folderCreated);
		self::assertCount(1, $configured->getDirectoryListing(), 'the managed folder is created inside the configured folder');
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'the default folder is not used');
	}

	public function testConfiguredAdminDestinationMissingInOwnerViewFailsClosed(): void {
		$this->addUser('alice');
		$this->setAdminDestination('home::test', 7001, '/Shared', 'admin');

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_no_access');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
		self::assertSame([], $this->deletedFolderIds);
	}

	public function testConfiguredAdminDestinationWithDifferentStorageIsNotResolved(): void {
		$this->addUser('alice');
		$this->addUserFolder('Shared', 7001);

		// The reference is resolved by exact file id plus exact storage id; a
		// same-id node on a different storage is not the configured folder.
		$this->setAdminDestination('other::storage', 7001, '/Shared', 'admin');

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_no_access');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}
	}

	public function testConfiguredAdminDestinationAmbiguousFailsClosed(): void {
		$this->addUser('alice');
		$this->addUserFolder('SharedA', 7001);
		$this->addUserFolder('SharedB', 7001);
		$this->setAdminDestination('home::test', 7001, '/SharedA', 'admin');

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_ambiguous');
		} catch (ConflictException $exception) {
			self::assertSame('destination_ambiguous', $exception->getReason());
		}
	}

	public function testConfiguredAdminDestinationUnwritableFailsClosed(): void {
		$this->addUser('alice');
		$this->addUserFolder('Shared', 7001, false);
		$this->setAdminDestination('home::test', 7001, '/Shared', 'admin');

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_not_writable');
		} catch (ConflictException $exception) {
			self::assertSame('destination_not_writable', $exception->getReason());
		}
	}

	public function testPartialAdminDestinationFailsClosed(): void {
		$this->addUser('alice');
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_FILE_ID, 7001);

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_invalid_config');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testUnsetAdminDestinationUsesDefaultRunbook(): void {
		$this->addUser('alice');

		$destination = $this->resolver()->resolveForRun('alice', 'u', 'Deploy');

		self::assertSame('default', $destination->source);
		self::assertNull($destination->configuredBy);
		self::assertArrayHasKey('Runbook', $this->userRootChildren);
	}

	public function testCompleteLegacyAdminDestinationIsResolvedInRunOwnerView(): void {
		$this->addUser('alice');
		$configured = $this->addUserFolder('Shared', 7001);
		$this->setLegacyAdminDestination('home::test', 7001, '/Shared', 'admin');

		$destination = $this->resolver()->resolveForRun('alice', 'u', 'Deploy');

		self::assertSame('admin', $destination->source);
		self::assertSame('admin', $destination->configuredBy);
		self::assertSame(7001, $destination->fileId);
		self::assertSame('home::test', $destination->storageId);
		self::assertCount(1, $configured->getDirectoryListing());
	}

	public function testMalformedAdminDestinationStoredStateFailsClosed(): void {
		$this->addUser('alice');
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_REFERENCE, '{not valid json');

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_invalid_config');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'a corrupt setting must not fall back to the default folder');
	}

	public function testConflictingAdminDestinationStoredStateFailsClosed(): void {
		$this->addUser('alice');
		$this->setLegacyAdminDestination('home::legacy', 7001, '/Legacy', 'admin-legacy');
		$this->setAppConfig(AdminSettings::KEY_DESTINATION_REFERENCE, json_encode([
			'v' => 1,
			'storageId' => 'home::new',
			'fileId' => 7002,
			'path' => '/New',
			'configuredBy' => 'admin-new',
		], JSON_THROW_ON_ERROR));

		try {
			$this->resolver()->resolveForRun('alice', 'u', 'Deploy');
			self::fail('expected destination_invalid_config');
		} catch (ValidationException $exception) {
			self::assertSame('destination_invalid_config', $exception->getReason());
		}
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testTemplateDestinationTakesPrecedenceOverAdminAndDefault(): void {
		$this->addUser('alice');
		$adminFolder = $this->addUserFolder('AdminShared', 7001);
		$templateFolder = $this->addUserFolder('TemplateShared', 7002);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');

		$destination = $this->resolver()->resolveForRun(
			'alice',
			'abcd1234-0000-4000-8000-000000000000',
			'Deploy',
			new DestinationReference('home::test', 7002, '/TemplateShared', 'editor'),
		);

		self::assertSame('template', $destination->source);
		self::assertSame('editor', $destination->configuredBy);
		self::assertSame(7002, $destination->fileId);
		self::assertCount(1, $templateFolder->getDirectoryListing(), 'the managed folder is created in the template folder');
		self::assertCount(0, $adminFolder->getDirectoryListing(), 'the administration folder is not used');
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testUnsetTemplateDestinationFallsThroughToAdminDestination(): void {
		$this->addUser('alice');
		$adminFolder = $this->addUserFolder('AdminShared', 7001);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');

		$destination = $this->resolver()->resolveForRun('alice', 'u', 'Deploy');

		self::assertSame('admin', $destination->source);
		self::assertSame(7001, $destination->fileId);
		self::assertCount(1, $adminFolder->getDirectoryListing());
	}

	public function testConfiguredTemplateDestinationMissingForOwnerFailsClosedWithoutFallthrough(): void {
		$this->addUser('alice');
		$this->addUserFolder('AdminShared', 7001);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');

		try {
			$this->resolver()->resolveForRun(
				'alice',
				'u',
				'Deploy',
				new DestinationReference('home::test', 9999, '/Missing', 'editor'),
			);
			self::fail('expected destination_no_access');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'no fallback to the default folder');
	}

	public function testConfiguredTemplateDestinationUnwritableFailsClosed(): void {
		$this->addUser('alice');
		$this->addUserFolder('ReadOnly', 7003, false);

		try {
			$this->resolver()->resolveForRun(
				'alice',
				'u',
				'Deploy',
				new DestinationReference('home::test', 7003, '/ReadOnly', 'editor'),
			);
			self::fail('expected destination_not_writable');
		} catch (ConflictException $exception) {
			self::assertSame('destination_not_writable', $exception->getReason());
		}
	}

	public function testConfiguredTemplateDestinationWithDifferentStorageIsNotResolved(): void {
		$this->addUser('alice');
		$this->addUserFolder('TemplateShared', 7002);

		try {
			$this->resolver()->resolveForRun(
				'alice',
				'u',
				'Deploy',
				new DestinationReference('other::storage', 7002, '/TemplateShared', 'editor'),
			);
			self::fail('expected destination_no_access');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}
	}

	public function testRuntimeDestinationTakesPrecedenceOverTemplateAdminAndDefault(): void {
		$this->addUser('alice');
		$adminFolder = $this->addUserFolder('AdminShared', 7001);
		$templateFolder = $this->addUserFolder('TemplateShared', 7002);
		$runtimeFolder = $this->addUserFolder('RuntimeShared', 7003);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');

		$destination = $this->resolver()->resolveForRun(
			'alice',
			'abcd1234-0000-4000-8000-000000000000',
			'Deploy',
			new DestinationReference('home::test', 7002, '/TemplateShared', 'editor'),
			new DestinationReference('home::test', 7003, '/RuntimeShared', 'alice'),
		);

		self::assertSame('runtime', $destination->source);
		self::assertSame('alice', $destination->configuredBy);
		self::assertSame(7003, $destination->fileId);
		self::assertCount(1, $runtimeFolder->getDirectoryListing(), 'the managed folder is created in the run-time folder');
		self::assertCount(0, $templateFolder->getDirectoryListing());
		self::assertCount(0, $adminFolder->getDirectoryListing());
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren);
	}

	public function testRuntimeDestinationMissingForOwnerFailsClosedWithoutFallthrough(): void {
		$this->addUser('alice');
		$this->addUserFolder('TemplateShared', 7002);
		$this->setAdminDestination('home::test', 7001, '/AdminShared', 'admin');

		try {
			$this->resolver()->resolveForRun(
				'alice',
				'u',
				'Deploy',
				new DestinationReference('home::test', 7002, '/TemplateShared', 'editor'),
				new DestinationReference('home::test', 9999, '/Missing', 'alice'),
			);
			self::fail('expected destination_no_access');
		} catch (ConflictException $exception) {
			self::assertSame('destination_no_access', $exception->getReason());
		}
		self::assertArrayNotHasKey('Runbook', $this->userRootChildren, 'no fallback to template, admin or default');
	}

	public function testRuntimeDestinationUnwritableFailsClosed(): void {
		$this->addUser('alice');
		$this->addUserFolder('ReadOnly', 7003, false);

		try {
			$this->resolver()->resolveForRun(
				'alice',
				'u',
				'Deploy',
				null,
				new DestinationReference('home::test', 7003, '/ReadOnly', 'alice'),
			);
			self::fail('expected destination_not_writable');
		} catch (ConflictException $exception) {
			self::assertSame('destination_not_writable', $exception->getReason());
		}
	}

	public function testRuntimeDestinationAmbiguousFailsClosed(): void {
		$this->addUser('alice');
		$this->addUserFolder('SharedA', 7003);
		$this->addUserFolder('SharedB', 7003);

		try {
			$this->resolver()->resolveForRun(
				'alice',
				'u',
				'Deploy',
				null,
				new DestinationReference('home::test', 7003, '/SharedA', 'alice'),
			);
			self::fail('expected destination_ambiguous');
		} catch (ConflictException $exception) {
			self::assertSame('destination_ambiguous', $exception->getReason());
		}
	}

	/**
	 * @param class-string $class
	 */
	private function assertCaptureRejected(string $uid, string $path, string $reason, string $class = ConflictException::class): void {
		try {
			$this->resolver()->captureReference($uid, $path);
			self::fail('expected capture rejection with ' . $reason);
		} catch (\Throwable $exception) {
			self::assertInstanceOf($class, $exception);
			if ($exception instanceof ConflictException || $exception instanceof ValidationException) {
				self::assertSame($reason, $exception->getReason());
			}
		}
	}

	public function testResolveManagedFolderByExactIdentity(): void {
		$this->addUser('alice');
		$folder = $this->addUserFolder('RunFolder', 7000);

		$resolved = $this->resolver()->resolveManagedFolder('alice', 7000, 'home::test');

		self::assertSame($folder->getId(), $resolved->getId());
	}

	public function testResolveManagedFolderFailsClosedWhenMissing(): void {
		$this->addUser('alice');

		$this->assertResolveManagedFolderRejected(7000, 'home::test', 'destination_unavailable');
	}

	public function testResolveManagedFolderFailsClosedWhenStorageMismatch(): void {
		$this->addUser('alice');
		$this->addUserFolder('RunFolder', 7000);

		$this->assertResolveManagedFolderRejected(7000, 'other::storage', 'destination_unavailable');
	}

	public function testResolveManagedFolderFailsClosedWhenUnwritable(): void {
		$this->addUser('alice');
		$this->addUserFolder('RunFolder', 7000, false);

		$this->assertResolveManagedFolderRejected(7000, 'home::test', 'destination_not_writable');
	}

	public function testResolveManagedFolderFailsClosedWhenAmbiguous(): void {
		$this->addUser('alice');
		$this->addUserFolder('RunFolderA', 7000);
		$this->addUserFolder('RunFolderB', 7000);

		$this->assertResolveManagedFolderRejected(7000, 'home::test', 'destination_ambiguous');
	}

	public function testResolveManagedFolderFailsClosedWhenIncomplete(): void {
		$this->addUser('alice');

		$this->assertResolveManagedFolderRejected(null, 'home::test', 'destination_unavailable');
		$this->assertResolveManagedFolderRejected(7000, '', 'destination_unavailable');
	}

	public function testStorageIdComparisonIsExactBeyond64Characters(): void {
		$this->addUser('alice');
		$prefix = str_repeat('a', 64);
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(7000);
		$folder->method('getPath')->willReturn('Shared');
		$folder->method('getName')->willReturn('Shared');
		$folder->method('isCreatable')->willReturn(true);
		$folder->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);
		$storage = $this->createMock(IStorage::class);
		$storage->method('getId')->willReturn($prefix . 'A');
		$folder->method('getStorage')->willReturn($storage);
		$this->userRootChildren['Shared'] = $folder;

		// A storage id that is identical up to and beyond the 64th character but
		// differs afterwards is a different storage: it must not resolve.
		try {
			$this->resolver()->resolveManagedFolder('alice', 7000, $prefix . 'B');
			self::fail('a storage id differing after the 64th character must not match');
		} catch (ConflictException $exception) {
			self::assertSame('destination_unavailable', $exception->getReason());
		}

		self::assertSame(7000, $this->resolver()->resolveManagedFolder('alice', 7000, $prefix . 'A')->getId());
	}

	public function testResolveTrackedFileByExactIdentityInsideManagedFolder(): void {
		$this->addUser('alice');
		$folder = $this->addUserFolder('RunFolder', 7000);
		$file = $folder->newFile('evidence.txt', 'hello');

		$resolved = $this->resolver()->resolveTrackedFile('alice', 'home::test', $file->getId());

		self::assertInstanceOf(File::class, $resolved);
		self::assertSame($file->getId(), $resolved->getId());
	}

	public function testResolveTrackedFileFailsClosedWhenMissing(): void {
		$this->addUser('alice');

		try {
			$this->resolver()->resolveTrackedFile('alice', 'home::test', 4242);
			self::fail('expected attachment_missing');
		} catch (ConflictException $exception) {
			self::assertSame('attachment_missing', $exception->getReason());
		}
	}

	public function testNodeDescriptorReturnsIdentityAndMetadata(): void {
		$this->addUser('alice');
		$folder = $this->addUserFolder('RunFolder', 7000);
		$file = $folder->newFile('evidence.txt', 'hello');

		$descriptor = $this->resolver()->nodeDescriptor($file);

		self::assertSame('home::test', $descriptor['storageId']);
		self::assertSame($file->getId(), $descriptor['fileId']);
		self::assertSame(7, $descriptor['storageRootId']);
		self::assertSame('local', $descriptor['mountType']);
		self::assertNull($descriptor['mountId']);
		self::assertSame(1, $descriptor['numericStorageId']);
		self::assertNotSame('', $descriptor['path']);
	}

	private function assertResolveManagedFolderRejected(?int $fileId, ?string $storageId, string $reason): void {
		try {
			$this->resolver()->resolveManagedFolder('alice', $fileId, $storageId);
			self::fail('expected ' . $reason);
		} catch (ConflictException $exception) {
			self::assertSame($reason, $exception->getReason());
		}
	}
}
