<?php

declare(strict_types=1);

namespace OCA\Runbook\Search;

use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\SearchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Unified Search provider for Runbook templates and runs.
 *
 * Access control is enforced in {@see SearchService}: only accessible records
 * are queried, so inaccessible titles never reach the client. Links deep-link
 * into the correct Runbook view and remain protected by the app's own access
 * checks.
 */
class Provider implements IProvider {
	private const MAX_DESCRIPTION_LENGTH = 150;

	public function __construct(
		private readonly SearchService $search,
		private readonly AdminSettings $settings,
		private readonly IURLGenerator $url,
		private readonly IFactory $l10nFactory,
	) {
	}

	public function getId(): string {
		return 'runbook';
	}

	public function getName(): string {
		return $this->l10n()->t('Runbook');
	}

	/**
	 * @param string $route Current route.
	 * @param array<string, mixed> $routeParameters Current route parameters.
	 */
	public function getOrder(string $route, array $routeParameters): ?int {
		return 20;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->settings->isSearchEnabled()) {
			return SearchResult::complete($this->getName(), []);
		}

		$results = $this->search->search($user->getUID(), $query->getTerm(), $query->getLimit());

		$entries = [];
		foreach ($results as $result) {
			$entry = new SearchResultEntry(
				'',
				$result['title'],
				$this->subline($result['type'], $result['description'], $result['updatedAt']),
				$this->link($result['type'], $result['id']),
			);
			$entry->addAttribute('type', $result['type']);
			$entry->addAttribute('timestamp', (string)$result['updatedAt']);
			$entries[] = $entry;
		}

		return SearchResult::complete($this->getName(), $entries);
	}

	private function subline(string $type, string $description, int $updatedAt): string {
		$label = $type === SearchService::TYPE_TEMPLATE
			? $this->l10n()->t('Template')
			: $this->l10n()->t('Run');
		$date = date('Y-m-d', $updatedAt);

		$excerpt = $this->excerpt($description);
		if ($excerpt === '') {
			return $this->l10n()->t('{type} · {date}', ['type' => $label, 'date' => $date]);
		}

		return $this->l10n()->t('{type} · {date} · {description}', [
			'type' => $label,
			'date' => $date,
			'description' => $excerpt,
		]);
	}

	private function excerpt(string $description): string {
		$description = trim(preg_replace('/\s+/u', ' ', $description) ?? '');
		if ($description === '') {
			return '';
		}
		if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
			$description = mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH) . '…';
		}

		return $description;
	}

	private function link(string $type, int $id): string {
		$base = $this->url->linkToRoute('runbook.page.index');
		if ($type === SearchService::TYPE_TEMPLATE) {
			return $base . '#/template/' . $id;
		}

		return $base . '#/run/' . $id;
	}

	private function l10n(): IL10N {
		return $this->l10nFactory->get('runbook');
	}
}
