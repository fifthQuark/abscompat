<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\EbookReader;
use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\OpdsLink;
use PHPUnit\Framework\TestCase;

class EbookTest extends TestCase {
	private string $file;

	protected function setUp(): void {
		$this->file = (string)tempnam(sys_get_temp_dir(), 'epub');
	}

	protected function tearDown(): void {
		unlink($this->file);
	}

	private function epub(string $opf, array $more = [], string $path = 'OEBPS/content.opf'): array {
		$zip = new \ZipArchive();
		$zip->open($this->file, \ZipArchive::OVERWRITE);
		$zip->addFromString('META-INF/container.xml', '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="' . $path . '"/></rootfiles></container>');
		$zip->addFromString($path, $opf);
		foreach ($more as $n => $d) {
			$zip->addFromString($n, $d);
		}
		$zip->close();
		return EbookReader::readEpub($this->file);
	}

	public function testEpub(): void {
		$opf = '<package xmlns="http://www.idpf.org/2007/opf"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:identifier>urn:isbn:978-0-00-000000-2</dc:identifier><dc:title>A &amp; B</dc:title>'
			. '<dc:creator>Ann Lee</dc:creator><dc:creator>Bo</dc:creator><dc:date>2001-02-03</dc:date><dc:description>&lt;p&gt;Hi &amp;amp; bye&lt;/p&gt;</dc:description><dc:subject>X</dc:subject>'
			. '<meta name="calibre:series" content="Saga"/><meta name="calibre:series_index" content="2.0"/><meta name="cover" content="c1"/></metadata><manifest><item id="c1" href="../img/c%20x.jpg"/></manifest></package>';
		$r = $this->epub($opf, ['img/c x.jpg' => "\xff\xd8\xff\xe0data"]);
		$this->assertSame(['title' => 'A & B', 'artist' => 'Ann Lee; Bo', 'description' => 'Hi & bye', 'year' => '2001-02-03', 'genre' => 'X', 'isbn' => '9780000000002', 'series' => 'Saga', 'seriesPart' => '2'], $r['tags']);
		$this->assertSame('image/jpeg', $r['picture']['mime']);
	}

	public function testBadFiles(): void {
		$none = ['tags' => [], 'picture' => null];
		file_put_contents($this->file, 'not a zip');
		$this->assertSame($none, EbookReader::readEpub($this->file));
		$this->assertSame($none, $this->epub('<broken'));
		$xxe = '<!DOCTYPE p [<!ENTITY x SYSTEM "file:///etc/passwd">]><package><metadata><title>&x;</title></metadata></package>';
		$this->assertSame($none, $this->epub($xxe));
		// A cover that is no JPEG or PNG is not used.
		$this->assertNull($this->epub('<package><metadata><title>T</title></metadata><manifest><item id="c" href="c.svg" properties="cover-image"/></manifest></package>', ['OEBPS/c.svg' => '<svg/>'])['picture']);
	}

	public function testOpdsRowsBecomeBooks(): void {
		$row = static fn (int $id, string $name, array $more = []) => $more + ['file_id' => $id, 'name' => $name, 'path' => '/B/' . $name, 'parent' => '/B', 'size' => 1, 'mtime' => 2, 'format' => pathinfo($name, PATHINFO_EXTENSION),
			'title' => 'T', 'author' => 'Ann & Bo', 'series' => 'S', 'series_index' => 1.5, 'subjects' => 'x; y', 'pubyear' => 0, 'description' => null, 'has_cover' => 1];
		$works = OpdsLink::group([$row(1, 'a.pdf', ['work' => 'w']), $row(2, 'a.epub', ['work' => 'w']), $row(3, 'b.fb2'), $row(4, 'C.mobi', ['series_index' => 0]), $row(5, 'c.azw3')]);
		$this->assertSame([[2, 1], [5, 4]], array_map(static fn ($w) => array_column($w['files'], 'id'), $works));
		$this->assertSame(['Ann; Bo', 'S', '1.5', ['x', 'y'], '', true], [$works[0]['author'], $works[0]['series'], $works[0]['seriesSeq'], $works[0]['genres'], $works[0]['year'], $works[0]['cover']]);
	}

	public function testCleanEdits(): void {
		$this->assertSame([], LibraryService::cleanEdits(['title' => ' ', 'evil' => 1, 'author' => ['x'], 'genres' => 'no']));
		$this->assertSame(
			['title' => 'T', 'series' => null, 'seriesSeq' => null, 'year' => null, 'genres' => ['a', 'b'], 'tags' => [], 'explicit' => false, 'moreSeries' => [['name' => 'S', 'seq' => '2'], ['name' => 'U', 'seq' => null]]],
			LibraryService::cleanEdits(['title' => " T\0", 'series' => '', 'seriesSeq' => '4', 'year' => '', 'genres' => ['a', ' b', 'a', ['x']], 'tags' => [], 'explicit' => 'false', 'moreSeries' => [['name' => 'S', 'seq' => 2], ['seq' => 1], 'x', ['name' => 'U']]]),
		);
	}
}
