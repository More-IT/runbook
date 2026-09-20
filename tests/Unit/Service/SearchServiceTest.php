<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;
use OCA\Runbook\Service\PermissionService;
use OCA\Runbook\Service\RunAccessService;
use OCA\Runbook\Service\SearchService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SearchServiceTest extends TestCase {
	/** @var TemplateMapper&MockObject */
	private TemplateMapper $templates;
	/** @var TemplateAclMapper&MockObject */
	private TemplateAclMapper $templateAcl;
	/** @var PermissionService&MockObject */
	private PermissionService $templatePermissions;
	/** @var RunMapper&MockObject */
	private RunMapper $runs;
	/** @var RunAclMapper&MockObject */
	private RunAclMapper $runAcl;
	/** @var RunStepMapper&MockObject */
	private RunStepMapper $runSteps;
	/** @var RunAccessService&MockObject */
	private RunAccessService $runAccess;

	protected function setUp(): void {
		$this->templates = $this->createMock(TemplateMapper::class);
		$this->templateAcl = $this->createMock(TemplateAclMapper::class);
		$this->templatePermissions = $this->createMock(PermissionService::class);
		$this->runs = $this->createMock(RunMapper::class);
		$this->runAcl = $this->createMock(RunAclMapper::class);
		$this->runSteps = $this->createMock(RunStepMapper::class);
		$this->runAccess = $this->createMock(RunAccessService::class);
	}

	private function service(): SearchService {
		return new SearchService(
			$this->templates,
			$this->templateAcl,
			$this->templatePermissions,
			$this->runs,
			$this->runAcl,
			$this->runSteps,
			$this->runAccess,
		);
	}

	private function template(int $id, string $title, int $updatedAt): Template {
		$template = new Template();
		$template->setId($id);
		$template->setTitle($title);
		$template->setDescription('description');
		$template->setUpdatedAt($updatedAt);

		return $template;
	}

	private function makeRun(int $id, string $title, int $updatedAt): Run {
		$run = new Run();
		$run->setId($id);
		$run->setTitle($title);
		$run->setDescription('description');
		$run->setUpdatedAt($updatedAt);

		return $run;
	}

	public function testSearchesTemplatesAndRunsWithinAccess(): void {
		$this->templatePermissions->method('getUserGroupIds')->with('alice')->willReturn(['eng']);
		$this->templateAcl->expects(self::once())
			->method('findTemplateIdsForPrincipal')
			->with('alice', ['eng'])
			->willReturn([2]);
		$this->templates->expects(self::once())
			->method('searchAccessible')
			->with('alice', [2], 'release', 10)
			->willReturn([$this->template(2, 'Release template', 200)]);

		$this->runAccess->method('getUserGroupIds')->with('alice')->willReturn(['eng']);
		$this->runAcl->expects(self::once())
			->method('findRunIdsForPrincipal')
			->with('alice', ['eng'])
			->willReturn([5]);
		$this->runSteps->expects(self::once())
			->method('findDistinctRunIdsForPrincipal')
			->with('alice', ['eng'])
			->willReturn([5, 6]);
		$this->runs->expects(self::once())
			->method('searchAccessible')
			->with('alice', [5, 6], 'release', 10)
			->willReturn([$this->makeRun(5, 'Release run', 300)]);

		$results = $this->service()->search('alice', 'release', 10);

		self::assertCount(2, $results);
		// Sorted by updatedAt descending: run 300 first, template 200 second.
		self::assertSame(SearchService::TYPE_RUN, $results[0]['type']);
		self::assertSame(SearchService::TYPE_TEMPLATE, $results[1]['type']);
	}

	public function testMergesAclAndAssignmentRunIds(): void {
		$this->templatePermissions->method('getUserGroupIds')->willReturn([]);
		$this->templateAcl->method('findTemplateIdsForPrincipal')->willReturn([]);
		$this->templates->method('searchAccessible')->willReturn([]);

		$this->runAccess->method('getUserGroupIds')->willReturn([]);
		$this->runAcl->method('findRunIdsForPrincipal')->willReturn([1, 2]);
		$this->runSteps->method('findDistinctRunIdsForPrincipal')->willReturn([2, 3]);
		$this->runs->expects(self::once())
			->method('searchAccessible')
			->with('alice', [1, 2, 3], 'xy', 10)
			->willReturn([]);

		$this->service()->search('alice', 'xy', 10);
	}

	public function testAccessScopingUsesOnlyAccessibleIds(): void {
		$this->templatePermissions->method('getUserGroupIds')->willReturn([]);
		$this->templateAcl->method('findTemplateIdsForPrincipal')->willReturn([]);
		// No accessible ids: only the owner condition applies, never an unbounded scan.
		$this->templates->expects(self::once())->method('searchAccessible')->with('alice', [], 'xy', 10)->willReturn([]);
		$this->runAccess->method('getUserGroupIds')->willReturn([]);
		$this->runAcl->method('findRunIdsForPrincipal')->willReturn([]);
		$this->runSteps->method('findDistinctRunIdsForPrincipal')->willReturn([]);
		$this->runs->expects(self::once())->method('searchAccessible')->with('alice', [], 'xy', 10)->willReturn([]);

		$this->service()->search('alice', 'xy', 10);
	}

	public function testEmptyQueryReturnsNothing(): void {
		$this->templateAcl->expects(self::never())->method('findTemplateIdsForPrincipal');
		$this->templates->expects(self::never())->method('searchAccessible');
		$this->runs->expects(self::never())->method('searchAccessible');

		self::assertSame([], $this->service()->search('alice', '   ', 10));
	}

	public function testShortQueryReturnsNothing(): void {
		$this->templates->expects(self::never())->method('searchAccessible');

		self::assertSame([], $this->service()->search('alice', 'a', 10));
	}

	public function testWildcardsAreStrippedFromTerm(): void {
		$this->templatePermissions->method('getUserGroupIds')->willReturn([]);
		$this->templateAcl->method('findTemplateIdsForPrincipal')->willReturn([]);
		$this->templates->expects(self::once())
			->method('searchAccessible')
			->with('alice', [], 'a b c', 10)
			->willReturn([]);
		$this->runAccess->method('getUserGroupIds')->willReturn([]);
		$this->runAcl->method('findRunIdsForPrincipal')->willReturn([]);
		$this->runSteps->method('findDistinctRunIdsForPrincipal')->willReturn([]);
		$this->runs->method('searchAccessible')->willReturn([]);

		$this->service()->search('alice', 'a%b_c', 10);
	}

	public function testLimitIsCapped(): void {
		$this->templatePermissions->method('getUserGroupIds')->willReturn([]);
		$this->templateAcl->method('findTemplateIdsForPrincipal')->willReturn([]);
		$this->templates->expects(self::once())
			->method('searchAccessible')
			->with('alice', [], 'xy', SearchService::MAX_RESULTS)
			->willReturn([]);
		$this->runAccess->method('getUserGroupIds')->willReturn([]);
		$this->runAcl->method('findRunIdsForPrincipal')->willReturn([]);
		$this->runSteps->method('findDistinctRunIdsForPrincipal')->willReturn([]);
		$this->runs->expects(self::once())
			->method('searchAccessible')
			->with('alice', [], 'xy', SearchService::MAX_RESULTS)
			->willReturn([]);

		$this->service()->search('alice', 'xy', 1000);
	}
}
