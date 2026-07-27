<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCA\AtriumSecureShare\Service\PersistedShareLookup;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

final class PersistedShareLookupTest extends TestCase {
	public function testResolvesTokenToShareInfo(): void {
		$share = ShareFactory::make(['token' => 'tok123']);
		$mapper = $this->createMock(AtriumShareMapper::class);
		$mapper->expects(self::once())->method('findByToken')->with('tok123')->willReturn($share);

		$info = (new PersistedShareLookup($mapper))->find('tok123');

		self::assertNotNull($info);
		self::assertSame('tok123', $info->id);
		self::assertSame('bob@example.com', $info->recipientEmail);
		self::assertTrue($info->isAccessible());
	}

	public function testUnknownTokenResolvesToNull(): void {
		$mapper = $this->createMock(AtriumShareMapper::class);
		$mapper->method('findByToken')->willThrowException(new DoesNotExistException('nope'));

		self::assertNull((new PersistedShareLookup($mapper))->find('missing'));
	}

	public function testExpiredShareIsNotAccessible(): void {
		$share = ShareFactory::make(['token' => 'tok123', 'expiresAt' => new \DateTime('-1 hour')]);
		$mapper = $this->createMock(AtriumShareMapper::class);
		$mapper->method('findByToken')->willReturn($share);

		$info = (new PersistedShareLookup($mapper))->find('tok123');

		self::assertNotNull($info);
		self::assertFalse($info->isAccessible());
	}
}
