<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Http\RangeFileResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RangeParseTest extends TestCase {
	public static function ranges(): array {
		return [
			'open end' => ['bytes=100-', [100, 999]],
			'closed' => ['bytes=10-19', [10, 19]],
			'end past size is clamped' => ['bytes=900-5000', [900, 999]],
			'suffix' => ['bytes=-5', [995, 999]],
			'suffix longer than file' => ['bytes=-5000', [0, 999]],
			'spaces' => [' bytes = 0 - 0 ', [0, 0]],
			'start past end' => ['bytes=1000-', false],
			'start after end' => ['bytes=20-10', false],
			'zero suffix' => ['bytes=-0', false],
			'multi-range served whole' => ['bytes=0-1,5-6', null],
			'other unit served whole' => ['items=0-1', null],
			'empty spec' => ['bytes=-', null],
		];
	}

	#[DataProvider('ranges')]
	public function testParse(string $header, array|null|false $expected): void {
		$this->assertSame($expected, RangeFileResponse::parseRange($header, 1000));
	}
}
