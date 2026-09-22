<?php

declare(strict_types=1);

namespace OCA\Runbook\Service;

use JsonException;
use OCA\Runbook\Db\Template;

/**
 * Imports a portable template export document (issue #30 contract) as a new,
 * independent DRAFT template owned by the importing user (issue #31).
 *
 * The document is validated and normalised up front, then written inside a
 * single transaction so a late failure leaves no template, section, step or
 * flow-rule rows behind. Flow rules are applied in a second pass after every
 * section and step exists, which lets document-local `s…`/`t…` references be
 * mapped to freshly generated database identifiers and reuses the authoring
 * validation in {@see TemplateService} instead of duplicating the flow engine.
 *
 * @phpstan-type ImportCondition array{stepRef: string, operator: string, value?: mixed}
 * @phpstan-type ImportStep array{ref: string, title: string, description: string, type: string, required: bool, position: int, config: array<string, mixed>, defaultAssignee: string|null, dueOffset: string|null}
 * @phpstan-type ImportSection array{ref: string, title: string, description: string, notes: string, dependsOn: list<string>, conditions: list<ImportCondition>, steps: list<ImportStep>}
 */
class TemplateImportService {
	public const FORMAT = 'runbook-template';
	public const SCHEMA_VERSION = 1;

	/**
	 * Maximum accepted size, in bytes, of the canonical compact UTF-8 encoding
	 * of the decoded import document ({@see self::documentSizeBytes()}).
	 *
	 * This is a single canonical limit, not a wire-byte limit: the installed
	 * Nextcloud `IRequest` API exposes no way to read the raw request body
	 * (`getParams()`/`getHeader()` only), so neither the actual body length nor a
	 * client-declared `Content-Length` can be trusted as the document size. The
	 * same rule therefore applies to HTTP requests and direct service calls.
	 * Consequences: a body padded with insignificant whitespace can be larger
	 * than the limit on the wire (the raw request size is bounded separately by
	 * PHP/Nextcloud request limits), while a valid multibyte document is counted
	 * by its real UTF-8 length because `json_encode()` is called with
	 * `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES` (never the default, which
	 * escapes `é` to `\u00e9` and overstates Portuguese text). The frontend
	 * measures exactly the same canonical payload.
	 */
	public const MAX_DOCUMENT_BYTES = 2000000;
	/** Maximum number of sections in one imported template. */
	public const MAX_SECTIONS = 500;
	/** Maximum number of steps across all sections. */
	public const MAX_STEPS = 5000;
	/** Maximum number of dependency edges across all sections. */
	public const MAX_DEPENDENCIES = 5000;
	/** Maximum number of conditions across all sections. */
	public const MAX_CONDITIONS = 5000;

	public function __construct(
		private readonly TemplateService $templates,
		private readonly PrincipalValidator $principalValidator,
		private readonly TransactionRunner $transactionRunner,
	) {
	}

	/**
	 * Size in bytes of the canonical compact UTF-8 JSON for a document.
	 *
	 * Uses `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`, matching the
	 * compact `JSON.stringify` payload the frontend measures, so multibyte
	 * characters are counted as their real UTF-8 length instead of `\uXXXX`
	 * escapes. This is the canonical quantity {@see self::MAX_DOCUMENT_BYTES}
	 * is defined on.
	 *
	 * Values that cannot be encoded (non-finite floats such as the `INF`
	 * produced by decoding `1e400`, malformed UTF-8, or nesting deeper than
	 * PHP's encoder limit) are reported as an invalid document instead of
	 * escaping as an uncaught `JsonException` (which would be a 500).
	 *
	 * @param array<string, mixed> $document Decoded document.
	 *
	 * @throws ValidationException When the document cannot be JSON-encoded.
	 */
	public static function documentSizeBytes(array $document): int {
		try {
			$encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new ValidationException('invalid_import_document');
		}

		return strlen($encoded);
	}

	/**
	 * Cheap pre-encode guard so an oversized collection is rejected before the
	 * 2 MB canonical encoding is built.
	 *
	 * @param array<string, mixed> $document Decoded document.
	 *
	 * @throws ValidationException When the collection counts exceed the limits.
	 */
	public static function assertCollectionLimits(array $document): void {
		if (is_array($document['sections'] ?? null)
			&& array_is_list($document['sections'])
			&& count($document['sections']) > self::MAX_SECTIONS) {
			throw new ValidationException('import_too_many_sections');
		}
		if (is_array($document['steps'] ?? null)
			&& array_is_list($document['steps'])
			&& count($document['steps']) > self::MAX_STEPS) {
			throw new ValidationException('import_too_many_steps');
		}
	}

