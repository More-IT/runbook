<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Search;

use OCA\Runbook\Search\Provider;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\SearchService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase {
	/** @var SearchService&MockObject */
	private SearchService $search;

	protected function setUp(): void {
		$this->search = $this->createMock(SearchService::class);
	}

	private function adminSettings(bool $searchEnabled = true): AdminSettings {
		/** @var IAppConfig&MockObject $appConfig */
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false, bool $lazy = false): bool => $key === AdminSettings::KEY_SEARCH_ENABLED ? $searchEnabled : $default,
		);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = '', bool $lazy = false): string => $default);
		$appConfig->method('getValueInt')->willReturnCallback(static fn (string $app, string $key, int $default = 0, bool $lazy = false): int => $default);
		$appConfig->method('getValueArray')->willReturnCallback(static fn (string $app, string $key, array $default = [], bool $lazy = false): array => $default);

		return new AdminSettings($appConfig, $this->createMock(IGroupManager::class));
	}

	private function provider(bool $searchEnabled = true): Provider {
		/** @var IL10N&MockObject $l10n */
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(function (string $text, array $parameters = []): string {
			foreach ($parameters as $key => $value) {
				$text = str_replace('{' . $key . '}', (string)$value, $text);
			}

			return $text;
		});

		/** @var IFactory&MockObject $factory */
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		/** @var IURLGenerator&MockObject $url */
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/index.php/apps/runbook/');

		return new Provider($this->search, $this->adminSettings($searchEnabled), $url, $factory);
	}

	private function query(string $term, int $limit): ISearchQuery {
		/** @var ISearchQuery&MockObject $query */
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);

		return $query;
	}

	private function user(): IUser {
		/** @var IUser&MockObject $user */
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}

	public function testIdentity(): void {
		$provider = $this->provider();
		self::assertSame('runbook', $provider->getId());
		self::assertSame('Runbook', $provider->getName());
		self::assertSame(20, $provider->getOrder('', []));
	}

	public function testSearchSerializesEntriesWithSafeLinks(): void {
		$this->search->expects(self::once())
			->method('search')
			->with('alice', 'release', 10)
			->willReturn([
				['type' => SearchService::TYPE_TEMPLATE, 'id' => 3, 'title' => 'Release template', 'description' => 'A description', 'updatedAt' => 1700000000],
				['type' => SearchService::TYPE_RUN, 'id' => 8, 'title' => 'Release run', 'description' => '', 'updatedAt' => 1700000001],
			]);

		$result = $this->provider()->search($this->user(), $this->query('release', 10));

		self::assertInstanceOf(SearchResult::class, $result);
		/** @var list<\OCP\Search\SearchResultEntry> $entries */
		$entries = $result->jsonSerialize()['entries'];
		self::assertCount(2, $entries);
		$templateEntry = $entries[0]->jsonSerialize();
		$runEntry = $entries[1]->jsonSerialize();
		self::assertSame('Release template', $templateEntry['title']);
		self::assertSame('/index.php/apps/runbook/#/template/3', $templateEntry['resourceUrl']);
		self::assertStringContainsString('Template', $templateEntry['subline']);
		self::assertSame('/index.php/apps/runbook/#/run/8', $runEntry['resourceUrl']);
		self::assertStringContainsString('Run', $runEntry['subline']);
		self::assertSame(SearchService::TYPE_TEMPLATE, $templateEntry['attributes']['type']);
	}

	public function testSearchDisabledReturnsNoResults(): void {
		$this->search->expects(self::never())->method('search');

		$result = $this->provider(false)->search($this->user(), $this->query('release', 10));

		self::assertSame([], $result->jsonSerialize()['entries']);
	}

	public function testEmptyResultIsACompleteSection(): void {
		$this->search->method('search')->willReturn([]);

		$result = $this->provider()->search($this->user(), $this->query('nope', 10));

		self::assertSame([], $result->jsonSerialize()['entries']);
		self::assertFalse($result->jsonSerialize()['isPaginated']);
	}

	public function testDescriptionIsTruncated(): void {
		$this->search->method('search')->willReturn([
			['type' => SearchService::TYPE_RUN, 'id' => 1, 'title' => 'Long', 'description' => str_repeat('x', 400), 'updatedAt' => 1700000000],
		]);

		/** @var list<\OCP\Search\SearchResultEntry> $entries */
		$entries = $this->provider()->search($this->user(), $this->query('long', 10))->jsonSerialize()['entries'];
		$subline = $entries[0]->jsonSerialize()['subline'];

		self::assertStringContainsString('…', $subline);
		self::assertLessThan(300, strlen($subline));
	}
}
