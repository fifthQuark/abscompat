<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Listener;

use OCA\AbsCompat\Service\BookmarkService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\TokenService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private TokenService $tokens,
		private LibraryService $library,
		private BookmarkService $bookmarks,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}
		$uid = $event->getUser()->getUID();
		$this->tokens->deleteAllForUser($uid);
		$this->library->deleteAllForUser($uid);
		$this->bookmarks->deleteAllForUser($uid);
	}
}
