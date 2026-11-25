<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\Catalog;
use OCA\AbsCompat\Service\SeriesParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeriesTest extends TestCase {
	public static function tags(): array {
		return [
			[[], null],
			[['series' => ' ; '], null],
			[['seriesPart' => '3'], null],
			[['series' => 'Saga'], ['Saga', null]],
			[['series' => 'Saga', 'seriesPart' => '0'], ['Saga', '0']],
			[['series' => 'Saga', 'seriesPart' => '3/7'], ['Saga', '3']],
			[['series' => 'Saga #2'], ['Saga', '2']],
			[['series' => 'A; B', 'seriesPart' => '1; 2'], ['A', '1']],
			[['series' => 'Saga', 'grouping' => 'Other #1'], ['Saga', null]],
			[['grouping' => 'Saga #1a; Other #2'], ['Saga', '1a']],
			[['grouping' => 'Saga #1 a'], ['Saga #1 a', null]],
			[['grouping' => 'C# Basics'], ['C# Basics', null]],
		];
	}

	#[DataProvider('tags')]
	public function testFromTags(array $tags, ?array $expected): void {
		$s = SeriesParser::fromTags($tags);
		$this->assertSame($expected, $s === null ? null : [$s['name'], $s['seq']]);
	}

	public static function folders(): array {
		return [
			['1 - Rising', 'Rising', '1'], ['02 - Two', 'Two', '2'], ['2.5 - Half', 'Half', '2.5'],
			['Book 3 - Three', 'Three', '3'], ['Book 3 Three', 'Three', '3'], ['Vol. 4 Four', 'Four', '4'], ['Volume 5: Five', 'Five', '5'],
			['6. Six', 'Six', '6'], ['7: Seven', 'Seven', '7'], ['Title - Book 8', 'Title', '8'], ['Title - Vol. 9 - 2004', 'Title - 2004', '9'],
			['101 Dalmatians', '101 Dalmatians', null], ['1984', '1984', null], ['1994 - Title', '1994 - Title', null],
			['Bookworm', 'Bookworm', null], ['Title', 'Title', null], ['12', '12', '12'],
		];
	}

	#[DataProvider('folders')]
	public function testFromFolder(string $name, string $title, ?string $seq): void {
		$this->assertSame(['title' => $title, 'seq' => $seq], SeriesParser::fromFolder($name));
	}

	public function testSequenceOrder(): void {
		$rows = array_map(static fn ($s) => ['title' => 'T', 'series_seq' => $s], ['10', null, '2.5', '1-2', 'x', '2']);
		$this->assertSame(['1-2', '2', '2.5', '10'], array_slice(array_column(Catalog::sortRows($rows, 'sequence', false), 'series_seq'), 0, 4));
	}

	public function testNextInSeries(): void {
		$s = static fn (string ...$keys) => ['rows' => array_map(static fn ($k) => ['item_key' => $k], $keys)];
		$p = static fn (bool $done, float $at, int $when) => ['is_finished' => $done, 'cur_time' => $at, 'last_update' => $when];
		$next = Catalog::nextInSeries(
			[$s('a1', 'a2', 'a3'), $s('b1', 'b2'), $s('c1', 'c2'), $s('d1'), $s('e1', 'e2'), $s('f1', 'f2')],
			[
				'a1' => $p(true, 9, 10), 'a2' => $p(true, 9, 20),
				'b1' => $p(true, 9, 30), 'b2' => $p(false, 5, 40), // in the middle of b2
				// c: not started; d: all finished
				'd1' => $p(true, 9, 50),
				'e2' => $p(true, 9, 60), // the skipped first book comes next
				'f1' => $p(true, 9, 70), 'f2' => $p(false, 0, 80), // reset to the start
			],
		);
		$this->assertSame(['f2', 'e1', 'a3'], array_column($next, 'item_key'));
	}
}
