<?php

declare(strict_types=1);

namespace OCA\Runbook\Notification;

use OCA\Runbook\Service\NotificationService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders Runbook notifications as translated, client friendly subjects.
 *
 * The stored subject is a stable machine identifier; all user facing text is
 * produced here using the recipient's language. No comment bodies, storage keys
 * or other private data are ever part of a notification payload.
 */
class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $url,
		private readonly IUserManager $userManager,
	) {
	}

	public function getID(): string {
		return 'runbook';
	}

	public function getName(): string {
		return $this->l10nFactory->get('runbook')->t('Runbook');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'runbook') {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get('runbook', $languageCode);
		$params = $notification->getSubjectParameters();
		$runId = $this->intParam($params, 'runId');
		$stepId = $this->intParam($params, 'stepId');
		$runTitle = $this->stringParam($params, 'runTitle');
		$stepTitle = $this->stringParam($params, 'stepTitle');
		$actor = $this->stringParam($params, 'actor');

		switch ($notification->getSubject()) {
			case NotificationService::SUBJECT_STEP_ASSIGNED:
				$parsed = $l->t('Step "{step}" in run "{run}" is assigned to you.', [
					'step' => $stepTitle,
					'run' => $runTitle,
				]);
				$richTemplate = $l->t('Step "{step}" in run "{run}" is assigned to you.');
				$richParams = [
					'step' => $this->highlight($stepTitle, $stepId),
					'run' => $this->highlight($runTitle, $runId),
				];
				break;
			case NotificationService::SUBJECT_RUN_ASSIGNED:
				$parsed = $l->t('Run "{run}" was shared with you.', ['run' => $runTitle]);
				$richTemplate = $l->t('Run "{run}" was shared with you.');
				$richParams = ['run' => $this->highlight($runTitle, $runId)];
				break;
			case NotificationService::SUBJECT_MENTIONED:
				if ($stepTitle !== '') {
					$parsed = $l->t('{actor} mentioned you in a comment on step "{step}" in run "{run}".', [
						'actor' => $actor,
						'step' => $stepTitle,
						'run' => $runTitle,
					]);
					$richTemplate = $l->t('{actor} mentioned you in a comment on step "{step}" in run "{run}".');
					$richParams = [
						'actor' => $this->user($actor),
						'step' => $this->highlight($stepTitle, $stepId),
						'run' => $this->highlight($runTitle, $runId),
					];
				} else {
					$parsed = $l->t('{actor} mentioned you in a comment in run "{run}".', [
						'actor' => $actor,
						'run' => $runTitle,
					]);
					$richTemplate = $l->t('{actor} mentioned you in a comment in run "{run}".');
					$richParams = [
						'actor' => $this->user($actor),
						'run' => $this->highlight($runTitle, $runId),
					];
				}
				break;
			case NotificationService::SUBJECT_STEP_DUE:
				$parsed = $l->t('Step "{step}" in run "{run}" is due tomorrow.', [
					'step' => $stepTitle,
					'run' => $runTitle,
				]);
				$richTemplate = $l->t('Step "{step}" in run "{run}" is due tomorrow.');
				$richParams = [
					'step' => $this->highlight($stepTitle, $stepId),
					'run' => $this->highlight($runTitle, $runId),
				];
				break;
			case NotificationService::SUBJECT_STEP_OVERDUE:
				$parsed = $l->t('Step "{step}" in run "{run}" is overdue.', [
					'step' => $stepTitle,
					'run' => $runTitle,
				]);
				$richTemplate = $l->t('Step "{step}" in run "{run}" is overdue.');
				$richParams = [
					'step' => $this->highlight($stepTitle, $stepId),
					'run' => $this->highlight($runTitle, $runId),
				];
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setParsedSubject($parsed);
		$notification->setRichSubject($richTemplate, $richParams);
		$notification->setLink($this->link($runId, $stepId));

		return $notification;
	}

	private function link(int $runId, int $stepId): string {
		// Nextcloud 33 requires absolute notification links.
		$link = $this->url->linkToRouteAbsolute('runbook.page.index') . '#/run/' . $runId;
		if ($stepId > 0) {
			$link .= '/step/' . $stepId;
		}

		return $link;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function intParam(array $params, string $key): int {
		$value = $params[$key] ?? null;

		return is_int($value) ? $value : 0;
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function stringParam(array $params, string $key): string {
		$value = $params[$key] ?? '';

		return is_string($value) ? $value : '';
	}

	/**
	 * @return array{type: string, id: string, name: string}
	 */
	private function highlight(string $name, int $id): array {
		return ['type' => 'highlight', 'id' => (string)$id, 'name' => $name];
	}

	/**
	 * @return array{type: string, id: string, name: string}
	 */
	private function user(string $uid): array {
		if ($uid === '') {
			return ['type' => 'highlight', 'id' => '', 'name' => ''];
		}

		$displayName = $this->userManager->getDisplayName($uid);

		return ['type' => 'user', 'id' => $uid, 'name' => $displayName ?? $uid];
	}
}
