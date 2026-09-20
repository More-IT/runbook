<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;

class RunAccessServiceTest extends RunTestBase {
	public function testOwnerHasFullAccess(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		self::assertSame(RunAclRole::Owner, $this->runAccess->getEffectiveRole($run, 'alice'));
		self::assertTrue($this->runAccess->canView($run, 'alice'));
		self::assertTrue($this->runAccess->canManage($run, 'alice'));
	}

	public function testDirectUserParticipant(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Participant->value);

		self::assertSame(RunAclRole::Participant, $this->runAccess->getEffectiveRole($run, 'bob'));
		self::assertTrue($this->runAccess->canView($run, 'bob'));
		self::assertFalse($this->runAccess->canManage($run, 'bob'));
	}

	public function testDirectUserViewer(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);

		self::assertSame(RunAclRole::Viewer, $this->runAccess->getEffectiveRole($run, 'bob'));
		self::assertTrue($this->runAccess->canView($run, 'bob'));
	}

	public function testGroupParticipant(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::Group->value, 'engineering', RunAclRole::Participant->value);

		self::assertSame(RunAclRole::Participant, $this->runAccess->getEffectiveRole($run, 'bob'));
	}

	public function testGroupAccessDoesNotApplyToNonMembers(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::Group->value, 'engineering', RunAclRole::Participant->value);

		self::assertNull($this->runAccess->getEffectiveRole($run, 'bob'));
	}

	public function testStrongestRoleWins(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);
		$this->seedRunAcl($run->getId(), PrincipalType::Group->value, 'engineering', RunAclRole::Participant->value);

		self::assertSame(RunAclRole::Participant, $this->runAccess->getEffectiveRole($run, 'bob'));
	}

	public function testStepAssignmentGrantsViewAccess(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$this->addRunStep($section->getId(), 'TEXT', true, 'PENDING', 0, [], PrincipalType::User->value, 'bob');

		self::assertTrue($this->runAccess->canView($run, 'bob'));
		self::assertNull($this->runAccess->getEffectiveRole($run, 'bob'));
	}

	public function testStepAssignmentGrantsExecuteForThatStepOnly(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$assigned = $this->addRunStep($section->getId(), 'TEXT', false, 'PENDING', 0, [], PrincipalType::User->value, 'bob');
		$other = $this->addRunStep($section->getId(), 'TEXT', false, 'PENDING', 1);

		self::assertTrue($this->runAccess->canExecuteStep($run, $assigned, 'bob'));
		self::assertFalse($this->runAccess->canExecuteStep($run, $other, 'bob'));
	}

	public function testGroupStepAssignmentCanExecute(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$this->addGroup('engineering');
		$this->joinGroup('bob', 'engineering');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', false, 'PENDING', 0, [], PrincipalType::Group->value, 'engineering');

		self::assertTrue($this->runAccess->canExecuteStep($run, $step, 'bob'));
	}

	public function testOwnerCanExecuteEveryStep(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'TEXT', false, 'PENDING', 0);

		self::assertTrue($this->runAccess->canExecuteStep($run, $step, 'alice'));
	}

	public function testExecutableStepIdsForParticipant(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$assigned = $this->addRunStep($section->getId(), 'TEXT', false, 'PENDING', 0, [], PrincipalType::User->value, 'bob');
		$this->addRunStep($section->getId(), 'TEXT', false, 'PENDING', 1);

		self::assertSame([$assigned->getId()], $this->runAccess->executableStepIds($run, array_values($this->runSteps), 'bob'));
	}
}
