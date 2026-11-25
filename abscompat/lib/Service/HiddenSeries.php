<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\Config\IUserConfig;

/** Series the user does not want under "Continue series". */
class HiddenSeries {
	private const KEY = 'hidden_series';

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	/** @return list<string> series ids */
	public function get(string $userId): array {
		$raw = UserValue::string($this->userConfig, $userId, self::KEY);
		$list = $raw === '' ? [] : json_decode($raw, true);
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}

	public function set(string $userId, string $seriesId, bool $hidden): void {
		$list = array_diff($this->get($userId), [$seriesId]);
		if ($hidden) {
			$list[] = $seriesId;
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, self::KEY, json_encode(array_values(array_slice($list, -500))));
	}
}
