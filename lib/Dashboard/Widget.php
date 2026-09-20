<?php

declare(strict_types=1);

namespace OCA\Runbook\Dashboard;

use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\WorkService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Dashboard widget that surfaces the current user's assigned work.
 *
 * All data comes from {@see WorkService}, so it reuses the exact same access
 * rules as "My Work" and never exposes inaccessible runs.
 */
class Widget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IOptionWidget {
	private const MAX_ITEMS = 7;

	public function __construct(
		private readonly WorkService $work,
		private readonly AdminSettings $settings,
		private readonly IURLGenerator $url,
		private readonly IFactory $l10nFactory,
	) {
	}

	public function getId(): string {
		return 'runbook';
	}

	public function getTitle(): string {
		return $this->l10n()->t('Runbook');
	}

	public function getOrder(): int {
		return 40;
	}

	public function getIconClass(): string {
		return 'icon-runbook';
	}

	public function getIconUrl(): string {
		// The Dashboard renders widget icons on the main background and applies
		// the theme-aware invert filter, so the dark icon variant is expected.
		return $this->url->getAbsoluteURL($this->url->imagePath('runbook', 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->myWorkLink();
	}

	public function load(): void {
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->settings->isDashboardEnabled()) {
			return new WidgetItems(
				[],
				'',
				$this->l10n()->t('The Runbook dashboard widget is disabled by your administrator.'),
			);
		}

		$limit = max(1, min($limit, self::MAX_ITEMS));
		$work = $this->work->myWorkForUser($userId, 'all');

		$overdue = 0;
		$dueToday = 0;
		foreach ($work as $item) {
			if ($item['overdue']) {
				$overdue++;
			}
			if ($item['dueToday']) {
				$dueToday++;
			}
		}

		$items = [];
		foreach (array_slice($work, 0, $limit) as $item) {
			$items[] = new WidgetItem(
				$item['step']->getTitle(),
				$this->subtitle($item['run']->getTitle(), $item['step']->getDueAt(), $item['overdue'], $item['dueToday']),
				$this->runLink($item['run']->getId()),
			);
		}

		// WidgetItems maps the second constructor argument to the empty state
		// (shown only when there are no items) and the third to the "half
		// empty" summary shown above the list. The summary must never be used
		// as the empty state and the empty message must never be shown while
		// pending work exists.
		return new WidgetItems(
			$items,
			$this->l10n()->t('No assigned work right now.'),
			$this->l10n()->t('%1$d active, %2$d overdue, %3$d due today', [
				count($work),
				$overdue,
				$dueToday,
			]),
		);
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->myWorkLink(),
				$this->l10n()->t('Open My Work'),
			),
		];
	}

	public function getWidgetOptions(): WidgetOptions {
		return WidgetOptions::getDefault();
	}

	private function subtitle(string $runTitle, ?int $dueAt, bool $overdue, bool $dueToday): string {
		if ($overdue) {
			return $this->l10n()->t('%1$s · Overdue', [$runTitle]);
		}
		if ($dueToday) {
			return $this->l10n()->t('%1$s · Due today', [$runTitle]);
		}
		if ($dueAt !== null) {
			return $this->l10n()->t('%1$s · Due %2$s', [$runTitle, date('Y-m-d', $dueAt)]);
		}

		return $runTitle;
	}

	private function myWorkLink(): string {
		return $this->url->linkToRoute('runbook.page.index') . '#/my-work';
	}

	private function runLink(int $runId): string {
		return $this->url->linkToRoute('runbook.page.index') . '#/run/' . $runId;
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get('runbook');
	}
}
