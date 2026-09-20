<?php

declare(strict_types=1);

namespace OCA\Runbook\Tests\Unit\Service;

class MentionServiceTest extends RunTestBase {
	public function testParsesValidUniqueMentions(): void {
		$this->addUser('bob');
		$this->addUser('carol');

		$uids = $this->mentionService->parseMentions('Hello @bob, ping @carol and @bob again');

		self::assertSame(['bob', 'carol'], $uids);
	}

	public function testIgnoresUnknownUsers(): void {
		$this->addUser('bob');

		$uids = $this->mentionService->parseMentions('@bob @ghost');

		self::assertSame(['bob'], $uids);
	}

	public function testIgnoresEmailAddresses(): void {
		$this->addUser('example');

		$uids = $this->mentionService->parseMentions('mail me at user@example');

		self::assertSame([], $uids);
	}

	public function testStoresAndReplacesMentions(): void {
		$this->addUser('bob');
		$this->addUser('carol');

		$this->mentionService->replaceMentions(42, ['bob', 'carol']);
		self::assertSame(['bob', 'carol'], $this->mentionService->listForComments([42])[42]);

		$this->mentionService->replaceMentions(42, ['carol']);
		self::assertSame(['carol'], $this->mentionService->listForComments([42])[42]);
	}

	public function testReplaceMentionsSkipsUnknownUsers(): void {
		$this->addUser('bob');

		$this->mentionService->replaceMentions(7, ['bob', 'ghost']);

		self::assertSame(['bob'], $this->mentionService->listForComments([7])[7]);
	}

	public function testDisplayNameFallsBackToUid(): void {
		$this->addUser('bob');

		self::assertSame('bob', $this->mentionService->displayName('bob'));
	}
}
