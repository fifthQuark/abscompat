<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\ProgressService;
use PHPUnit\Framework\TestCase;

/** The Audiobookshelf progress rules (ProgressService::compute). */
class ProgressRulesTest extends TestCase {
	private const NOW = 1_800_000_000_000;

	private static function row(array $s, int $lastUpdate = 1): array {
		return [
			'cur_time' => $s['cur_time'] ?? 0, 'duration' => $s['duration'] ?? 120, 'progress' => $s['progress'] ?? 0,
			'is_finished' => (int)($s['is_finished'] ?? 0), 'hide_continue' => (int)($s['hide_continue'] ?? 0),
			'ebook_progress' => $s['ebook_progress'] ?? null, 'ebook_location' => $s['ebook_location'] ?? null,
			'started_at' => 5, 'finished_at' => $s['finished_at'] ?? null, 'last_update' => $lastUpdate,
		];
	}

	public function testNewRecordFromCrossPointPush(): void {
		[$s, $lu] = ProgressService::compute(null, ['progress' => 0.25, 'duration' => 120, 'currentTime' => 30, 'ebookProgress' => 0.25], 120, self::NOW);
		$this->assertSame(30.0, $s['cur_time']);
		$this->assertSame(0.25, $s['progress']);
		$this->assertSame(0.25, $s['ebook_progress']);
		$this->assertFalse($s['is_finished']);
		$this->assertSame(self::NOW, $s['started_at']);
		$this->assertSame(self::NOW, $lu);
	}

	public function testProgressOnlyMovesCurrentTime(): void {
		[$s] = ProgressService::compute(self::row(['cur_time' => 10]), ['progress' => 0.5], 120, self::NOW);
		$this->assertSame(60.0, $s['cur_time']);
		$this->assertSame(0.5, $s['progress']);
	}

	public function testCurrentTimeOnlyUpdatesProgress(): void {
		[$s] = ProgressService::compute(self::row([]), ['currentTime' => 30], 120, self::NOW);
		$this->assertSame(0.25, $s['progress']);
	}

	public function testIsFinishedFalseIgnoresProgressLikeAbs(): void {
		[$s] = ProgressService::compute(self::row(['progress' => 0.5, 'cur_time' => 60]), ['progress' => 0.9, 'isFinished' => false], 120, self::NOW);
		$this->assertSame(0.5, $s['progress']);
		$this->assertSame(60.0, $s['cur_time']);
	}

	public function testFinishSetsProgressAndTimestamp(): void {
		[$s] = ProgressService::compute(self::row(['cur_time' => 30]), ['isFinished' => true], 120, self::NOW);
		$this->assertTrue($s['is_finished']);
		$this->assertSame(1.0, $s['progress']);
		$this->assertSame(self::NOW, $s['finished_at']);
	}

	public function testUnfinishResetsPosition(): void {
		[$s] = ProgressService::compute(self::row(['cur_time' => 119, 'progress' => 1, 'is_finished' => 1, 'finished_at' => 7]), ['isFinished' => false, 'currentTime' => 50], 120, self::NOW);
		$this->assertFalse($s['is_finished']);
		$this->assertSame(0.0, $s['cur_time']);
		$this->assertSame(0.0, $s['progress']);
		$this->assertNull($s['finished_at']);
	}

	public function testAutoFinishWithinTenSecondsOfTheEnd(): void {
		[$s] = ProgressService::compute(self::row([]), ['currentTime' => 111, 'duration' => 120], 120, self::NOW);
		$this->assertTrue($s['is_finished']);
		$this->assertSame(1.0, $s['progress']);
		[$s] = ProgressService::compute(self::row([]), ['currentTime' => 109, 'duration' => 120], 120, self::NOW);
		$this->assertFalse($s['is_finished']);
	}

	public function testSeekingBackUnfinishes(): void {
		[$s] = ProgressService::compute(self::row(['cur_time' => 115, 'progress' => 1, 'is_finished' => 1, 'finished_at' => 7]), ['currentTime' => 20], 120, self::NOW);
		$this->assertFalse($s['is_finished']);
		$this->assertNull($s['finished_at']);
	}

	public function testLastUpdateFromPayload(): void {
		[, $lu] = ProgressService::compute(self::row([]), ['currentTime' => 5, 'lastUpdate' => 1234], 120, self::NOW);
		$this->assertSame(1234, $lu);
	}

	public function testHideFlagClearedWhenPositionMoves(): void {
		[$s] = ProgressService::compute(self::row(['hide_continue' => 1, 'cur_time' => 5]), ['currentTime' => 6], 120, self::NOW);
		$this->assertFalse($s['hide_continue']);
		[$s] = ProgressService::compute(self::row(['hide_continue' => 0]), ['hideFromContinueListening' => true], 120, self::NOW);
		$this->assertTrue($s['hide_continue']);
	}

	public function testEbookFieldsAndClamping(): void {
		[$s] = ProgressService::compute(self::row([]), ['ebookProgress' => 1.7, 'ebookLocation' => 'epubcfi(/6/2)', 'progress' => -3], 120, self::NOW);
		$this->assertSame(1.0, $s['ebook_progress']);
		$this->assertSame('epubcfi(/6/2)', $s['ebook_location']);
		$this->assertSame(0.0, $s['progress']);
	}

	public function testItemDurationUsedWhenPayloadHasNone(): void {
		[$s] = ProgressService::compute(null, ['progress' => 0.5], 300, self::NOW);
		$this->assertSame(300.0, $s['duration']);
		$this->assertSame(150.0, $s['cur_time']);
	}

	public function testJsonShape(): void {
		$json = ProgressService::toJson(self::row(['cur_time' => 3, 'progress' => 0.1]) + ['item_key' => '42'], 'alice');
		foreach (['id', 'libraryItemId', 'duration', 'progress', 'currentTime', 'isFinished', 'lastUpdate', 'startedAt'] as $k) {
			$this->assertArrayHasKey($k, $json);
			$this->assertNotNull($json[$k], $k);
		}
		$this->assertSame('42', $json['libraryItemId']);
		$this->assertSame('mp_42', $json['id']);
	}
}
