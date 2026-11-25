<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\MetadataExtractor;
use OCP\Files\File;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Reads the generated fixtures in tests/fixtures (see make.sh). */
class MetadataExtractorTest extends TestCase {
	private function file(string $name): File {
		$path = __DIR__ . '/../fixtures/' . $name;
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn($path);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getInternalPath')->willReturn('files/' . $name);
		$file->method('getSize')->willReturn(filesize($path));
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/u/files/' . $name);
		return $file;
	}

	public function testM4bWithChaptersAndTags(): void {
		$m = (new MetadataExtractor(new NullLogger()))->extract($this->file('chapters.m4b'), false);
		$this->assertEqualsWithDelta(10.0, $m['duration'], 0.2);
		$this->assertSame(['One', 'Two'], array_column($m['chapters'], 'title'));
		$this->assertEqualsWithDelta(4.0, $m['chapters'][1]['start'], 0.05);
		$this->assertSame('Fixture Book', $m['tags']['album']);
		$this->assertSame('Fix Author', $m['tags']['albumArtist'] ?? $m['tags']['artist']);
		$this->assertSame('Fix Narrator', $m['tags']['composer']);
	}

	public function testMp3WithId3Chapters(): void {
		$m = (new MetadataExtractor(new NullLogger()))->extract($this->file('chapters.mp3'), false);
		$this->assertEqualsWithDelta(10.0, $m['duration'], 0.3);
		$this->assertSame(['One', 'Two'], array_column($m['chapters'], 'title'));
		$this->assertEqualsWithDelta(10.0, $m['chapters'][1]['end'], 0.3);
	}

	public function testMp3WithoutChapters(): void {
		$m = (new MetadataExtractor(new NullLogger()))->extract($this->file('plain.mp3'), false);
		$this->assertEqualsWithDelta(6.0, $m['duration'], 0.3);
		$this->assertSame([], $m['chapters']);
		$this->assertSame('Plain', $m['tags']['title']);
		$this->assertSame('2', $m['tags']['track']);
	}

	public function testUnreadableFileGivesEmptyMetadata(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->method('getLocalFile')->willReturn(false);
		$file = $this->createMock(File::class);
		$file->method('getStorage')->willReturn($storage);
		$m = (new MetadataExtractor(new NullLogger()))->extract($file, false);
		$this->assertSame(0.0, $m['duration']);
	}

	public function testMalformedTextIsCleaned(): void {
		$this->assertSame('ספר 🎧', MetadataExtractor::cleanText(" ספר 🎧\0 "));
		$this->assertSame("a\nb", MetadataExtractor::cleanText("a\nb"));
		$this->assertSame('onetwo', MetadataExtractor::cleanText("one\0two"));
		$bad = MetadataExtractor::cleanText("broken \xff\xfe\xc3( text");
		$this->assertTrue(mb_check_encoding($bad, 'UTF-8'));
		$this->assertStringStartsWith('broken ', $bad);

		$c = MetadataExtractor::chapters(['id3v2' => ['chapters' => [
			['time_begin' => 0, 'time_end' => 3000, 'chapter_name' => "Ch \xff x"],
			['time_begin' => 3000, 'time_end' => 6000, 'chapter_name' => "\0"],
		]]], 6.0);
		$this->assertNotFalse(json_encode($c));
		$this->assertSame('Chapter 2', $c[1]['title']);
	}

	public function testMalformedTagsInAFile(): void {
		$m = (new MetadataExtractor(new NullLogger()))->extract($this->file('malformed.mp3'), false);
		$this->assertNotFalse(json_encode($m['tags']), 'tags must be valid UTF-8');
		$this->assertStringStartsWith('broken', $m['tags']['description']);
		$this->assertStringNotContainsString("\0", implode('', $m['tags']));
		$this->assertCount(2, $m['chapters']);
	}

	public function testChapterNormalisation(): void {
		// Missing ends are filled from the next start, empty titles numbered, zero-length dropped.
		$info = ['id3v2' => ['chapters' => [
			['time_begin' => 5000, 'chapter_name' => ''],
			['time_begin' => 0, 'time_end' => 5000, 'chapter_name' => 'Intro'],
			['time_begin' => 9000, 'time_end' => 9000, 'chapter_name' => 'Empty'],
		]]];
		$c = MetadataExtractor::chapters($info, 9.0);
		$this->assertSame(['Intro', 'Chapter 2'], array_column($c, 'title'));
		$this->assertSame(9.0, $c[1]['end']);
	}
}
