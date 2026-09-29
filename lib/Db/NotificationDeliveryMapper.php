<?php

declare(strict_types=1);

namespace OCA\Runbook\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NotificationDelivery>
 */
class NotificationDeliveryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'runbook_notification_deliveries', NotificationDelivery::class);
	}

	public function find(int $id): NotificationDelivery {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$delivery = $this->findEntity($qb);

		return $delivery;
	}

	/**
	 * Find the delivery ledger row for a dedupe key, if any.
	 */
	public function findByKey(string $dedupeKey): ?NotificationDelivery {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where($qb->expr()->eq('dedupe_key', $qb->createNamedParameter($dedupeKey)))
			->setMaxResults(1);

		try {
			$delivery = $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}

		return $delivery;
	}

	/**
	 * Atomically claim a delivery for (re)attempt.
	 *
	 * The conditional update succeeds only when the row is retryable: a failed
	 * row below the attempt bound, or a pending row whose last attempt is older
	 * than the staleness window (crash recovery). Because the update is atomic,
	 * concurrent workers cannot both claim the same row, which bounds duplicate
	 * notifications even under concurrency.
	 */
	public function claimForDelivery(int $id, int $now, int $maxAttempts, int $staleBefore): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('status', $qb->createNamedParameter(NotificationDelivery::STATUS_PENDING))
			->set('attempts', $qb->createFunction('attempts + 1'))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('attempts', $qb->createNamedParameter($maxAttempts, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('status', $qb->createNamedParameter(NotificationDelivery::STATUS_FAILED)),
					$qb->expr()->andX(
						$qb->expr()->eq('status', $qb->createNamedParameter(NotificationDelivery::STATUS_PENDING)),
						$qb->expr()->lt('updated_at', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_INT)),
					),
				),
			);

		return $qb->executeStatement() === 1;
	}

	public function markSent(int $id, int $now): void {
		$this->markStatus($id, NotificationDelivery::STATUS_SENT, $now);
	}

	public function markFailed(int $id, int $now): void {
		$this->markStatus($id, NotificationDelivery::STATUS_FAILED, $now);
	}

	private function markStatus(int $id, string $status, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('status', $qb->createNamedParameter($status))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}
}
