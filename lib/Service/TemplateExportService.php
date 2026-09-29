<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use OCA\Runbook\Db\TemplateSection;
use OCA\Runbook\Db\TemplateStep;

/**
 * Builds the portable JSON document for a single template.
 *
 * The document is a pure serialisation of the authoring model: it has no
 * database ids, no run data and no internal bookkeeping. Sections and steps are
 * addressed by stable, document-local references (`s1`, `t1`, ...) and every
 * dependency / condition reference is rewritten to them.
 *
 * Reading is enforced through {@see TemplateService} (the same view permission
 * as the detail endpoint); exporting never writes.
 *
 * @psalm-type ExportSection array{
 *     ref: string,
 *     title: string,
 *     description: string,
 *     notes: string,
 *     dependsOn: list<string>,
 *     conditions: list<array{stepRef: string, operator: string, value?: mixed}>
 * }
 * @psalm-type ExportStep array{
 *     ref: string,
 *     sectionRef: string,
 *     title: string,
 *     description: string,
 *     type: string,
 *     required: bool,
 *     position: int,
 *     config: object,
 *     defaultAssignee: string|null,
 *     dueOffset: string|null
 * }
 * @psalm-type ExportDocument array{
 *     format: string,
 *     schemaVersion: int,
 *     template: array{title: string, description: string},
 *     sections: list<ExportSection>,
 *     steps: list<ExportStep>
 * }
 */
class TemplateExportService {
	public const FORMAT = 'runbook-template';
	public const SCHEMA_VERSION = 1;

	public function __construct(
		private readonly TemplateService $templates,
	) {
	}

	/**
	 * Export a template the current user may view.
	 *
	 * @param int $templateId Template identifier.
	 * @return ExportDocument
	 */
	public function export(int $templateId): array {
		$template = $this->templates->getTemplate($templateId);
		$sections = $this->sorted($this->templates->getSections($templateId));

		// First pass: assign stable, positional references to sections and steps.
		/** @var array<int, string> $sectionRefs */
		$sectionRefs = [];
		/** @var array<int, string> $stepRefs */
		$stepRefs = [];
		/** @var list<array{0: TemplateSection, 1: string, 2: list<TemplateStep>}> $prepared */
		$prepared = [];
		$sectionNumber = 1;
		$stepNumber = 1;
		foreach ($sections as $section) {
			$ref = 's' . $sectionNumber;
			$sectionNumber++;
			$sectionRefs[$section->getId()] = $ref;
			$steps = $this->sorted($this->templates->getSteps($section->getId()));
			$prepared[] = [$section, $ref, $steps];
			foreach ($steps as $step) {
				$stepRefs[$step->getId()] = 't' . $stepNumber;
				$stepNumber++;
			}
		}

		$exportSections = [];
		foreach ($prepared as [$section, $ref]) {
			$exportSections[] = [
				'ref' => $ref,
				'title' => $section->getTitle(),
				'description' => $section->getDescription(),
				'notes' => $section->getNotes(),
				'dependsOn' => $this->mapDependencies($section, $sectionRefs),
				'conditions' => $this->mapConditions($section, $stepRefs),
			];
		}

		$exportSteps = [];
		foreach ($prepared as [$_section, $sectionRef, $steps]) {
			foreach ($steps as $step) {
				$exportSteps[] = [
					'ref' => $stepRefs[$step->getId()],
					'sectionRef' => $sectionRef,
					'title' => $step->getTitle(),
					'description' => $step->getDescription(),
					'type' => $step->getType(),
					'required' => $step->getRequired(),
					'position' => $step->getPosition(),
					'config' => $this->stepConfig($step),
					'defaultAssignee' => $step->getDefaultAssignee(),
					'dueOffset' => $step->getDueOffset(),
				];
			}
		}

		return [
			'format' => self::FORMAT,
			'schemaVersion' => self::SCHEMA_VERSION,
			'template' => [
				'title' => $template->getTitle(),
				'description' => $template->getDescription(),
			],
			'sections' => $exportSections,
			'steps' => $exportSteps,
		];
	}

	/**
	 * Sort sections/steps deterministically by position, then id.
	 *
	 * @template T of TemplateSection|TemplateStep
	 * @param list<T> $items
	 * @return list<T>
	 */
	private function sorted(array $items): array {
		usort($items, static function (TemplateSection|TemplateStep $left, TemplateSection|TemplateStep $right): int {
			return [$left->getPosition(), (int)$left->getId()] <=> [$right->getPosition(), (int)$right->getId()];
		});

		return $items;
	}

	/**
	 * Rewrite stored section ids into document-local references.
	 *
	 * @param TemplateSection $section Section being exported.
	 * @param array<int, string> $sectionRefs Section id to reference map.
	 * @return list<string>
	 */
	private function mapDependencies(TemplateSection $section, array $sectionRefs): array {
		$refs = [];
		foreach ($section->getDependsOnIds() as $depId) {
			if (!isset($sectionRefs[$depId])) {
				// Stored reference cannot be mapped: fail loudly instead of
				// silently dropping a dependency from the export.
				throw new ValidationException('export_reference_unresolved');
			}
			$refs[] = $sectionRefs[$depId];
		}
		usort($refs, static fn (string $left, string $right): int => (int)substr($left, 1) <=> (int)substr($right, 1));

		return $refs;
	}

	/**
	 * Rewrite stored condition step ids into document-local references, keeping
	 * the typed comparison value.
	 *
	 * @param TemplateSection $section Section being exported.
	 * @param array<int, string> $stepRefs Step id to reference map.
	 * @return list<array{stepRef: string, operator: string, value?: mixed}>
	 */
	private function mapConditions(TemplateSection $section, array $stepRefs): array {
		$conditions = [];
		foreach ($section->getConditions() as $condition) {
			$stepId = (int)($condition['stepId'] ?? 0);
			if (!isset($stepRefs[$stepId])) {
				throw new ValidationException('export_reference_unresolved');
			}
			$entry = [
				'stepRef' => $stepRefs[$stepId],
				'operator' => (string)($condition['operator'] ?? ''),
			];
			if (array_key_exists('value', $condition)) {
				/** @psalm-suppress MixedAssignment JSON condition values are intentionally untyped. */
				$entry['value'] = $condition['value'];
			}
			$conditions[] = $entry;
		}

		return $conditions;
	}

	/**
	 * The complete persisted step configuration, always a JSON object (empty
	 * `{}` when nothing is stored). All authored keys are kept for every step
	 * type — including extra keys on SELECT/NUMBER — with their values and order
	 * unchanged, so no user configuration is lost. An absent NUMBER `unit` stays
	 * absent (it is not invented as `""`).
	 *
	 * @param TemplateStep $step Step being exported.
	 */
	private function stepConfig(TemplateStep $step): object {
		$config = $step->getConfigArray();

		return (object)$config;
	}
}
