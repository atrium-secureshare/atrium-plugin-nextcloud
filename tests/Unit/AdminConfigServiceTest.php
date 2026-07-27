<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdminConfigServiceTest extends TestCase {
	/** A valid ES256 (P-256 / prime256v1) public key. */
	private const P256_PEM = <<<PEM
		-----BEGIN PUBLIC KEY-----
		MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAECUFr6xCIhfX6eBTZfC/bdaMwc2s2
		O2k933Uk9R+hOjhQ8sPfyUjIU/C48q9g8b6tAz/s8iB+NRaoEwo/eBaB5A==
		-----END PUBLIC KEY-----
		PEM;

	/** A valid EC public key on the wrong curve (secp384r1) — must be rejected. */
	private const P384_PEM = <<<PEM
		-----BEGIN PUBLIC KEY-----
		MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAEJLKv7QnEqfclZz4adxmo2RpIy9FFYmsD
		JfFmasLHHLijTkwIehn7nh1f8v9feIq067Cmiv1ZwNn1Jh6nm5BaESYgDOCPDlm9
		D8dBmM+0HAnv6GLWWnzJHeBkU0H3H0gS
		-----END PUBLIC KEY-----
		PEM;

	/** A valid RSA public key — must be rejected (ES256 requires EC). */
	private const RSA_PEM = <<<PEM
		-----BEGIN PUBLIC KEY-----
		MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA2cMak4pKqTe5S/VJHMLt
		El4HUYCNuAAQpQHWpdahP2OXyvVoQtV0eflfnu0wFpxsyuNPQbBKilMPFXJPOlN+
		MiKTAmAnIpUtIeBTmvH2evpQWHAflI6yNdyq/FaxBJwY6he566TmI2grIWA3ur9T
		DlQSS4y0DVOwicQ+N36S2bZwUPaquETAX27V3VVgWb3FEpSdlCWG6YxJ9avJFio6
		jLSuGn3y9MAMzDPWBRffPi7yIy3JfLGZ7wD3a0ciDYfJvmhhZG0zlzJ7uFDrz8ES
		gsmfBI4WUYu7rk/txp1JdXioXCgbWTC0QKJ3BWAU6/U8jfYzg2KGV4DqgXvrpZF8
		oQIDAQAB
		-----END PUBLIC KEY-----
		PEM;

	public function testSetPublicKeyAcceptsValidES256(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, AdminConfigService::KEY_CORE_PUBLIC_KEY, self::P256_PEM);

		$this->service($appConfig)->setPublicKey(self::P256_PEM);
	}

	/**
	 * @dataProvider invalidPublicKeys
	 */
	public function testSetPublicKeyRejects(string $pem): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setPublicKey($pem);
	}

	/** @return array<string,array{0:string}> */
	public static function invalidPublicKeys(): array {
		return [
			'malformed PEM' => ['not a key'],
			'wrong curve (P-384)' => [self::P384_PEM],
			'RSA key' => [self::RSA_PEM],
		];
	}

	public function testSetPublicKeyAcceptsEmptyToClearTrust(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())
			->method('setValueString')
			->with(Application::APP_ID, AdminConfigService::KEY_CORE_PUBLIC_KEY, '');

		$this->service($appConfig)->setPublicKey('   ');
	}

	public function testComputeFingerprintIsDeterministicAndPrefixed(): void {
		$service = $this->service();
		$fp = $service->computeFingerprint(self::P256_PEM);

		self::assertStringStartsWith('SHA256:', $fp);
		self::assertSame($fp, $service->computeFingerprint(self::P256_PEM));
	}

	public function testGetAllowedModesDefaultsToAllFour(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueArray')->willReturnArgument(2); // return the default (3rd arg)
		self::assertSame([0, 1, 2, 3], $this->service($appConfig)->getAllowedModes());
	}

	public function testGetAllowedModesSanitisesStoredValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		// Duplicates, an out-of-range mode and string values are all cleaned up.
		$appConfig->method('getValueArray')->willReturn([2, 2, '1', 9]);
		self::assertSame([1, 2], $this->service($appConfig)->getAllowedModes());
	}

	public function testGetAllowedModesFallsBackWhenStoredEmpty(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueArray')->willReturn([]);
		self::assertSame([0, 1, 2, 3], $this->service($appConfig)->getAllowedModes());
	}

	public function testSetAllowedModesRejectsEmptySet(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setAllowedModes([]);
	}

	public function testSetAllowedModesRejectsUnknownMode(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setAllowedModes([0, 5]);
	}

	public function testGetMaxShareDurationDaysReturnsNullWhenZero(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(0);
		self::assertNull($this->service($appConfig)->getMaxShareDurationDays());
	}

	public function testGetMaxShareDurationDaysReturnsConfiguredValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(14);
		self::assertSame(14, $this->service($appConfig)->getMaxShareDurationDays());
	}

	public function testSetMaxShareDurationDaysRejectsNegative(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setMaxShareDurationDays(-1);
	}

	public function testGetRetentionDaysDefaultsToSeven(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		// Return the injected default (3rd arg) so the service default surfaces.
		$appConfig->method('getValueInt')->willReturnArgument(2);
		self::assertSame(7, $this->service($appConfig)->getRetentionDays());
	}

	public function testGetRetentionDaysReturnsConfiguredValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(0);
		self::assertSame(0, $this->service($appConfig)->getRetentionDays());
	}

	public function testGetRetentionDaysClampsNegativeToZero(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturn(-5);
		self::assertSame(0, $this->service($appConfig)->getRetentionDays());
	}

	public function testSetRetentionDaysRejectsNegative(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->setRetentionDays(-1);
	}

	public function testSetRetentionDaysPersistsValue(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->expects(self::once())
			->method('setValueInt')
			->with(Application::APP_ID, AdminConfigService::KEY_RETENTION_DAYS, 14);
		$this->service($appConfig)->setRetentionDays(14);
	}

	public function testGetWhitelabelNameFallsBackToDefault(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('  ');
		self::assertSame('Atrium', $this->service($appConfig)->getWhitelabelName());
	}

	public function testGetPolicyOmitsTrustKey(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueArray')->willReturnArgument(2);
		$appConfig->method('getValueInt')->willReturnArgument(2);

		$policy = $this->service($appConfig)->getPolicy();

		self::assertArrayNotHasKey('corePublicKey', $policy);
		self::assertArrayHasKey('allowedModes', $policy);
		self::assertArrayHasKey('whitelabelName', $policy);
	}

	private function service(?IAppConfig $appConfig = null): AdminConfigService {
		return new AdminConfigService(
			$appConfig ?? $this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
