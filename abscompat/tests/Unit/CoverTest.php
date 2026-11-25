<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\CoverService;
use OCA\AbsCompat\Service\SquareImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

class CoverTest extends TestCase {
	private static function r(string $title, string $author): array {
		return ['title' => $title, 'author' => $author, 'year' => '', 'thumb' => '', 'url' => $title];
	}

	public function testPickNeedsTheSameTitleAndAuthor(): void {
		$pick = static fn (array $rs, string $t, ?string $a) => CoverService::pick($rs, $t, $a)['url'] ?? null;
		$this->assertSame('Dune (Unabridged)', $pick([self::r('Dune Messiah', 'Frank Herbert'), self::r('Dune (Unabridged)', 'Frank Herbert')], 'Dune', 'Frank Herbert'));
		$this->assertNull($pick([self::r('Dune', 'Someone Else')], 'Dune', 'Frank Herbert'));
		$this->assertNull($pick([self::r('Dune Messiah', 'Frank Herbert')], 'Dune', 'Frank Herbert'));
		$this->assertNull($pick([self::r('Dune', 'Frank Herbert')], 'Dune', null));
		$this->assertNull($pick([self::r('Dune', 'Frank Herbert')], '', 'Frank Herbert'));
		$this->assertNull($pick([self::r('Dune', 'Frank Herbertson')], 'Dune', 'Frank Herbert'), 'whole names only');
		// Punctuation, accents, articles, several authors, the narrator listed first.
		$this->assertSame('The Emperor’s Soul', $pick([self::r('The Emperor’s Soul', 'Brandon Sanderson')], "Emperor's Soul", 'Angela Lin, Brandon Sanderson'));
		$this->assertSame('Les Misérables', $pick([self::r('Les Misérables', 'Víctor Hugo')], 'Les Miserables', 'Victor Hugo'));
		$this->assertSame('ספר', $pick([self::r('ספר', 'סופר אחד')], 'ספר', 'סופר אחד'));
	}

	public function testPickWithSubtitles(): void {
		$one = [self::r('Sapiens: A Brief History', 'Yuval Noah Harari')];
		$this->assertNotNull(CoverService::pick($one, 'Sapiens', 'Yuval Noah Harari'));
		$two = [self::r('Mistborn: Secret History', 'Brandon Sanderson'), self::r('Mistborn: The Final Empire', 'Brandon Sanderson')];
		$this->assertNull(CoverService::pick($two, 'Mistborn', 'Brandon Sanderson'), 'ambiguous');
		$this->assertSame('Mistborn: The Final Empire', CoverService::pick($two, 'Mistborn: The Final Empire', 'Brandon Sanderson')['url']);
		$this->assertNull(CoverService::pick([self::r('Mistborn', 'Brandon Sanderson')], 'Mistborn: The Final Empire', 'Brandon Sanderson'));
	}

	public function testParseKeepsOnlyAppleImages(): void {
		$art = 'https://is1-ssl.mzstatic.com/image/thumb/Music/ab/cover.jpg/100x100bb.jpg';
		$row = static fn (string $u) => ['collectionName' => "T\0", 'artistName' => 'A', 'releaseDate' => '2019-01-02T00:00:00Z', 'artworkUrl100' => $u];
		$out = CoverService::parse(json_encode(['results' => [$row($art), $row('https://evil.example/100x100bb.jpg'), $row('http://is1-ssl.mzstatic.com/a/100x100bb.jpg'), [], 'x']]));
		$this->assertCount(1, $out);
		$this->assertSame(['T', 'A', '2019'], [$out[0]['title'], $out[0]['author'], $out[0]['year']]);
		$this->assertSame('https://is1-ssl.mzstatic.com/image/thumb/Music/ab/cover.jpg/1200x1200bb.jpg', $out[0]['url']);
		$this->assertStringEndsWith('/200x200bb.jpg', $out[0]['thumb']);
		foreach (['', 'nope', '[]', '{"results":5}', '{"results":[{"artworkUrl100":[]}]}'] as $bad) {
			$this->assertSame([], CoverService::parse($bad));
		}
	}

	public function testAllowedUrls(): void {
		foreach (['https://mzstatic.com/a.jpg', 'https://a.mzstatic.com.evil.example/a.jpg', 'https://u@a.mzstatic.com/a.jpg', 'https://a.mzstatic.com:8080/a.jpg',
			'https://a.mzstatic.com/../x', 'https://a.mzstatic.com/a.jpg?x=1', "https://a.mzstatic.com/a.jpg\n", 'file:///etc/passwd', 'https://127.0.0.1/#.mzstatic.com/a'] as $bad) {
			$this->assertFalse(CoverService::allowedUrl($bad), $bad);
		}
		$this->assertTrue(CoverService::allowedUrl('https://is1-ssl.mzstatic.com/image/thumb/a%20b/1200x1200bb.jpg'));
	}

	private static function png(int $w, int $h): string {
		$im = imagecreatetruecolor($w, $h);
		imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
		// Blue bands at the outer edges: cropping would lose them.
		imagefilledrectangle($im, 0, 0, $w >= $h ? 9 : $w, $w >= $h ? $h : 9, imagecolorallocate($im, 30, 30, 200));
		ob_start();
		imagepng($im);
		return (string)ob_get_clean();
	}

	public static function sizes(): array {
		return [[300, 300, 300], [1000, 1500, 1200], [1500, 1000, 1200], [320, 300, 300], [3000, 3000, 1200], [40, 400, 400]];
	}

	#[RequiresPhpExtension('gd')]
	#[DataProvider('sizes')]
	public function testAlwaysSquare(int $w, int $h, int $side): void {
		$out = SquareImage::make(self::png($w, $h));
		[$ow, $oh, $type] = getimagesizefromstring($out);
		$this->assertSame([$side, $side, IMAGETYPE_JPEG], [$ow, $oh, $type]);
	}

	#[RequiresPhpExtension('gd')]
	public function testPortraitCoverIsNotCut(): void {
		$im = imagecreatefromstring(SquareImage::make(self::png(400, 600)));
		$top = imagecolorsforindex($im, imagecolorat($im, 300, 3));
		$this->assertGreaterThan(120, $top['blue'], 'the top edge is still there');
		$side = imagecolorsforindex($im, imagecolorat($im, 10, 300));
		$this->assertLessThan(190, $side['red'], 'the sides are the darker background');
	}

	#[RequiresPhpExtension('gd')]
	public function testBadImages(): void {
		$gif = "GIF89a\x10\x00\x10\x00\x00\x00\x00;";
		foreach (['', 'text', $gif, "\xff\xd8\xff\xe0broken", substr(self::png(300, 300), 0, 40), '<svg xmlns="http://www.w3.org/2000/svg"/>'] as $bad) {
			$this->assertNull(@SquareImage::make($bad));
		}
		// A tiny file that claims to be enormous is refused before it is decoded.
		$bomb = substr_replace(self::png(20, 20), pack('NN', 60000, 60000), 16, 8);
		$this->assertNull(SquareImage::make($bomb));
		$this->assertNull(SquareImage::ratio('x'));
		$this->assertEqualsWithDelta(1.5, SquareImage::ratio(self::png(600, 400)), 0.001);
	}
}
