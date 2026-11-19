<?php

declare(strict_types=1);

// Unit tests run without a Nextcloud server: OCP interfaces come from the nextcloud/ocp package,
// which ships no autoloader of its own.
require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
	foreach (['OCP\\' => 'OCP/', 'NCU\\' => 'NCU/'] as $prefix => $dir) {
		if (str_starts_with($class, $prefix)) {
			$file = __DIR__ . '/../vendor/nextcloud/ocp/' . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
});