	/**
	 * Enforce {@see self::MAX_DOCUMENT_BYTES} on the canonical encoding.
	 *
	 * @param array<string, mixed> $document Decoded document.
	 *
	 * @throws ValidationException When the canonical encoding exceeds the limit.
	 */
	public static function assertDocumentSize(array $document): void {
		if (self::documentSizeBytes($document) > self::MAX_DOCUMENT_BYTES) {
			throw new ValidationException('import_document_too_large');
		}
	}

	/**
	 * Import a document as a new DRAFT template.
	 *
	 * @param array<string, mixed> $document Decoded JSON document.
	 * @return Template The newly created template.
	 *
	 * @throws ValidationException When the document is malformed, unsupported or oversized.
	 * @throws ForbiddenException When the current user may not create templates.
	 */
	public function import(array $document): Template {
		self::assertCollectionLimits($document);
		self::assertDocumentSize($document);

		$parsed = $this->parseDocument($document);

		return $this->transactionRunner->run(function () use ($parsed, $document): Template {
			$template = $this->createTemplate($document);
			$templateId = $template->getId();

			$sectionIds = [];
			foreach ($parsed['sections'] as $section) {
				$created = $this->templates->createSection($templateId, [
					'title' => $section['title'],
					'description' => $section['description'],
					'notes' => $section['notes'],
				]);
				$sectionIds[$section['ref']] = $created->getId();
			}

			$stepIds = [];
			foreach ($parsed['sections'] as $section) {
				foreach ($section['steps'] as $step) {
					$created = $this->templates->createStep($sectionIds[$section['ref']], [
						'title' => $step['title'],
						'description' => $step['description'],
						'type' => $step['type'],
						'required' => $step['required'],
						'config' => $step['config'],
						'defaultAssignee' => $step['defaultAssignee'],
						'dueOffset' => $step['dueOffset'],
					]);
					$stepIds[$step['ref']] = $created->getId();
				}
			}

			$this->applyFlowRules($parsed['sections'], $sectionIds, $stepIds);

			return $this->templates->getTemplate($templateId);
		});
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function createTemplate(array $document): Template {
		$metadata = $document['template'];
		$data = ['title' => $metadata['title']];
		if (array_key_exists('description', $metadata)) {
			$data['description'] = $metadata['description'];
		}

		return $this->templates->createTemplate($data);
	}

	/**
	 * Apply dependency edges and conditions in a second pass, mapping the
	 * document-local refs onto the newly generated database identifiers.
	 *
	 * @param list<ImportSection> $sections
	 * @param array<string, int> $sectionIds
	 * @param array<string, int> $stepIds
	 */
	private function applyFlowRules(array $sections, array $sectionIds, array $stepIds): void {
		foreach ($sections as $section) {
			if ($section['dependsOn'] === [] && $section['conditions'] === []) {
				continue;
			}

			$dependsOn = array_map(
				static fn (string $ref): int => $sectionIds[$ref],
				$section['dependsOn'],
			);

			$conditions = array_map(
				static function (array $condition) use ($stepIds): array {
					$mapped = ['stepId' => $stepIds[$condition['stepRef']], 'operator' => $condition['operator']];
					if (array_key_exists('value', $condition)) {
						$mapped['value'] = $condition['value'];
					}

					return $mapped;
				},
				$section['conditions'],
			);

			$this->templates->updateSection($sectionIds[$section['ref']], [
				'dependsOn' => $dependsOn,
				'conditions' => $conditions,
			]);
		}
	}

	/**
	 * Validate the document structure, field types, references and bounds, and
	 * return normalised sections with their steps ordered by exported position.
	 *
	 * @param array<string, mixed> $document
	 * @return array{sections: list<ImportSection>}
	 */
	private function parseDocument(array $document): array {
		$this->assertFormat($document);

		$template = $document['template'] ?? null;
		if (!is_array($template) || !array_key_exists('title', $template) || !is_string($template['title'])) {
			throw new ValidationException('invalid_import_document');
		}
		if (array_key_exists('description', $template) && !is_string($template['description'])) {
			throw new ValidationException('invalid_import_document');
		}

		if (!isset($document['sections']) || !is_array($document['sections']) || !array_is_list($document['sections'])) {
			throw new ValidationException('invalid_import_document');
		}
		if (!isset($document['steps']) || !is_array($document['steps']) || !array_is_list($document['steps'])) {
			throw new ValidationException('invalid_import_document');
		}
		if (count($document['sections']) > self::MAX_SECTIONS) {
			throw new ValidationException('import_too_many_sections');
		}
		if (count($document['steps']) > self::MAX_STEPS) {
			throw new ValidationException('import_too_many_steps');
		}

		$sections = $this->normalizeSections($document['sections']);
		$steps = $this->normalizeSteps($document['steps']);

		$this->assertReferences($sections, $steps);
		$sections = $this->attachSteps($sections, $this->sortSteps($steps));
		$this->assertAssignees($steps);

		return ['sections' => $sections];
	}

	/**
	 * @param array<string, mixed> $document
	 */
	private function assertFormat(array $document): void {
		if (!array_key_exists('format', $document) || $document['format'] !== self::FORMAT) {
			throw new ValidationException('invalid_import_format');
		}
		if (!array_key_exists('schemaVersion', $document) || !is_int($document['schemaVersion'])) {
			throw new ValidationException('invalid_import_document');
		}
		if ($document['schemaVersion'] !== self::SCHEMA_VERSION) {
			throw new ValidationException('unsupported_import_schema_version');
		}
	}

	/**
	 * @param list<mixed> $rawSections
	 * @return list<ImportSection>
	 */
	private function normalizeSections(array $rawSections): array {
		$sections = [];
		$refs = [];
		foreach ($rawSections as $raw) {
			if (!is_array($raw)) {
				throw new ValidationException('invalid_import_document');
			}

			$ref = $this->requireString($raw, 'ref');
			if (preg_match('/^s[0-9]+$/', $ref) !== 1) {
				throw new ValidationException('invalid_import_reference');
			}
			if (isset($refs[$ref])) {
				throw new ValidationException('duplicate_import_reference');
			}
			$refs[$ref] = true;

			$sections[] = [
				'ref' => $ref,
				'title' => $this->requireString($raw, 'title'),
				'description' => $this->optionalString($raw, 'description'),
				'notes' => $this->optionalString($raw, 'notes'),
				'dependsOn' => $this->stringList($raw, 'dependsOn'),
				'conditions' => $this->normalizeConditions($raw),
				'steps' => [],
			];
		}

		return $sections;
	}

	/**
	 * @param list<mixed> $rawSteps
	 * @return list<array{ref: string, title: string, description: string, type: string, required: bool, position: int, order: int, sectionRef: string, config: array<string, mixed>, defaultAssignee: string|null, dueOffset: string|null}>
	 */
	private function normalizeSteps(array $rawSteps): array {
		$steps = [];
		$refs = [];
		$order = 0;
		foreach ($rawSteps as $raw) {
			if (!is_array($raw)) {
				throw new ValidationException('invalid_import_document');
			}

			$ref = $this->requireString($raw, 'ref');
			if (preg_match('/^t[0-9]+$/', $ref) !== 1) {
				throw new ValidationException('invalid_import_reference');
			}
			if (isset($refs[$ref])) {
				throw new ValidationException('duplicate_import_reference');
			}
			$refs[$ref] = true;

			$position = $raw['position'] ?? null;
			if ($position !== null && !is_int($position)) {
				throw new ValidationException('invalid_import_document');
			}

			$required = $raw['required'] ?? null;
			if ($required !== null && !is_bool($required)) {
				throw new ValidationException('invalid_import_document');
			}

			$config = $raw['config'] ?? null;
			if ($config !== null && !is_array($config) && !($config instanceof \stdClass)) {
				throw new ValidationException('invalid_import_document');
			}
			// The export endpoint serialises an empty configuration as a JSON
			// object, so a decoded document may carry a stdClass here; accept
			// both shapes and normalise to an associative array.
			$config = $config === null ? [] : (array)$config;

			$assignee = $raw['defaultAssignee'] ?? null;
			if ($assignee !== null && !is_string($assignee)) {
				throw new ValidationException('invalid_import_document');
			}

			$dueOffset = $raw['dueOffset'] ?? null;
			if ($dueOffset !== null && !is_string($dueOffset)) {
				throw new ValidationException('invalid_import_document');
			}

			$steps[] = [
				'ref' => $ref,
				'title' => $this->requireString($raw, 'title'),
				'description' => $this->optionalString($raw, 'description'),
				'type' => $this->requireString($raw, 'type'),
				'required' => $required ?? false,
				'position' => $position ?? $order,
				'order' => $order,
				'sectionRef' => $this->requireString($raw, 'sectionRef'),
				'config' => $config,
				'defaultAssignee' => $assignee,
				'dueOffset' => $dueOffset,
			];
			$order++;
		}

		return $steps;
	}

	/**
	 * Reject dependency and condition references to unknown refs and enforce the
	 * collection bounds that are checked after normalisation.
	 *
	 * @param list<ImportSection> $sections
	 * @param list<array{ref: string, sectionRef: string}> $steps
	 */
	private function assertReferences(array $sections, array $steps): void {
		$sectionRefs = [];
		foreach ($sections as $section) {
			$sectionRefs[$section['ref']] = true;
		}
		$stepRefs = [];
		foreach ($steps as $step) {
			$stepRefs[$step['ref']] = true;
		}

		$dependencies = 0;
		$conditions = 0;
		foreach ($sections as $section) {
			foreach ($section['dependsOn'] as $ref) {
				if (!isset($sectionRefs[$ref])) {
					throw new ValidationException('invalid_import_reference');
				}
				$dependencies++;
			}
			foreach ($section['conditions'] as $condition) {
				if (!isset($stepRefs[$condition['stepRef']])) {
					throw new ValidationException('invalid_import_reference');
				}
				$conditions++;
			}
		}

		if ($dependencies > self::MAX_DEPENDENCIES) {
			throw new ValidationException('import_too_many_dependencies');
		}
		if ($conditions > self::MAX_CONDITIONS) {
			throw new ValidationException('import_too_many_conditions');
		}
	}

	/**
	 * Attach ordered steps to their sections, rejecting unknown section refs.
	 *
	 * @param list<ImportSection> $sections
	 * @param list<array{ref: string, title: string, description: string, type: string, required: bool, position: int, order: int, sectionRef: string, config: array<string, mixed>, defaultAssignee: string|null, dueOffset: string|null}> $steps
	 * @return list<ImportSection>
	 */
	private function attachSteps(array $sections, array $steps): array {
		$indexByRef = [];
		$buckets = [];
		foreach ($sections as $index => $section) {
			$indexByRef[$section['ref']] = $index;
			$buckets[$index] = [];
		}

		foreach ($steps as $step) {
			$index = $indexByRef[$step['sectionRef']] ?? null;
			if ($index === null) {
				throw new ValidationException('invalid_import_reference');
			}
			$buckets[$index][] = $step;
		}

		foreach ($sections as $index => $section) {
			$sections[$index]['steps'] = $buckets[$index];
		}

		return $sections;
	}

	/**
	 * Sort steps by exported position with document order as stable tie-breaker.
	 *
	 * @param list<array{ref: string, title: string, description: string, type: string, required: bool, position: int, order: int, sectionRef: string, config: array<string, mixed>, defaultAssignee: string|null, dueOffset: string|null}> $steps
	 * @return list<array{ref: string, title: string, description: string, type: string, required: bool, position: int, order: int, sectionRef: string, config: array<string, mixed>, defaultAssignee: string|null, dueOffset: string|null}>
	 */
	private function sortSteps(array $steps): array {
		usort($steps, static function (array $left, array $right): int {
			if ($left['position'] !== $right['position']) {
				return $left['position'] <=> $right['position'];
			}

			return $left['order'] <=> $right['order'];
		});

		return $steps;
	}

	/**
	 * Verify that every non-null default assignee resolves to a local principal.
	 *
	 * @param list<array{defaultAssignee: string|null}> $steps
	 */
	private function assertAssignees(array $steps): void {
		foreach ($steps as $step) {
			$value = $step['defaultAssignee'];
			if ($value === null) {
				continue;
			}

			$parsed = $this->principalValidator->parsePrincipalString($value);
			if ($parsed === null || !$this->principalValidator->exists($parsed['type'], $parsed['id'])) {
				throw new ValidationException('invalid_import_assignee');
			}
		}
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return list<ImportCondition>
	 */
	private function normalizeConditions(array $raw): array {
		$conditions = [];
		if (!array_key_exists('conditions', $raw)) {
			return $conditions;
		}
		if (!is_array($raw['conditions']) || !array_is_list($raw['conditions'])) {
			throw new ValidationException('invalid_import_document');
		}

		foreach ($raw['conditions'] as $condition) {
			if (!is_array($condition)) {
				throw new ValidationException('invalid_import_document');
			}
			$stepRef = $this->requireString($condition, 'stepRef');
			if (preg_match('/^t[0-9]+$/', $stepRef) !== 1) {
				throw new ValidationException('invalid_import_reference');
			}
			$normalized = [
				'stepRef' => $stepRef,
				'operator' => $this->requireString($condition, 'operator'),
			];
			if (array_key_exists('value', $condition)) {
				$normalized['value'] = $condition['value'];
			}
			$conditions[] = $normalized;
		}

		return $conditions;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function requireString(array $data, string $key): string {
		if (!array_key_exists($key, $data) || !is_string($data[$key])) {
			throw new ValidationException('invalid_import_document');
		}

		return $data[$key];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function optionalString(array $data, string $key): string {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return '';
		}
		if (!is_string($data[$key])) {
			throw new ValidationException('invalid_import_document');
		}

		return $data[$key];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return list<string>
	 */
	private function stringList(array $data, string $key): array {
		if (!array_key_exists($key, $data) || $data[$key] === null) {
			return [];
		}
		if (!is_array($data[$key]) || !array_is_list($data[$key])) {
			throw new ValidationException('invalid_import_document');
		}

		$list = [];
		foreach ($data[$key] as $value) {
			if (!is_string($value)) {
				throw new ValidationException('invalid_import_document');
			}
			$list[] = $value;
		}

		return $list;
	}
}
