<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;

/**
 * Playing without downloading first. Off until the user turns it on: every listen goes through the
 * server, and some hosting providers limit traffic or long-running requests. An admin can forbid it.
 */
class StreamingService {
	public function __construct(
		private IUserConfig $userConfig,
		private IAppConfig $appConfig,
	) {
	}

	public function isAllowed(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'allow_streaming', true);
	}

	public function allow(bool $on): void {
		$this->appConfig->setValueBool(Application::APP_ID, 'allow_streaming', $on);
	}

	public function wanted(string $userId): bool {
		return UserValue::bool($this->userConfig, $userId, 'streaming');
	}

	public function set(string $userId, bool $on): void {
		$this->userConfig->setValueBool($userId, Application::APP_ID, 'streaming', $on);
	}

	public function isOn(string $userId): bool {
		return $this->isAllowed() && $this->wanted($userId);
	}
}
