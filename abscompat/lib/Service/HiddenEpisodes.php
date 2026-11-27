<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Podcast episodes the user took off "Continue Listening". Kept here, not in GPodderSync, with the
 * position they had then: once the position moves (in the apps, rePod or AntennaPod), the episode
 * is back, as ABS brings a book back when it is played again.
 */
class HiddenEpisodes {
	private const KEY = 'hidden_episodes';
	private const MAX = 500;

	public function __construct(
		private IUserConfig $userConfig,
	) {
	}

	/** @return array<string, int> "podcastKey-episodeKey" => position when hidden */
	private function all(string $userId): array {
		$raw = UserValue::string($this->userConfig, $userId, self::KEY);
		$map = $raw === '' ? [] : json_decode($raw, true);
		return is_array($map) ? array_filter($map, 'is_int') : [];
	}

	/** Hidden, and not played since. */
	public function isHidden(string $userId, string $podcastKey, string $episodeKey, ?array $position): bool {
		$at = $this->all($userId)[$podcastKey . '-' . $episodeKey] ?? null;
		return $at !== null && $position !== null && $at === $position['position'];
	}

	public function set(string $userId, string $podcastKey, string $episodeKey, ?array $position, bool $hidden): void {
		$map = $this->all($userId);
		$key = $podcastKey . '-' . $episodeKey;
		if (!$hidden && !isset($map[$key])) {
			return;
		}
		unset($map[$key]);
		if ($hidden && $position !== null) {
			$map[$key] = $position['position'];
		}
		$this->userConfig->setValueString($userId, Application::APP_ID, self::KEY, json_encode(array_slice($map, -self::MAX, null, true), JSON_FORCE_OBJECT));
	}
}
