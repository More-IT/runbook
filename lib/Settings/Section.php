<?php

declare(strict_types=1);

namespace OCA\Runbook\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Settings\IIconSection;

/**
 * Administration section that groups the Runbook settings.
 */
class Section implements IIconSection {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return 'runbook';
	}

	public function getName(): string {
		return $this->l10n()->t('Runbook');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getIcon(): string {
		// Nextcloud renders settings section icons on the main background and
		// inverts them in dark themes, so the dark icon variant is expected.
		return $this->url->imagePath('runbook', 'app-dark.svg');
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get('runbook');
	}
}
