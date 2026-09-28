<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Db;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Db\FilesCleanup;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateStep;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for entity fields whose value can equal the entity default
 * but whose database column is NOT NULL without a database default.
 *
 * These fields must always be marked as updated so they are included in the
 * INSERT. A magic setter skips a value that equals the current value, which
 * previously caused "NOT NULL constraint failed: oc_runbook_runs.template_version".
 */
class EntityPersistenceFieldsTest extends TestCase {
	public function testRunTemplateVersionIsAlwaysUpdated(): void {
		$run = new Run();

		$run->setTemplateVersion(1);

		self::assertArrayHasKey('templateVersion', $run->getUpdatedFields());
		self::assertSame(1, $run->toArray()['templateVersion']);
	}

	public function testRunTextFieldsAreAlwaysUpdatedWhenEmpty(): void {
		$run = new Run();

		$run->setTitle('');
		$run->setDescription('');

		self::assertArrayHasKey('title', $run->getUpdatedFields());
		self::assertArrayHasKey('description', $run->getUpdatedFields());
	}

	public function testTemplateTextFieldsAreAlwaysUpdatedWhenEmpty(): void {
		$template = new Template();
		$template->setTitle('');
		$template->setDescription('');

		self::assertArrayHasKey('title', $template->getUpdatedFields());
		self::assertArrayHasKey('description', $template->getUpdatedFields());
	}

	public function testSectionAndStepTextAndConfigAreAlwaysUpdated(): void {
		$templateSection = new TemplateSection();
		$templateSection->setDescription('');
		self::assertArrayHasKey('description', $templateSection->getUpdatedFields());

		$templateStep = new TemplateStep();
		$templateStep->setDescription('');
		$templateStep->setConfigArray([]);
		self::assertArrayHasKey('description', $templateStep->getUpdatedFields());
		self::assertArrayHasKey('config', $templateStep->getUpdatedFields());
		self::assertSame('[]', $templateStep->getConfig());

		$runSection = new RunSection();
		$runSection->setDescription('');
		self::assertArrayHasKey('description', $runSection->getUpdatedFields());

		$runStep = new RunStep();
		$runStep->setDescription('');
		$runStep->setConfigArray([]);
		self::assertArrayHasKey('description', $runStep->getUpdatedFields());
		self::assertArrayHasKey('config', $runStep->getUpdatedFields());
		self::assertSame('[]', $runStep->getConfig());
	}

	public function testActivityMetadataIsAlwaysUpdated(): void {
		$event = new ActivityEvent();
		$event->setMetadataArray([]);

		self::assertArrayHasKey('metadata', $event->getUpdatedFields());
		self::assertSame('[]', $event->getMetadata());
	}

	public function testFilesCleanupKindIsAlwaysUpdated(): void {
		// `kind` defaults to KIND_FOLDER and its column is NOT NULL without a
		// database default, so the setter must still mark it updated.
		$cleanup = new FilesCleanup();

		$cleanup->setKind(FilesCleanup::KIND_FOLDER);

		self::assertArrayHasKey('kind', $cleanup->getUpdatedFields());
		self::assertSame(FilesCleanup::KIND_FOLDER, $cleanup->getKind());
	}
}
