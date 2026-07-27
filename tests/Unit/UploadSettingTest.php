<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Activity\Provider;
use OCA\AtriumSecureShare\Activity\UploadSetting;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Tests\MocksL10N;
use PHPUnit\Framework\TestCase;

final class UploadSettingTest extends TestCase {
	use MocksL10N;

	private function setting(): UploadSetting {
		$admin = $this->createMock(AdminConfigService::class);
		$admin->method('getWhitelabelName')->willReturn('Atrium');
		return new UploadSetting($this->mockL10N(), $admin);
	}

	public function testIdentifierMatchesPublishedType(): void {
		self::assertSame(Provider::TYPE_UPLOADED, $this->setting()->getIdentifier());
	}

	public function testStreamIsDefaultEnabledAndNotDisableable(): void {
		$setting = $this->setting();
		self::assertTrue($setting->isDefaultEnabledStream());
		self::assertFalse($setting->canChangeStream());
	}

	public function testEmailDigestIsOptInAndToggleable(): void {
		$setting = $this->setting();
		self::assertFalse($setting->isDefaultEnabledMail());
		self::assertTrue($setting->canChangeMail());
	}
}
