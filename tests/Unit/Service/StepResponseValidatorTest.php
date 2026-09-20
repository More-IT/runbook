<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\StepType;
use OCA\Runbook\Service\StepResponseValidator;
use OCA\Runbook\Service\ValidationException;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class StepResponseValidatorTest extends TestCase {
	private StepResponseValidator $validator;

	protected function setUp(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('userExists')->willReturnCallback(
			static fn (string $uid, array $excludeBackends = []): bool => $uid === 'bob',
		);

		$this->validator = new StepResponseValidator($userManager);
	}

	public function testCheckAcceptsBoolean(): void {
		self::assertTrue($this->validator->validate(StepType::Check, true, []));
		self::assertFalse($this->validator->validate(StepType::Check, false, []));
	}

	public function testCheckRejectsNonBoolean(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::Check, 'yes', []);
	}

	public function testConfirmationRejectsNonBoolean(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::Confirmation, 1, []);
	}

	public function testTextAcceptsString(): void {
		self::assertSame('hello', $this->validator->validate(StepType::Text, 'hello', []));
	}

	public function testTextRejectsNonString(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::Text, ['hello'], []);
	}

	public function testNumberAcceptsNumericValues(): void {
		self::assertSame(42, $this->validator->validate(StepType::Number, 42, []));
		self::assertSame(1.5, $this->validator->validate(StepType::Number, 1.5, []));
		self::assertSame(7, $this->validator->validate(StepType::Number, '7', []));
	}

	public function testNumberRejectsNonNumericValue(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::Number, 'abc', []);
	}

	public function testSelectAcceptsConfiguredOption(): void {
		$config = ['options' => ['A', 'B']];
		self::assertSame('B', $this->validator->validate(StepType::Select, 'B', $config));
	}

	public function testSelectRejectsUnknownOption(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::Select, 'C', ['options' => ['A', 'B']]);
	}

	public function testDateAcceptsIsoDate(): void {
		self::assertSame('2026-01-31', $this->validator->validate(StepType::Date, '2026-01-31', []));
	}

	public function testDateRejectsInvalidIsoDate(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::Date, '2026-02-31', []);
	}

	public function testUserAcceptsExistingUid(): void {
		self::assertSame('bob', $this->validator->validate(StepType::User, 'bob', []));
	}

	public function testUserRejectsUnknownUid(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::User, 'ghost', []);
	}

	public function testFileAlwaysReportsUnsupportedUpload(): void {
		$this->expectException(ValidationException::class);
		$this->validator->validate(StepType::File, 'file-id', []);
	}
}
