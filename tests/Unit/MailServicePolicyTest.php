<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Service\MailService;
use OCA\AtriumSecureShare\Service\PortalConfig;
use OCA\AtriumSecureShare\Tests\MocksL10N;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class MailServicePolicyTest extends TestCase {
	use MocksL10N;

	public function testGlobalDisabledSendsNothing(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects(self::never())->method('send');

		$service = $this->service($mailer, emailEnabled: false, optOutAllowed: true);
		self::assertFalse($service->sendInvitation(ShareFactory::make(['id' => 1]), 'file.txt', true));
	}

	public function testOptOutAllowedAndOwnerDeclinedSendsNothing(): void {
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects(self::never())->method('send');

		$service = $this->service($mailer, emailEnabled: true, optOutAllowed: true);
		self::assertFalse($service->sendInvitation(ShareFactory::make(['id' => 1]), 'file.txt', false));
	}

	public function testOptOutNotAllowedNotifiesEvenWhenOwnerDeclined(): void {
		$mailer = $this->sendingMailer();
		$mailer->expects(self::once())->method('send')->willReturn([]);

		$service = $this->service($mailer, emailEnabled: true, optOutAllowed: false);
		self::assertTrue($service->sendInvitation(ShareFactory::make(['id' => 1]), 'file.txt', false));
	}

	/** sendingMailer wires the full template/message path so send() is reached. */
	private function sendingMailer(): IMailer {
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createEMailTemplate')->willReturn($this->createStub(IEMailTemplate::class));
		$mailer->method('createMessage')->willReturn($this->createStub(IMessage::class));
		return $mailer;
	}

	private function service(IMailer $mailer, bool $emailEnabled, bool $optOutAllowed): MailService {
		$adminConfig = $this->createStub(AdminConfigService::class);
		$adminConfig->method('isEmailEnabled')->willReturn($emailEnabled);
		$adminConfig->method('isEmailOptOutAllowed')->willReturn($optOutAllowed);

		$factory = $this->createStub(IFactory::class);
		$factory->method('get')->willReturn($this->mockL10N());

		return new MailService(
			$mailer,
			$this->createStub(IUserManager::class),
			new PortalConfig($this->createStub(IAppConfig::class), $this->createStub(IURLGenerator::class)),
			$adminConfig,
			$this->createStub(LoggerInterface::class),
			$factory,
		);
	}
}
