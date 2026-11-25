<?php
// Run by jit-check.sh inside an installed Nextcloud.
define('OC_CONSOLE', 1);
require '/tmp/nc/lib/base.php';
\OC_App::loadApps();
$l = \OCP\Server::get(\OCA\AbsCompat\Service\LibraryService::class);
$c = \OCP\Server::get(\OCA\AbsCompat\Service\CoverService::class);
$cfg = \OCP\Server::get(\OCP\Config\IUserConfig::class);
if (($argv[1] ?? '') === 'init') { // "other" has own settings, "admin" has none
	$l->setFolderPaths('other', ['Mine']);
	$c->setAuto('other', true);
	$cfg->setValueInt('other', 'abscompat', 'last_scan', 1234, true);
	exit;
}
$bad = $n = 0;
$msg = '';
$expect = static function (string $what, callable $f, mixed $want) use (&$bad, &$n, &$msg): void {
	$n++;
	try {
		$got = $f();
		if ($got === $want) {
			return;
		}
		$m = $what . ' = ' . json_encode($got);
	} catch (\TypeError $e) {
		$m = $what . ': ' . $e->getMessage();
	}
	$bad++;
	$msg = $msg ?: $m;
};
$default = $l->getDefaultFolderPaths();
$mine = ['Mine'];
for ($i = 0; $i < 600; $i++) {
	$expect('folders', static fn () => $l->getFolderPaths('admin'), $default);
	$expect('default', static fn () => $l->usesDefaultFolders('admin'), true);
	$expect('own', static fn () => $l->usesDefaultFolders('other'), false);
	$expect('ownfolders', static fn () => array_map(static fn ($p) => trim($p, '/'), $l->getFolderPaths('other')), $mine);
	$expect('lastscan', static fn () => $l->getLastScan('admin'), 0);
	$expect('ownscan', static fn () => $l->getLastScan('other'), 1234);
	$expect('auto', static fn () => $c->isAuto('admin'), false);
	$expect('autoon', static fn () => $c->isAuto('other'), true);
}
$s = opcache_get_status(false)['jit'] ?? [];
echo PHP_VERSION, ' jit=', var_export($s['on'] ?? null, true), " wrong=$bad of $n $msg\n";
