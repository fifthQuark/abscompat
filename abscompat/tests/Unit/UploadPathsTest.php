<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\UploadService;
use PHPUnit\Framework\TestCase;

class UploadPathsTest extends TestCase {
	private static function parts(string $title, string $author = '', string $series = '', string $seq = ''): array {
		return UploadService::bookParts($title, $author, $series, $seq, static fn (string $s) => $s);
	}

	public function testLayouts(): void {
		$this->assertSame(['T'], self::parts('T'));
		$this->assertSame(['A', 'T'], self::parts('T', 'A'));
		$this->assertSame(['A', 'S', 'T'], self::parts('T', 'A', 'S'));
		$this->assertSame(['A', 'S', '1.5 - T'], self::parts('T', 'A', 'S', ' 1.5 '));
		$this->assertSame(['A', 'S', 'T'], self::parts('T', 'A', 'S', 'one/..'));
		$this->assertSame(['Unknown author', 'S', 'T'], self::parts('T', '', 'S'));
		$this->assertSame([], self::parts(" .. \0"));
	}

	public function testNamesStayInsideTheLibrary(): void {
		foreach (['../../x', '..', '/etc/passwd', '..\\..\\x', "a\0/../b", '.hidden', 'x/.', 'trailing. '] as $bad) {
			foreach (self::parts($bad, $bad, $bad, $bad) as $p) {
				$this->assertDoesNotMatchRegularExpression('#[/\\\\\x00]|^\.|[. ]$#', $p, $bad);
			}
		}
		// A sanitizer that brings separators back is not trusted either.
		$this->assertSame('a_b', UploadService::component('x', static fn () => 'a/b'));
	}

	public function testLongNamesAreCutBetweenCharacters(): void {
		$p = self::parts(str_repeat('ש', 300))[0];
		$this->assertLessThanOrEqual(120, strlen($p));
		$this->assertTrue(mb_check_encoding($p, 'UTF-8'));
	}

	public function testFileNames(): void {
		$names = static fn (array $f) => UploadService::fileNames($f, static fn (string $s) => $s);
		$a = static fn (string $p) => ['path' => $p, 'kind' => 'audio'];
		$this->assertSame(['CD1 - 01.mp3', 'CD2 - 01.mp3'], $names([$a('CD1/01.mp3'), $a('CD2/01.mp3')]));
		$this->assertSame(['x.mp3', 'X (2).mp3'], $names([$a('x.mp3'), $a('X.MP3')]));
		$this->assertSame(['Audio.mp3'], $names([$a('../.mp3')]));
		$this->assertSame(['cover.jpg'], $names([['path' => 'My Photo.JPEG', 'kind' => 'cover']]));
		foreach ([['path' => 'x.php', 'kind' => 'audio'], ['path' => 'x.svg', 'kind' => 'cover'], ['path' => 'x.mp3', 'kind' => ''], ['path' => 'x.mp3'], []] as $bad) {
			$this->assertNull($names([$bad]));
		}
	}
}
