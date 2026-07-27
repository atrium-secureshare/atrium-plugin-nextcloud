<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class AtriumShareMapperTest extends TestCase {
	public function testDeleteRetiredBeforeSelectsExpiredAndExhaustedPastCutoff(): void {
		$sql = null;
		$params = null;
		$db = $this->createMock(IDBConnection::class);
		$db->expects(self::once())
			->method('executeStatement')
			->willReturnCallback(function (string $q, array $p) use (&$sql, &$params): int {
				$sql = $q;
				$params = $p;
				return 4;
			});

		$deleted = (new AtriumShareMapper($db))->deleteRetiredBefore(new \DateTime('2026-07-03 12:00:00'));

		self::assertSame(4, $deleted);
		self::assertStringContainsString('DELETE FROM', $sql);
		// Expired branch: an expiry older than the cutoff.
		self::assertStringContainsString('`expires_at` < ?', $sql);
		// Exhausted branch: cap met AND a recorded last download older than the cutoff.
		self::assertStringContainsString('`download_count` >= `max_downloads`', $sql);
		self::assertStringContainsString('`last_download_at` IS NOT NULL', $sql);
		self::assertStringContainsString('`last_download_at` < ?', $sql);
		// The cutoff is bound to both placeholders in the stored datetime format.
		self::assertSame(['2026-07-03 12:00:00', '2026-07-03 12:00:00'], $params);
	}
}
