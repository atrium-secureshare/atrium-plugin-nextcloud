<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use PHPUnit\Framework\TestCase;

final class AtriumShareTest extends TestCase {
	/**
	 * @dataProvider statusCases
	 *
	 * @param array<string,mixed> $overrides
	 */
	public function testDerivedStatus(
		array $overrides,
		bool $expired,
		bool $limitReached,
		bool $active,
		string $status,
		?\DateTime $referenceTime,
	): void {
		$share = ShareFactory::make($overrides);
		self::assertSame($expired, $share->isExpired());
		self::assertSame($limitReached, $share->isDownloadLimitReached());
		self::assertSame($active, $share->isActive());
		self::assertSame($status, $share->getStatus());
		// assertSame is deliberate: statusReferenceTime must return the very
		// instant instance the retention deadline is measured from, not a copy.
		self::assertSame($referenceTime, $share->statusReferenceTime());
	}

	/**
	 * Columns: overrides, isExpired, isDownloadLimitReached, isActive, getStatus,
	 * statusReferenceTime. The DateTime instances are reused as both the input
	 * override and the expected reference time so the identity assertion holds.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:bool,2:bool,3:bool,4:string,5:?\DateTime}>
	 */
	public static function statusCases(): array {
		$past = new \DateTime('-1 hour');
		$future = new \DateTime('+1 hour');
		$lastDownload = new \DateTime('-2 hours');
		return [
			// A fresh share with no expiry and no cap also covers the null-expiry
			// branch (expiresAt defaults to null), so no separate null case is needed.
			'fresh, no expiry or cap' => [[], false, false, true, AtriumShare::STATUS_ACTIVE, null],
			'expiry in the past' => [['expiresAt' => $past], true, false, false, AtriumShare::STATUS_EXPIRED, $past],
			'expiry in the future' => [['expiresAt' => $future], false, false, true, AtriumShare::STATUS_ACTIVE, null],
			'download count meets cap' => [
				['maxDownloads' => 3, 'downloadCount' => 3, 'lastDownloadAt' => $lastDownload],
				false, true, false, AtriumShare::STATUS_EXHAUSTED, $lastDownload,
			],
			// Expiry wins over exhaustion for both the label and the reference time.
			'expiry takes precedence over exhaustion' => [
				['expiresAt' => $past, 'maxDownloads' => 1, 'downloadCount' => 1],
				true, true, false, AtriumShare::STATUS_EXPIRED, $past,
			],
			// A legacy exhausted row with no last_download_at has no reference time,
			// so the sidebar grace filter drops it.
			'exhausted without recorded download' => [
				['maxDownloads' => 1, 'downloadCount' => 1],
				false, true, false, AtriumShare::STATUS_EXHAUSTED, null,
			],
			'below the download cap' => [
				['maxDownloads' => 3, 'downloadCount' => 2],
				false, false, true, AtriumShare::STATUS_ACTIVE, null,
			],
			'null cap is unlimited' => [
				['maxDownloads' => null, 'downloadCount' => 9999],
				false, false, true, AtriumShare::STATUS_ACTIVE, null,
			],
		];
	}
}
