<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Service\ActivityService;

class ActivityServiceTest extends RunTestBase {
	public function testRecordSanitizesForbiddenMetadata(): void {
		$this->addUser('alice');

		$event = $this->activityServiceFor('alice')->record(1, null, ActivityType::AttachmentUploaded, [
			'filename' => 'evidence.txt',
			'size' => 12,
			'storageKey' => 'should-never-be-stored',
			'storage_key' => 'also-forbidden',
			'path' => '/runs/1/steps/2',
			'body' => 'secret body',
			'content' => 'secret content',
			'nested' => ['body' => 'nope', 'ok' => 'kept'],
		]);

		$metadata = $event->getMetadataArray();
		self::assertSame('evidence.txt', $metadata['filename']);
		self::assertSame(12, $metadata['size']);
		self::assertArrayNotHasKey('storageKey', $metadata);
		self::assertArrayNotHasKey('storage_key', $metadata);
		self::assertArrayNotHasKey('path', $metadata);
		self::assertArrayNotHasKey('body', $metadata);
		self::assertArrayNotHasKey('content', $metadata);
		self::assertSame(['ok' => 'kept'], $metadata['nested']);
	}

	public function testListForRunReturnsNewestFirst(): void {
		$this->addUser('alice');
		$service = $this->activityServiceFor('alice');
		$service->record(5, null, ActivityType::RunStarted);
		$this->now = 2000;
		$service->record(5, null, ActivityType::RunCompleted);
		$service->record(6, null, ActivityType::RunStarted);

		$events = $service->listForRun(5);

		self::assertCount(2, $events);
		self::assertSame(ActivityType::RunCompleted->value, $events[0]->getEventType());
		self::assertSame(ActivityType::RunStarted->value, $events[1]->getEventType());
	}

	public function testLimitIsAppliedAndClamped(): void {
		$this->addUser('alice');
		$service = $this->activityServiceFor('alice');
		for ($i = 0; $i < 5; $i++) {
			$service->record(9, null, ActivityType::StepStarted);
		}

		self::assertCount(2, $service->listForRun(9, 2));
		self::assertCount(1, $service->listForRun(9, 0));
		self::assertCount(5, $service->listForRun(9, 1000));
	}

	public function testActivityHistoryIsAppendOnly(): void {
		self::assertFalse(method_exists(ActivityService::class, 'update'));
		self::assertFalse(method_exists(ActivityService::class, 'delete'));
	}
}
