<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\PodcastJson;
use OCA\AbsCompat\Service\PodcastService;
use PHPUnit\Framework\TestCase;

class PodcastTest extends TestCase {
	private const FEED = '<?xml version="1.0"?><rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"><channel><title>Pod &amp; Co</title>'
		. '<itunes:author>Ann</itunes:author><itunes:explicit>yes</itunes:explicit><itunes:category text="Arts"><itunes:category text="Books"/></itunes:category><image><url>https://i.example/p.png</url></image>'
		. '<item><title>Old</title><guid>g1</guid><pubDate>Mon, 05 Jan 2026 10:00:00 +0000</pubDate><enclosure url="https://a.example/1.mp3" type="audio/mpeg" length="12"/><itunes:duration>01:02:03</itunes:duration></item>'
		. '<item><title>New</title><pubDate>Tue, 06 Jan 2026 10:00:00 +0000</pubDate><enclosure url="https://a.example/2.mp3"/><itunes:duration>bad</itunes:duration><description>&lt;b&gt;x&lt;/b&gt;</description></item>'
		. '<item><title>Same guid</title><guid>g1</guid><enclosure url="https://a.example/3.mp3"/></item>'
		. '<item><title>Local file</title><enclosure url="file:///etc/passwd"/></item></channel></rss>';

	public function testFeed(): void {
		$p = PodcastService::parse(self::FEED, 'https://f.example/rss');
		$this->assertSame(['Pod & Co', 'Ann', true, ['Arts'], 'https://i.example/p.png', PodcastService::podcastKey('https://f.example/rss')], [$p['title'], $p['author'], $p['explicit'], $p['genres'], $p['image'], $p['id']]);
		$this->assertSame([['New', 0.0, 'audio/mpeg', null], ['Old', 3723.0, 'audio/mpeg', 'g1']], array_map(static fn ($e) => [$e['title'], $e['duration'], $e['type'], $e['guid']], $p['episodes']));
		$this->assertTrue(PodcastService::isEpisodeKey($p['episodes'][0]['id']));
		$this->assertSame('<b>x</b>', $p['episodes'][0]['description']);
	}

	public function testBadFeeds(): void {
		foreach (['', 'not xml', '<!DOCTYPE r [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><title>&x;</title></channel></rss>', '<html/>'] as $xml) {
			$p = PodcastService::parse($xml, 'https://host.example/feed');
			$this->assertSame(['host.example', []], [$p['title'], $p['episodes']], $xml);
		}
	}

	public function testDurationsAndFinished(): void {
		$this->assertSame([62.0, 3723.0, 90.5, 0.0, 0.0], array_map([PodcastService::class, 'seconds'], ['1:02', '1:02:03', '90.5', '', '1:2:3:4']));
		$this->assertFalse(PodcastService::finished(null));
		$this->assertFalse(PodcastService::finished(['position' => 5, 'total' => 0, 'at' => 0]));
		$this->assertTrue(PodcastService::finished(['position' => 100, 'total' => 100, 'at' => 0]));
	}

	public function testAppShape(): void {
		$p = PodcastService::parse(self::FEED, 'https://f.example/rss') + ['fetched' => 100];
		$item = PodcastJson::item($p, true);
		// The Android app tells podcasts from books by these keys.
		$this->assertArrayHasKey('autoDownloadEpisodes', $item['media']);
		$this->assertArrayHasKey('feedUrl', $item['media']['metadata']);
		$this->assertArrayNotHasKey('audioFiles', $item['media']);
		$this->assertSame([2, 1], array_column($item['media']['episodes'], 'index'));
		$progress = PodcastJson::progress('u', $p, $p['episodes'][1], ['position' => 3723, 'total' => 3723, 'at' => 7]);
		$this->assertSame([true, 1.0, 7000, 'mp_' . $p['id'] . '-' . $p['episodes'][1]['id']], [$progress['isFinished'], $progress['progress'], $progress['lastUpdate'], $progress['id']]);
	}
}
