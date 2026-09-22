<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;

/**
 * Guards the flow-engine localization: every English key must exist in
 * Portuguese, the Portuguese values must not silently fall back to English, and
 * the generated .js files loaded by Nextcloud must mirror the JSON sources.
 */
class LocalizationParityTest extends TestCase {
	/**
	 * Keys introduced by the process-flow engine (milestone 0.3.0). They must be
	 * translated, never left as English fallbacks.
	 */
	private const FLOW_KEYS = [
		'Available',
		'Blocked',
		'Not applicable',
		'Resolved',
		'Depends on sections',
		'Select prerequisite sections',
		'Conditions (all must match)',
		'This section has no condition.',
		'Add condition',
		'Remove condition',
		'Controlling step',
		'Condition value',
		'is yes',
		'is no',
		'equals',
		'does not equal',
		'is greater than',
		'is less than',
		'is greater or equal',
		'is less or equal',
		'Reopen section',
		'Return reason',
		'Return for correction',
		'Confirm return',
		'Step returned',
		'Section returned',
		'Sections cannot depend on each other in a cycle.',
		'A selected prerequisite section does not exist.',
		'A section cannot depend on itself.',
		'The condition is invalid.',
		'The condition operator is not valid for this step.',
		'The condition value is not valid for this step.',
		'The controlling step was not found.',
		'Two sections cannot share the same condition.',
		'This section contains conditions that can never both be true.',
		'This section is not available yet.',
		'A return reason is required.',
		'The return reason is too long.',
		'This section has no resolved steps to return.',
		'A section cannot use a condition on one of its own steps.',
		'The condition on “{step}” was not satisfied',
		'Waiting for an answer to “{step}”',
		'Waiting for “{section}” to be completed',
		'You can start this section.',
		'Continue with the steps in this section.',
		'This section is outside the current path.',
		'All steps are resolved. Completed: {completed}, Skipped: {skipped}.',
		'This section contains information but has no steps to complete.',
		'Opens immediately',
		'Opens after {sections}',
		'is complete',
		'are complete',
		'if {condition}',
		'AND',
	];

	/**
	 * Portuguese catalogs. pt_BR intentionally mirrors pt_PT until a dedicated
	 * translation update diverges them.
	 */
	private const PORTUGUESE_CATALOGS = ['pt_PT', 'pt_BR'];

	/**
	 * @return array<string, string>
	 */
	private function translations(string $file): array {
		$path = $this->l10nPath($file);
		self::assertFileExists($path);

		$decoded = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		self::assertIsArray($decoded);
		self::assertArrayHasKey('translations', $decoded);
		self::assertIsArray($decoded['translations']);

		$translations = [];
		foreach ($decoded['translations'] as $key => $value) {
			self::assertIsString($value);
			$translations[(string)$key] = $value;
		}

		return $translations;
	}

	public function testEnglishAndPortugueseKeySetsMatch(): void {
		$english = array_keys($this->translations('en.json'));
		sort($english);

		foreach (self::PORTUGUESE_CATALOGS as $locale) {
			$portuguese = array_keys($this->translations($locale . '.json'));
			sort($portuguese);
			self::assertSame($english, $portuguese, $locale . ' must have the same keys as en.json');
		}
	}

	public function testPortugueseBrazilMirrorsPortugalExactly(): void {
		$portugal = $this->translations('pt_PT.json');
		$brazil = $this->translations('pt_BR.json');

		self::assertSame(array_keys($portugal), array_keys($brazil));

		foreach ($portugal as $key => $value) {
			self::assertSame($value, $brazil[$key], 'pt_BR must currently mirror pt_PT for: ' . $key);
		}
	}

	public function testNoStrayTranslationKeysOutsideTheContainer(): void {
		foreach (array_merge(['en.json'], array_map(static fn (string $locale): string => $locale . '.json', self::PORTUGUESE_CATALOGS)) as $file) {
			$decoded = json_decode((string)file_get_contents($this->l10nPath($file)), true, 512, JSON_THROW_ON_ERROR);
			self::assertIsArray($decoded);
			$rootKeys = array_keys($decoded);
			sort($rootKeys);
			self::assertSame(['pluralForm', 'translations'], $rootKeys, $file . ' has stray root keys');
		}
	}

	public function testCatalogsContainNoHtmlEntities(): void {
		$entities = ['&quot;', '&amp;', '&#39;', '&apos;', '&lt;', '&gt;', '&#x27;', '&#x2F;'];
		foreach (array_merge(['en'], self::PORTUGUESE_CATALOGS) as $locale) {
			foreach ($this->translations($locale . '.json') as $key => $value) {
				foreach ($entities as $entity) {
					self::assertStringNotContainsString(
						$entity,
						$value,
						sprintf('%s.json value for "%s" must not contain the HTML entity %s', $locale, $key, $entity),
					);
				}
			}
		}
	}

	public function testReasonLabelsUseUnicodeQuotes(): void {
		$english = $this->translations('en.json');
		$portuguese = $this->translations('pt_PT.json');

		self::assertSame('The condition on “{step}” was not satisfied', $english['The condition on “{step}” was not satisfied']);
		self::assertSame('A condição sobre “{step}” não foi satisfeita', $portuguese['The condition on “{step}” was not satisfied']);
		self::assertSame('Waiting for “{section}” to be completed', $english['Waiting for “{section}” to be completed']);
		self::assertSame('À espera de que “{section}” seja concluída', $portuguese['Waiting for “{section}” to be completed']);
	}

	public function testCompiledBundleCarriesTheCorrectedReasonLabels(): void {
		$bundlePath = dirname(__DIR__, 3) . '/js/runbook-main.mjs';
		if (!is_file($bundlePath)) {
			self::markTestSkipped('Compiled bundle not present; run "npm run build" first.');
		}

		$bundle = (string)file_get_contents($bundlePath);
		self::assertStringContainsString('The condition on “{step}” was not satisfied', $bundle);
		self::assertStringContainsString('Waiting for an answer to “{step}”', $bundle);
		self::assertStringContainsString('Opens immediately', $bundle);
		self::assertStringNotContainsString('Condition on "{step}" is not met', $bundle);
	}

	public function testFlowEngineKeysAreTranslatedInPortuguese(): void {
		$english = $this->translations('en.json');

		foreach (self::PORTUGUESE_CATALOGS as $locale) {
			$portuguese = $this->translations($locale . '.json');
			foreach (self::FLOW_KEYS as $key) {
				self::assertArrayHasKey($key, $english, 'Missing English flow key: ' . $key);
				self::assertArrayHasKey($key, $portuguese, $locale . ' is missing the flow key: ' . $key);
				self::assertNotSame('', $portuguese[$key], 'Empty ' . $locale . ' value for: ' . $key);
				self::assertNotSame(
					$english[$key],
					$portuguese[$key],
					$locale . ' value must not fall back to English for: ' . $key,
				);
			}
		}
	}

	public function testGeneratedJavaScriptMirrorsJson(): void {
		foreach (array_merge(['en'], self::PORTUGUESE_CATALOGS) as $locale) {
			$translations = $this->translations($locale . '.json');
			$js = (string)file_get_contents($this->l10nPath($locale . '.js'));

			self::assertStringStartsWith('OC.L10N.register(', $js);
			self::assertStringContainsString('"runbook",', $js);

			foreach (array_keys($translations) as $key) {
				$encoded = json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				self::assertIsString($encoded);
				self::assertStringContainsString($encoded, $js, $locale . '.js is missing the key: ' . $key);
			}
		}
	}

	private function l10nPath(string $file): string {
		return dirname(__DIR__, 3) . '/l10n/' . $file;
	}
}
