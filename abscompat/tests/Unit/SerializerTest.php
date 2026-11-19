<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\Serializer;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase {
	private static function item(array $over = []): array {
		return $over + [
			'id' => 1, 'item_key' => '77', 'library_id' => '5', 'is_file' => 0, 'rel_path' => 'Ada/Book',
			'title' => 'The Book', 'subtitle' => null, 'author' => 'Ada Lovelace & Bob Smith', 'narrator' => 'Nora',
			'series' => 'Saga', 'series_seq' => '2', 'published_year' => '2020', 'description' => 'd',
			'genres' => '["Fantasy"]', 'duration' => 120.5, 'size' => 1000, 'chapters' => '[{"id":0,"start":0,"end":120.5,"title":"All"}]',
			'cover_file_id' => 9, 'cover_cache' => null, 'ebook_file_id' => null, 'added_at' => 10, 'updated_at' => 20, 'mtime' => 3,
		];
	}

	private static function track(): array {
		return ['track_index' => 1, 'file_id' => 88, 'filename' => 'a.m4b', 'rel_path' => 'Ada/Book/a.m4b', 'size' => 1000,
			'duration' => 120.5, 'start_offset' => 0, 'mime' => 'audio/mp4', 'mtime' => 3, 'meta' => '{"codec":"aac","tags":{"title":"A"}}'];
	}

	public function testNames(): void {
		$this->assertSame(['Ada Lovelace', 'Bob Smith'], Serializer::splitNames('Ada Lovelace & Bob Smith'));
		$this->assertSame(['A', 'B', 'C'], Serializer::splitNames('A; B and C'));
		$this->assertSame(['Andrew Anderson'], Serializer::splitNames('Andrew Anderson'));
		$this->assertSame([], Serializer::splitNames('  '));
		$this->assertSame('Lovelace, Ada', Serializer::lastFirst('Ada Lovelace'));
		$this->assertSame('Book, The', Serializer::ignorePrefix('The Book'));
		$this->assertSame(Serializer::seriesId('Saga'), Serializer::seriesId(' saga '));
	}

	/** Non-null fields of the Android app's Kotlin LibraryItem / Book / BookMetadata. */
	public function testMinifiedHasKotlinRequiredFieldsAndNoPodcastKeys(): void {
		$j = Serializer::itemMinified(self::item(), [self::track()], '/Audiobooks');
		foreach (['id', 'ino', 'libraryId', 'folderId', 'path', 'relPath', 'mtimeMs', 'ctimeMs', 'birthtimeMs', 'addedAt', 'updatedAt', 'isMissing', 'isInvalid', 'mediaType', 'media'] as $k) {
			$this->assertNotNull($j[$k] ?? null, $k);
		}
		$this->assertIsArray($j['media']['tags']);
		$this->assertIsArray($j['media']['metadata']['genres']);
		$this->assertIsBool($j['media']['metadata']['explicit']);
		$this->assertArrayNotHasKey('episodes', $j['media']);
		$this->assertArrayNotHasKey('feedUrl', $j['media']['metadata']);
		$this->assertArrayNotHasKey('author', $j['media']['metadata']);
		$this->assertSame('Ada Lovelace, Bob Smith', $j['media']['metadata']['authorName']);
		$this->assertSame('Saga #2', $j['media']['metadata']['seriesName']);
		$this->assertSame('/covers/77', $j['media']['coverPath']);
	}

	public function testExpandedTracksAndFiles(): void {
		$j = Serializer::itemExpanded(self::item(), [self::track()], '/Audiobooks');
		$t = $j['media']['tracks'][0];
		$this->assertSame('/api/items/77/file/88', $t['contentUrl']);
		$this->assertSame(0.0, $t['startOffset']);
		$this->assertFalse($t['isLocal']);
		$af = $j['media']['audioFiles'][0];
		$this->assertSame('88', $af['ino']);
		$this->assertSame('.m4b', $af['metadata']['ext']);
		$this->assertSame(120.5, $af['duration']);
		$this->assertSame([['id' => 0, 'start' => 0.0, 'end' => 120.5, 'title' => 'All']], $j['media']['chapters']);
		$this->assertSame([['id' => Serializer::seriesId('Saga'), 'name' => 'Saga', 'sequence' => '2']], $j['media']['metadata']['series']);
		$this->assertNull($j['media']['ebookFile']);
	}

	public function testUserPermissions(): void {
		$u = Serializer::user('alice', 'Alice', null, [], []);
		$this->assertTrue($u['permissions']['download']);
		$this->assertSame('alice', $u['username']);
		$this->assertSame([], $u['librariesAccessible']);
	}
}
