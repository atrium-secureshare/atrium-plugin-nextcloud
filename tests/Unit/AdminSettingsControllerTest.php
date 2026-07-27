<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Controller\AdminSettingsController;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class AdminSettingsControllerTest extends TestCase {
	public function testUpdateAppliesProvidedFieldsAndReturnsConfig(): void {
		$config = $this->createMock(AdminConfigService::class);
		$config->method('getPublicKey')->willReturn('');
		$config->expects(self::once())->method('setPortalUrl')->with('https://portal.example');
		$config->expects(self::once())->method('setEmailEnabled')->with(false);
		$config->expects(self::once())->method('setEmailOptOutAllowed')->with(true);
		$config->expects(self::once())->method('setAllowedModes')->with([0, 1]);
		$config->expects(self::once())->method('setMaxShareDurationDays')->with(7);
		$config->expects(self::once())->method('setRetentionDays')->with(14);
		$config->expects(self::once())->method('setWhitelabelName')->with('Acme Share');
		$config->method('getAll')->willReturn(['portalUrl' => 'https://portal.example']);

		$response = $this->controller($config)->update(
			portalUrl: 'https://portal.example',
			emailEnabled: false,
			emailOptOutAllowed: true,
			allowedModes: [0, 1],
			maxShareDurationDays: 7,
			retentionDays: 14,
			whitelabelName: 'Acme Share',
		);

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('https://portal.example', $response->getData()['portalUrl']);
	}

	public function testUpdateDoesNotRewriteUnchangedKey(): void {
		$config = $this->createMock(AdminConfigService::class);
		$config->method('getPublicKey')->willReturn('EXISTING');
		$config->expects(self::never())->method('setPublicKey');
		$config->method('getAll')->willReturn([]);

		$this->controller($config)->update(corePublicKey: 'EXISTING');
	}

	public function testUpdateWritesChangedKey(): void {
		$config = $this->createMock(AdminConfigService::class);
		$config->method('getPublicKey')->willReturn('OLD');
		$config->expects(self::once())->method('setPublicKey')->with('NEW');
		$config->method('getAll')->willReturn([]);

		$this->controller($config)->update(corePublicKey: 'NEW');
	}

	public function testUpdateInvalidValueReturns400WithMessage(): void {
		$config = $this->createMock(AdminConfigService::class);
		$config->method('getPublicKey')->willReturn('');
		$config->method('setPublicKey')->willThrowException(new \InvalidArgumentException('key must use the P-256 (prime256v1) curve'));

		$response = $this->controller($config)->update(corePublicKey: 'bad');

		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame('key must use the P-256 (prime256v1) curve', $response->getData()['message']);
	}

	private function controller(AdminConfigService $config): AdminSettingsController {
		return new AdminSettingsController(
			$this->createMock(IRequest::class),
			$config,
		);
	}
}
