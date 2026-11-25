<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Service;

use OCA\AbsCompat\AppInfo\Application;
use OCP\Config\IUserConfig;

/**
 * Reads this app's per-user settings without ever asking IUserConfig for a key that is not set.
 *
 * Nextcloud's UserConfig passes $default on by reference as a named argument and then returns it.
 * PHP's JIT (seen on 8.4.27 and 8.5.12) can lose track of that and return a reference, which makes
 * json_decode() throw "must be of type string, string given" and comparisons silently wrong until
 * the web server restarts. dev/jit-check.sh reproduces it.
 */
final class UserValue {
	public static function string(IUserConfig $config, string $userId, string $key): string {
		return $config->hasKey($userId, Application::APP_ID, $key) ? $config->getValueString($userId, Application::APP_ID, $key) : '';
	}

	public static function bool(IUserConfig $config, string $userId, string $key): bool {
		return $config->hasKey($userId, Application::APP_ID, $key) && $config->getValueBool($userId, Application::APP_ID, $key);
	}

	public static function lazyInt(IUserConfig $config, string $userId, string $key): int {
		return $config->hasKey($userId, Application::APP_ID, $key, true) ? $config->getValueInt($userId, Application::APP_ID, $key, 0, true) : 0;
	}
}
