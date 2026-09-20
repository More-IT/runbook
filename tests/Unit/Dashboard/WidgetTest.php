<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Dashboard;

use OCA\Runbook\Dashboard\Widget;
use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunSection;
use OCA\Runbook\Db\RunStep;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\WorkService;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WidgetTest extends TestCase {
	/** @var WorkService&MockObject */
	private WorkService $work;

	protected function setUp(): void {
		$this->work = $this->createMock(WorkService::class);
	}

	private function adminSettings(bool $dashboardEnabled = true): AdminSettings {
		/** @var IAppConfig&MockObject $appConfig */
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => $key === AdminSettings::KEY_DASHBOARD_ENABLED ? $dashboardEnabled : $default,
		);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = '', bool $lazy = false): string => $default);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0, bool $lazy = false): int => $default);
		$appConfig->method('getValueArray')->willReturnCallback(static fn (string $app, string $key, array $default = [], bool $lazy = false): array => $default);

		return new AdminSettings($appConfig, $this->createMock(IGroupManager::class));
	}

	private function widget(bool $dashboardEnabled = true): Widget {
		/** @var IL10N&MockObject $l10n */
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function (string $text, array $parameters = []): string {
			if ($parameters === []) {
				return $text;
			}

			// Nextcloud's IL10N::t() resolves sprintf placeholders with
			// vsprintf(); the mock must do the same so a widget that passes
			// JavaScript-style named placeholders is caught by the tests.
			return vsprintf($text, $parameters);
		});

		/** @var IFactory&MockObject $factory */
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		/** @var IURLGenerator&MockObject $url */
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/index.php/apps/runbook/');
		$url->method('imagePath')->willReturnCallback(
			static fn (string $app, string $file): string => '/apps/' . $app . '/img/' . $file,
		);
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.com' . $path);

		return new Widget($this->work, $this->adminSettings($dashboardEnabled), $url, $factory);
	}

	private function makeRun(int $id, string $title = 'Run'): Run {
		$run = new Run();
		$run->setId($id);
		$run->setTitle($title);
		$run->setOwner('alice');

		return $run;
	}

	/**
	 * @return array{run: Run, section: RunSection, step: RunStep, overdue: bool, dueToday: bool}
	 */
	private function item(int $runId, string $stepTitle = 'Step', bool $overdue = false, bool $dueToday = false, ?int $dueAt = null, string $runTitle = 'Run'): array {
		$section = new RunSection();
		$section->setId($runId);
		$section->setRunId($runId);

		$step = new RunStep();
		$step->setId($runId);
		$step->setTitle($stepTitle);
		$step->setDueAt($dueAt);

		return [
			'run' => $this->makeRun($runId, $runTitle),
			'section' => $section,
			'step' => $step,
			'overdue' => $overdue,
			'dueToday' => $dueToday,
		];
	}

	public function testMetadata(): void {
		$widget = $this->widget();

		self::assertSame('runbook', $widget->getId());
		self::assertSame('Runbook', $widget->getTitle());
		self::assertGreaterThanOrEqual(0, $widget->getOrder());
		self::assertSame('/index.php/apps/runbook/#/my-work', $widget->getUrl());
		self::assertSame('https://cloud.example.com/apps/runbook/img/app-dark.svg', $widget->getIconUrl());
	}

	public function testItemsAreBoundedAndUsesProvidedUser(): void {
		$items = [];
		for ($i = 1; $i <= 12; $i++) {
			$items[] = $this->item($i, 'Step ' . $i);
		}
		$this->work->expects(self::once())
			->method('myWorkForUser')
			->with('bob', 'all')
			->willReturn($items);

		$result = $this->widget()->getItemsV2('bob', null, 50);

		self::assertCount(7, $result->getItems());
	}

	public function testSummaryCountsOverdueAndDueToday(): void {
		$this->work->method('myWorkForUser')->willReturn([
			$this->item(1, 'A', true, false),
			$this->item(2, 'B', false, true),
			$this->item(3, 'C', true, true),
			$this->item(4, 'D'),
		]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		// The summary is the "half empty" message rendered above the list.
		self::assertSame('4 active, 2 overdue, 2 due today', $result->getHalfEmptyContentMessage());
	}

	public function testSummaryInterpolatesCounts(): void {
		$this->work->method('myWorkForUser')->willReturn([
			$this->item(1, 'A', true, false),
			$this->item(2, 'B', true, false),
			$this->item(3, 'C', false, true),
		]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		self::assertSame('3 active, 2 overdue, 1 due today', $result->getHalfEmptyContentMessage());
	}

	public function testSubtitleUsesActualRunTitle(): void {
		$dueAt = 1893456000; // 2030-01-01
		$this->work->method('myWorkForUser')->willReturn([
			$this->item(1, 'Deploy', false, false, $dueAt, 'Production release'),
		]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		/** @var WidgetItem $item */
		$item = $result->getItems()[0];
		self::assertSame('Production release · Due ' . date('Y-m-d', $dueAt), $item->getSubtitle());
	}

	public function testOverdueSubtitle(): void {
		$this->work->method('myWorkForUser')->willReturn([
			$this->item(1, 'Deploy', true, false, 1700000000, 'Production release'),
		]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		/** @var WidgetItem $item */
		$item = $result->getItems()[0];
		self::assertSame('Production release · Overdue', $item->getSubtitle());
	}

	public function testDueTodaySubtitle(): void {
		$this->work->method('myWorkForUser')->willReturn([
			$this->item(1, 'Deploy', false, true, 1700000100, 'Production release'),
		]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		/** @var WidgetItem $item */
		$item = $result->getItems()[0];
		self::assertSame('Production release · Due today', $item->getSubtitle());
	}

	public function testNoLiteralPlaceholdersRemain(): void {
		$this->work->method('myWorkForUser')->willReturn([
			$this->item(1, 'Overdue step', true, false, 1700000000, 'Alpha'),
			$this->item(2, 'Today step', false, true, 1700000100, 'Beta'),
			$this->item(3, 'Dated step', false, false, 1893456000, 'Gamma'),
			$this->item(4, 'No date step', false, false, null, 'Delta'),
		]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		$texts = [
			$result->getHalfEmptyContentMessage(),
			$result->getEmptyContentMessage(),
		];
		foreach ($result->getItems() as $item) {
			$texts[] = $item->getTitle();
			$texts[] = $item->getSubtitle();
		}

		foreach ($texts as $text) {
			foreach (['{active}', '{overdue}', '{dueToday}', '{run}', '{date}', '%1$', '%2$', '%3$'] as $placeholder) {
				self::assertStringNotContainsString($placeholder, $text, 'Unresolved placeholder in: ' . $text);
			}
		}
	}

	public function testEmptyMessageIsNotShownWhenWorkExists(): void {
		$this->work->method('myWorkForUser')->willReturn([$this->item(1, 'A', false, true)]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		self::assertCount(1, $result->getItems());
		self::assertStringNotContainsString('No assigned work', $result->getHalfEmptyContentMessage());
		self::assertStringContainsString('1 active', $result->getHalfEmptyContentMessage());
	}

	public function testEmptyState(): void {
		$this->work->method('myWorkForUser')->willReturn([]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		self::assertSame([], $result->getItems());
		self::assertSame('No assigned work right now.', $result->getEmptyContentMessage());
	}

	public function testItemSubtitleShowsRunAndActualDueDate(): void {
		$dueAt = 1893456000; // 2030-01-01
		$this->work->method('myWorkForUser')->willReturn([$this->item(1, 'Deploy', false, false, $dueAt)]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		/** @var WidgetItem $item */
		$item = $result->getItems()[0];
		self::assertSame('Deploy', $item->getTitle());
		self::assertStringContainsString('Run', $item->getSubtitle());
		self::assertStringContainsString(date('Y-m-d', $dueAt), $item->getSubtitle());
	}

	public function testItemLinksToRun(): void {
		$this->work->method('myWorkForUser')->willReturn([$this->item(21, 'Deploy')]);

		$result = $this->widget()->getItemsV2('bob', null, 7);

		/** @var WidgetItem $item */
		$item = $result->getItems()[0];
		self::assertSame('Deploy', $item->getTitle());
		self::assertSame('/index.php/apps/runbook/#/run/21', $item->getLink());
	}

	public function testButtonsLinkToMyWork(): void {
		$buttons = $this->widget()->getWidgetButtons('bob');

		self::assertCount(1, $buttons);
		self::assertSame('/index.php/apps/runbook/#/my-work', $buttons[0]->getLink());
	}

	public function testDisabledWidgetReturnsNoContent(): void {
		$this->work->expects(self::never())->method('myWorkForUser');

		$result = $this->widget(false)->getItemsV2('bob', null, 7);

		self::assertSame([], $result->getItems());
		self::assertNotSame('', $result->getHalfEmptyContentMessage());
	}
}
