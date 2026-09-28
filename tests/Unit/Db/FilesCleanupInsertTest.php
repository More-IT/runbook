<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Db;

use OCA\Runbook\Db\FilesCleanup;
use OCA\Runbook\Db\FilesCleanupMapper;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Service\FilesCleanupService;
use OCA\Runbook\Service\RunDestinationResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Database-insertion-boundary regression for the managed run folder cleanup
 * record (issue #54).
 *
 * `oc_runbook_files_cleanup.kind` is NOT NULL and has no database default, so an
 * INSERT that omits it is rejected by PostgreSQL with SQLSTATE 23502 and the
 * whole run deletion fails. `Entity::setter()` skips a value equal to the
 * current property value, and `FilesCleanup::$kind` already defaults to
 * `KIND_FOLDER`, so a magic setter would drop the column from the INSERT.
 *
 * This test runs the real entity through the real
 * {@see FilesCleanupMapper::insert()} and inspects the columns it binds, exactly
 * as the mapper would bind them against the database. It fails if `kind` is
 * omitted from the INSERT (which is what produced the NULL/NOT NULL violation).
 */
class FilesCleanupInsertTest extends TestCase {
	/** @var array<string, mixed> Bound column => value. */
	private array $bound = [];
	private ?string $insertedTable = null;

	private function capturingMapper(): FilesCleanupMapper {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnCallback(function (mixed $table = null) use ($qb): IQueryBuilder {
			$this->insertedTable = is_string($table) ? $table : null;

			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(
			static fn (mixed $value, mixed $type = null, mixed $placeholder = null): mixed => $value,
		);
		$qb->method('setValue')->willReturnCallback(function (mixed $column, mixed $value): void {
			$this->bound[(string)$column] = $value;
		});
		$qb->method('executeStatement')->willReturn(1);
		$qb->method('getLastInsertId')->willReturn(4242);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new FilesCleanupMapper($db);
	}

	private function serviceWith(FilesCleanupMapper $mapper): FilesCleanupService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1700000000);

		return new FilesCleanupService(
			$mapper,
			$this->createMock(RunDestinationResolver::class),
			$time,
			new NullLogger(),
		);
	}

	public function testCleanupInsertAlwaysBindsKind(): void {
		$run = new Run();
		$run->setOwner('alice');
		$run->setDestinationViewUid('alice');
		$run->setRunFolderFileId(4242);
		$run->setRunFolderStorageId('home::alice');
		$run->setRunFolderPath('/alice/files/Runbook/Run (uuid)');

		$service = $this->serviceWith($this->capturingMapper());
		$record = $service->buildRecord($run);
		self::assertInstanceOf(FilesCleanup::class, $record);
		self::assertSame(FilesCleanup::KIND_FOLDER, $record->getKind());

		$service->persist($record);

		self::assertSame('runbook_files_cleanup', $this->insertedTable);
		self::assertArrayHasKey('kind', $this->bound, 'the NOT NULL kind column must be bound, never omitted');
		self::assertSame(FilesCleanup::KIND_FOLDER, $this->bound['kind']);
		self::assertSame(4242, $record->getId());
	}

	public function testCleanupInsertBindsEveryNotNullColumnWithoutADatabaseDefault(): void {
		// Mirrors the oc_runbook_files_cleanup schema: these columns are NOT NULL
		// with no database default, so omitting any of them fails the INSERT.
		$run = new Run();
		$run->setOwner('bob');
		$run->setDestinationViewUid('bob');
		$run->setRunFolderFileId(7);
		$run->setRunFolderStorageId('home::bob');

		$service = $this->serviceWith($this->capturingMapper());
		$record = $service->buildRecord($run);
		self::assertInstanceOf(FilesCleanup::class, $record);

		$service->persist($record);

		foreach (['kind', 'view_uid', 'storage_id', 'file_id', 'created_at'] as $column) {
			self::assertArrayHasKey($column, $this->bound, $column . ' must be bound in the INSERT');
		}
		self::assertSame('bob', $this->bound['view_uid']);
		self::assertSame('home::bob', $this->bound['storage_id']);
		self::assertSame(7, $this->bound['file_id']);
		self::assertSame(1700000000, $this->bound['created_at']);
	}
}
