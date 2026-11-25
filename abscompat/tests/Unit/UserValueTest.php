<?php

declare(strict_types=1);

namespace OCA\AbsCompat\Tests\Unit;

use OCA\AbsCompat\Service\UserValue;
use OCP\Config\IUserConfig;
use PHPUnit\Framework\TestCase;

class UserValueTest extends TestCase {
	public function testUnsetKeysAreNeverRead(): void {
		$c = $this->createMock(IUserConfig::class);
		$c->method('hasKey')->willReturn(false);
		$c->expects($this->never())->method('getValueString');
		$c->expects($this->never())->method('getValueBool');
		$c->expects($this->never())->method('getValueInt');
		$this->assertSame('', UserValue::string($c, 'u', 'folders'));
		$this->assertFalse(UserValue::bool($c, 'u', 'auto_covers'));
		$this->assertSame(0, UserValue::lazyInt($c, 'u', 'last_scan'));
	}

	public function testSetKeysAreRead(): void {
		$c = $this->createMock(IUserConfig::class);
		$c->method('hasKey')->willReturnCallback(static fn ($u, $a, $k, $lazy = false) => $lazy === ($k === 'last_scan'));
		$c->method('getValueString')->willReturn('["A"]');
		$c->method('getValueBool')->willReturn(true);
		$c->expects($this->once())->method('getValueInt')->with('u', 'abscompat', 'last_scan', 0, true)->willReturn(7);
		$this->assertSame('["A"]', UserValue::string($c, 'u', 'folders'));
		$this->assertTrue(UserValue::bool($c, 'u', 'auto_covers'));
		$this->assertSame(7, UserValue::lazyInt($c, 'u', 'last_scan'));
	}
}
