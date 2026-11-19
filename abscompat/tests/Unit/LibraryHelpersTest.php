<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\LibraryService;
use OCA\AbsCompat\Service\TokenService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class LibraryHelpersTest extends TestCase {
	public function testCleanPaths(): void {
		$this->assertSame(['/Audiobooks', '/A/B'], LibraryService::cleanPaths(['Audiobooks/', '/A/B', '/Audiobooks', '/', '../x', '/a/../b', 42]));
	}

	public function testAudioDetectionAndMime(): void {
		$this->assertTrue(LibraryService::isAudio('Book.M4B'));
		$this->assertFalse(LibraryService::isAudio('cover.jpg'));
		$this->assertSame('audio/mp4', LibraryService::mimeFor('x.m4b'));
		$this->assertSame('audio/mpeg', LibraryService::mimeFor('x.mp3'));
	}

	public function testTokenFromRequest(): void {
		$r = $this->createMock(IRequest::class);
		$r->method('getHeader')->willReturnMap([['Authorization', 'Bearer abs_x']]);
		$this->assertSame('abs_x', TokenService::tokenFromRequest($r, false));

		$q = $this->createMock(IRequest::class);
		$q->method('getHeader')->willReturn('');
		$q->method('getParam')->willReturnMap([['token', null, 'abs_q']]);
		$this->assertSame('', TokenService::tokenFromRequest($q, false), 'query token only on media URLs');
		$this->assertSame('abs_q', TokenService::tokenFromRequest($q, true));
	}
}
