<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\Run;
use OCA\Runbook\Db\RunAclMapper;
use OCA\Runbook\Db\RunMapper;
use OCA\Runbook\Db\RunStepMapper;
use OCA\Runbook\Db\Template;
use OCA\Runbook\Db\TemplateAclMapper;
use OCA\Runbook\Db\TemplateMapper;

/**
 * Access-aware unified search over templates and runs.
 *
 * Only records the user can actually access are queried; inaccessible titles
 * and metadata are never loaded. Search terms are sanitized so that user input
 * can never introduce SQL LIKE wildcards, and results are always bounded.
 */
class SearchService {
	public const MIN_TERM_LENGTH = 2;
	public const MAX_RESULTS = 25;
	private const MAX_TERM_LENGTH = 100;

	public const TYPE_TEMPLATE = 'template';
	public const TYPE_RUN = 'run';

	public function __construct(
		private readonly TemplateMapper $templates,
		private readonly TemplateAclMapper $templateAcl,
		private readonly PermissionService $templatePermissions,
		private readonly RunMapper $runs,
		private readonly RunAclMapper $runAcl,
		private readonly RunStepMapper $runSteps,
		private readonly RunAccessService $runAccess,
	) {
	}

	/**
	 * @return list<array{type: string, id: int, title: string, description: string, updatedAt: int}>
	 */
	public function search(string $uid, string $term, int $limit): array {
		$term = $this->normalizeTerm($term);
		if ($term === '') {
			return [];
		}

		$limit = max(1, min($limit, self::MAX_RESULTS));
		$results = [
			...$this->searchTemplates($uid, $term, $limit),
			...$this->searchRuns($uid, $term, $limit),
		];

		usort($results, static fn (array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);

		return array_slice($results, 0, $limit);
	}

	/**
	 * @return list<array{type: string, id: int, title: string, description: string, updatedAt: int}>
	 */
	private function searchTemplates(string $uid, string $term, int $limit): array {
		$groupIds = $this->templatePermissions->getUserGroupIds($uid);
		$templateIds = $this->templateAcl->findTemplateIdsForPrincipal($uid, $groupIds);

		$results = [];
		foreach ($this->templates->searchAccessible($uid, $templateIds, $term, $limit) as $template) {
			$results[] = $this->templateResult($template);
		}

		return $results;
	}

	/**
	 * @return list<array{type: string, id: int, title: string, description: string, updatedAt: int}>
	 */
	private function searchRuns(string $uid, string $term, int $limit): array {
		$groupIds = $this->runAccess->getUserGroupIds($uid);
		$runIds = array_values(array_unique(array_merge(
			$this->runAcl->findRunIdsForPrincipal($uid, $groupIds),
			$this->runSteps->findDistinctRunIdsForPrincipal($uid, $groupIds),
		)));

		$results = [];
		foreach ($this->runs->searchAccessible($uid, $runIds, $term, $limit) as $run) {
			$results[] = $this->runResult($run);
		}

		return $results;
	}

	/**
	 * @return array{type: string, id: int, title: string, description: string, updatedAt: int}
	 */
	private function templateResult(Template $template): array {
		return [
			'type' => self::TYPE_TEMPLATE,
			'id' => $template->getId(),
			'title' => $template->getTitle(),
			'description' => $template->getDescription(),
			'updatedAt' => $template->getUpdatedAt(),
		];
	}

	/**
	 * @return array{type: string, id: int, title: string, description: string, updatedAt: int}
	 */
	private function runResult(Run $run): array {
		return [
			'type' => self::TYPE_RUN,
			'id' => $run->getId(),
			'title' => $run->getTitle(),
			'description' => $run->getDescription(),
			'updatedAt' => $run->getUpdatedAt(),
		];
	}

	/**
	 * Trim, bound and strip LIKE wildcards and escape characters from a term.
	 */
	private function normalizeTerm(string $term): string {
		$term = str_replace(['%', '_', '\\'], ' ', $term);
		$term = trim(preg_replace('/\s+/u', ' ', $term) ?? '');
		if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
			$term = mb_substr($term, 0, self::MAX_TERM_LENGTH);
		}
		if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
			return '';
		}

		return $term;
	}
}
