<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;

/**
 * MailService sends the invitation email telling a recipient a file has been
 * shared with them via Atrium. The mail carries no share token: the link points
 * at the Atrium portal only, where the recipient authenticates via OIDC. Delivery
 * failures are logged, never propagated — the share stays valid and email_sent
 * stays false so the UI can flag "not notified" and offer a resend.
 */
class MailService {
	private IL10N $l;

	public function __construct(
		private readonly IMailer $mailer,
		private readonly IUserManager $userManager,
		private readonly PortalConfig $portalConfig,
		private readonly AdminConfigService $adminConfig,
		private readonly LoggerInterface $logger,
		IFactory $l10nFactory,
	) {
		$this->l = $l10nFactory->get(Application::APP_ID);
	}

	/**
	 * sendInvitation notifies the share recipient, subject to the admin email
	 * policy: nothing is sent when invitations are globally disabled, nor when the
	 * owner opted out ($userRequestedEmail = false) and opting out is allowed; when
	 * opt-out is not allowed the recipient is always notified. Returns true only
	 * when the mail was accepted, so the caller can persist email_sent.
	 */
	public function sendInvitation(AtriumShare $share, string $fileName, bool $userRequestedEmail = true): bool {
		if (!$this->adminConfig->isEmailEnabled()) {
			return false;
		}
		if (!$userRequestedEmail && $this->adminConfig->isEmailOptOutAllowed()) {
			return false;
		}

		$recipient = $share->getRecipientEmail();
		if (!$this->mailer->validateMailAddress($recipient)) {
			$this->logger->warning('atrium invitation not sent: invalid recipient address', [
				'share_id' => $share->getId(),
			]);
			return false;
		}

		$ownerName = $this->ownerDisplayName($share->getOwnerUid());
		$portalUrl = $this->portalConfig->getUrl();

		$template = $this->mailer->createEMailTemplate('atrium_secureshare.invitation', [
			'fileName' => $fileName,
			'ownerName' => $ownerName,
		]);
		$template->setSubject($this->l->t('%1$s shared "%2$s" with you', [$ownerName, $fileName]));
		$template->addHeader();
		$template->addHeading($this->l->t('A file was shared with you'));
		$template->addBodyText($this->l->t('%1$s has shared "%2$s" with you via Atrium.', [$ownerName, $fileName]));

		$expiresAt = $share->getExpiresAt();
		if ($expiresAt !== null) {
			$template->addBodyText($this->l->t('This share is available until %s.', [
				$this->l->l('datetime', $expiresAt),
			]));
		}

		$template->addBodyButton($this->l->t('Open Atrium'), $portalUrl);
		$template->addBodyText($this->l->t('You will be asked to sign in to confirm your identity.'));
		$template->addFooter();

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$recipient]);
			$message->useTemplate($template);
			$failed = $this->mailer->send($message);
			if ($failed !== []) {
				$this->logger->warning('atrium invitation partially failed', [
					'share_id' => $share->getId(),
					'failed_count' => count($failed),
				]);
				return false;
			}
			return true;
		} catch (\Throwable $e) {
			// Never let a mail failure abort share creation.
			$this->logger->error('atrium invitation send failed', [
				'share_id' => $share->getId(),
				'exception' => $e,
			]);
			return false;
		}
	}

	/** ownerDisplayName resolves the sharer's display name, falling back to uid. */
	private function ownerDisplayName(string $ownerUid): string {
		$user = $this->userManager->get($ownerUid);
		return $user !== null ? $user->getDisplayName() : $ownerUid;
	}
}
