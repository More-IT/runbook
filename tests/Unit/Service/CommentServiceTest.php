<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

use OCA\Runbook\Db\ActivityEvent;
use OCA\Runbook\Enum\ActivityType;
use OCA\Runbook\Enum\PrincipalType;
use OCA\Runbook\Enum\RunAclRole;
use OCA\Runbook\Enum\RunStatus;
use OCA\Runbook\Enum\RunStepStatus;
use OCA\Runbook\Service\AdminSettings;
use OCA\Runbook\Service\ConflictException;
use OCA\Runbook\Service\ForbiddenException;
use OCA\Runbook\Service\NotFoundException;
use OCA\Runbook\Service\ValidationException;

class CommentServiceTest extends RunTestBase {
	/**
	 * @return list<ActivityEvent>
	 */
	private function activitiesFor(int $runId): array {
		return array_values(array_filter(
			$this->activityEvents,
			static fn (ActivityEvent $event): bool => $event->getRunId() === $runId,
		));
	}

	public function testOwnerCanCreateRunComment(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$item = $this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'Looks good']);

		self::assertSame('Looks good', $item['comment']->getBody());
		self::assertSame('alice', $item['comment']->getAuthorUid());
		self::assertNull($item['comment']->getStepId());
		self::assertSame('alice', $item['authorDisplayName']);
		self::assertCount(1, $this->activitiesFor($run->getId()));
		self::assertSame(ActivityType::CommentAdded->value, $this->activitiesFor($run->getId())[0]->getEventType());
	}

	public function testViewerCanReadButNotCreate(): void {
		$this->addUser('alice');
		$this->addUser('bob');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'bob', RunAclRole::Viewer->value);

		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'hi']);
		self::assertCount(1, $this->commentServiceFor('bob')->listComments($run->getId()));

		$this->expectException(ForbiddenException::class);
		$this->commentServiceFor('bob')->createComment($run->getId(), ['body' => 'nope']);
	}

	public function testParticipantCanCreate(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Participant->value);

		$item = $this->commentServiceFor('carol')->createComment($run->getId(), ['body' => 'on it']);

		self::assertSame('carol', $item['comment']->getAuthorUid());
	}

	public function testStepAssigneeCanCreate(): void {
		$this->addUser('alice');
		$this->addUser('dave');
		$run = $this->addRun('alice');
		$section = $this->addRunSection($run->getId(), 0);
		$step = $this->addRunStep($section->getId(), 'CHECK', true, RunStepStatus::Pending->value, 0, [], PrincipalType::User->value, 'dave');

		$item = $this->commentServiceFor('dave')->createComment($run->getId(), [
			'body' => 'done',
			'stepId' => $step->getId(),
		]);

		self::assertSame($step->getId(), $item['comment']->getStepId());
	}

	public function testUnrelatedUserCannotReadOrCreate(): void {
		$this->addUser('alice');
		$this->addUser('stranger');
		$run = $this->addRun('alice');

		$this->expectException(NotFoundException::class);
		$this->commentServiceFor('stranger')->createComment($run->getId(), ['body' => 'x']);
	}

	public function testCommentOnStepFromAnotherRunIsRejected(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$otherRun = $this->addRun('alice');
		$otherSection = $this->addRunSection($otherRun->getId(), 0);
		$otherStep = $this->addRunStep($otherSection->getId(), 'CHECK', true);

		$this->expectException(NotFoundException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), [
			'body' => 'x',
			'stepId' => $otherStep->getId(),
		]);
	}

	public function testBodyIsRequiredAndTrimmed(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => '   ']);
	}

	public function testBodyRejectsHtmlAndOverlongContent(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => '<b>hi</b>']);
	}

	public function testBodyRejectsOverlongContent(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');

		$this->expectException(ValidationException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => str_repeat('a', 10001)]);
	}

	public function testMentionsAreStoredAndValidated(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		$run = $this->addRun('alice');

		$item = $this->commentServiceFor('alice')->createComment($run->getId(), [
			'body' => 'cc @carol and @ghost',
		]);

		self::assertCount(1, $item['mentions']);
		self::assertSame('carol', $item['mentions'][0]['uid']);
		self::assertSame('carol', $item['mentions'][0]['displayName']);
		self::assertSame(['alice'], array_map(
			static fn (ActivityEvent $event): string => $event->getActorUid(),
			$this->activitiesFor($run->getId()),
		));
	}

	public function testOnlyAuthorCanEditComment(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Participant->value);
		$commentId = $this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'first'])['comment']->getId();

		$updated = $this->commentServiceFor('alice')->updateComment($commentId, ['body' => 'second']);
		self::assertSame('second', $updated['comment']->getBody());
		self::assertSame(ActivityType::CommentEdited->value, $this->activitiesFor($run->getId())[1]->getEventType());

		$this->expectException(ForbiddenException::class);
		$this->commentServiceFor('carol')->updateComment($commentId, ['body' => 'hijack']);
	}

	public function testParticipantCannotDeleteOthersComment(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Participant->value);
		$commentId = $this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'note'])['comment']->getId();

		$this->expectException(ForbiddenException::class);
		$this->commentServiceFor('carol')->deleteComment($commentId);
	}

	public function testOwnerCanDeleteParticipantsComment(): void {
		$this->addUser('alice');
		$this->addUser('carol');
		$run = $this->addRun('alice');
		$this->seedRunAcl($run->getId(), PrincipalType::User->value, 'carol', RunAclRole::Participant->value);
		$commentId = $this->commentServiceFor('carol')->createComment($run->getId(), ['body' => 'note'])['comment']->getId();

		$this->commentServiceFor('alice')->deleteComment($commentId);

		self::assertArrayNotHasKey($commentId, $this->comments);
	}

	public function testOwnerDeletesCommentAndRecordsActivity(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$commentId = $this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'note'])['comment']->getId();

		$this->commentServiceFor('alice')->deleteComment($commentId);

		self::assertSame([], $this->comments);
		self::assertSame(ActivityType::CommentDeleted->value, $this->activitiesFor($run->getId())[1]->getEventType());
	}

	public function testCommentsDisabledRejectsCreate(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$this->setAppConfig(AdminSettings::KEY_COMMENTS_ENABLED, false);

		$this->expectException(ForbiddenException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'hello']);
	}

	public function testCommentsDisabledRejectsUpdate(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$commentId = $this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'hello'])['comment']->getId();
		$this->setAppConfig(AdminSettings::KEY_COMMENTS_ENABLED, false);

		$this->expectException(ForbiddenException::class);
		$this->commentServiceFor('alice')->updateComment($commentId, ['body' => 'changed']);
	}

	public function testCommentsDisabledRejectsDelete(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$commentId = $this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'hello'])['comment']->getId();
		$this->setAppConfig(AdminSettings::KEY_COMMENTS_ENABLED, false);

		$this->expectException(ForbiddenException::class);
		$this->commentServiceFor('alice')->deleteComment($commentId);
	}

	public function testCommentsRemainReadableWhenDisabled(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice');
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'hello']);
		$this->setAppConfig(AdminSettings::KEY_COMMENTS_ENABLED, false);

		$comments = $this->commentServiceFor('alice')->listComments($run->getId());

		self::assertCount(1, $comments);
		self::assertSame('hello', $comments[0]['comment']->getBody());
	}

	public function testCannotCommentOnInactiveRun(): void {
		$this->addUser('alice');
		$run = $this->addRun('alice', RunStatus::Completed->value);

		$this->expectException(ConflictException::class);
		$this->commentServiceFor('alice')->createComment($run->getId(), ['body' => 'late']);
	}
}
