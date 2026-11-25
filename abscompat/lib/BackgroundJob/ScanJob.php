<?php

declare(strict_types=1);

namespace OCA\AbsCompat\BackgroundJob;

use OCA\AbsCompat\Service\CoverService;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\SessionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Hourly rescan for users who use the app (have a login or API key).
 * Unchanged files are skipped via the per-file cache, so this is cheap.
 */
class ScanJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private IDBConnection $db,
		private LibraryService $library,
		private SessionService $sessions,
		private CoverService $covers,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from('abs_tokens');
		$users = $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN);
		$deadline = microtime(true) + 240;
		foreach ($users as $uid) {
			$left = $deadline - microtime(true);
			if ($left <= 0) {
				break;
			}
			try {
				$this->library->scanUser((string)$uid, $left);
			} catch (\Throwable $e) {
				$this->logger->warning('Library scan failed for ' . $uid . ': ' . $e->getMessage(), ['app' => 'abscompat', 'exception' => $e]);
			}
		}
		// Covers for users who switched that on; stops when the time is up and continues next hour.
		$deadline = microtime(true) + 120;
		foreach ($this->library->usersWithItems() as $uid) {
			if (microtime(true) > $deadline) {
				break;
			}
			try {
				$this->covers->autoAll($uid, $deadline);
			} catch (\Throwable $e) {
				$this->logger->warning('Looking up covers failed for ' . $uid . ': ' . $e->getMessage(), ['app' => 'abscompat', 'exception' => $e]);
			}
		}
		// Keep playback sessions (listening stats) for a year.
		$this->sessions->deleteOldSessions((int)(microtime(true) * 1000) - 365 * 86400 * 1000);
	}
}
