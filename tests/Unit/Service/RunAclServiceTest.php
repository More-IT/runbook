<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;

class RunAclServiceTest extends RunTestBase {
	public function testGetAclReturnsOwnerAndEntries(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		$result = $this->runAclServiceFor('alice')->getAcl($run->getId());

		self::assertSame('alice', $result['owner']);
		self::assertCount(1, $result['entries']);
	}

	public function testReplaceStoresNormalizedEntries(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$run = $this->addRun('alice');

		$result = $this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => 'USER', 'principalId' => ' bob ', 'role' => 'PARTICIPANT'],
			['principalType' => 'GROUP', 'principalId' => 'engineering', 'role' => 'VIEWER'],
		]);

		self::assertCount(2, $result['entries']);
		self::assertSame('bob', $result['entries'][0]->getPrincipalId());
		self::assertSame(RunAclRole::Participant->value, $result['entries'][0]->getRole());
	}

	public function testReplaceWithEmptyClearsAcl(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		$result = $this->runAclServiceFor('alice')->replaceAcl($run->getId(), []);

		self::assertSame([], $result['entries']);
	}

	public function testParticipantCannotReadAcl(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		$this->expectException(ForbiddenException::class);
		$this->runAclServiceFor('bob')->getAcl($run->getId());
	}

	public function testOutsiderCannotReadAcl(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->expectException(NotFoundException::class);
		$this->runAclServiceFor('bob')->getAcl($run->getId());
	}

	public function testReplaceRejectsUnknownUser(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => 'USER', 'principalId' => 'ghost', 'role' => 'PARTICIPANT'],
		]);
	}

	public function testReplaceRejectsUnknownGroup(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => 'GROUP', 'principalId' => 'ghosts', 'role' => 'VIEWER'],
		]);
	}

	public function testReplaceRejectsDuplicates(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'PARTICIPANT'],
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'VIEWER'],
		]);
	}

	public function testReplaceRejectsOwnerRole(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'OWNER'],
		]);
	}

	public function testReplaceRejectedOnCompletedRun(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice', RunStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
			['principalType' => 'USER', 'principalId' => 'bob', 'role' => 'PARTICIPANT'],
		]);
	}

	public function testValidationFailureLeavesAclUntouched(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$existing = $this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		try {
			$this->runAclServiceFor('alice')->replaceAcl($run->getId(), [
				['principalType' => 'USER', 'principalId' => 'ghost', 'role' => 'VIEWER'],
			]);
			self::fail('Expected a validation exception');
		} catch (ValidationException) {
			// expected
		}

		self::assertCount(1, $this->runAclEntries);
		self::assertArrayHasKey($existing->getId(), $this->runAclEntries);
	}
}
