<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\Activity\Provider;
use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCA\AtriumSecureShare\Exception\DownloadLimitReachedException;
use OCP\Activity\IManager as IActivityManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use OCP\Share\IManager as IShareManager;
use Psr\Log\LoggerInterface;

/**
 * ShareService is the domain logic for identity-bound external shares: creation
 * (with token minting and optional invitation), the authoritative active-share
 * filter, hard-delete revocation and the atomic download counter. The plugin is
 * the sole authorization instance, so "is this share still usable?" is decided
 * here (and re-checked at download time), never left to the caller.
 */
class ShareService {
	/** Token length in characters; alphanumeric => URL-safe capability. */
	private const TOKEN_LENGTH = 64;

	public function __construct(
		private readonly AtriumShareMapper $mapper,
		private readonly IDBConnection $db,
		private readonly ISecureRandom $secureRandom,
		private readonly MailService $mailService,
		private readonly IActivityManager $activityManager,
		private readonly IShareManager $shareManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * createShare persists a new share for $recipientEmail and, when requested,
	 * sends the invitation. A mail failure does not fail creation.
	 */
	public function createShare(
		int $fileId,
		string $ownerUid,
		string $recipientEmail,
		int $permissions,
		?int $maxDownloads,
		?\DateTime $expiresAt,
		bool $sendEmail,
		string $fileName = '',
	): AtriumShare {
		$share = new AtriumShare();
		$share->setToken($this->secureRandom->generate(self::TOKEN_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC));
		$share->setFileId($fileId);
		$share->setOwnerUid($ownerUid);
		$share->setRecipientEmail(EmailCanonicalizer::canonical($recipientEmail));
		$share->setPermissions($permissions);
		$share->setMaxDownloads($maxDownloads);
		$share->setDownloadCount(0);
		$share->setExpiresAt($expiresAt);
		$share->setCreatedAt(new \DateTime());
		$share->setEmailSent(false);
		$share = $this->mapper->insert($share);

		// A side-effect, never a precondition: publishing runs after the share is
		// persisted and its failure is swallowed.
		$this->publishShareActivity(Provider::SUBJECT_SHARED_SELF, $share, $fileName);

		// The mailer applies the admin email policy, so the owner's $sendEmail
		// preference is passed through rather than gating the call here (when
		// opt-out is disallowed the recipient is notified regardless).
		if ($this->mailService->sendInvitation($share, $fileName, $sendEmail)) {
			$share->setEmailSent(true);
			$share = $this->mapper->update($share);
		}

		return $share;
	}

	/**
	 * publishShareActivity writes one share-lifecycle entry (created or revoked)
	 * into the owner's native activity stream. The owner is both author and
	 * affected user. Any failure is logged and swallowed — an activity must never
	 * turn a successful share mutation into a failed request.
	 */
	private function publishShareActivity(string $subject, AtriumShare $share, string $fileName): void {
		try {
			$event = $this->activityManager->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType('shared')
				->setAuthor($share->getOwnerUid())
				->setAffectedUser($share->getOwnerUid())
				->setSubject($subject, [$fileName, $share->getRecipientEmail()])
				->setObject('files', $share->getFileId(), $fileName);
			$this->activityManager->publish($event);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to publish share activity', ['subject' => $subject, 'exception' => $e]);
		}
	}

	/**
	 * publishUploadActivity records an external recipient's upload of $node in the
	 * native activity stream of everyone with access to the node (the owner and
	 * every internal user the folder is shared with). The uploader is named by
	 * email — the only identity an external uploader has — and the author is set to
	 * the owner as a technical anchor, since there is no uid for the actual actor.
	 * Any failure is logged and swallowed so it can never turn a stored upload into
	 * a failed request.
	 */
	public function publishUploadActivity(AtriumShare $share, File $node, string $uploaderEmail): void {
		try {
			$fileName = $node->getName();
			$email = EmailCanonicalizer::canonical($uploaderEmail);
			foreach ($this->uploadRecipients($node, $share->getOwnerUid()) as $affectedUser) {
				$event = $this->activityManager->generateEvent();
				$event->setApp(Application::APP_ID)
					->setType(Provider::TYPE_UPLOADED)
					->setAuthor($share->getOwnerUid())
					->setAffectedUser($affectedUser)
					->setSubject(Provider::SUBJECT_UPLOADED, [$fileName, $email])
					->setObject('files', $node->getId(), $fileName);
				$this->activityManager->publish($event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to publish upload activity', ['exception' => $e]);
		}
	}

	/**
	 * uploadRecipients returns the uids that should see the upload entry: the owner
	 * plus every user the node is shared with. getAccessList walks the folder tree,
	 * so a user who reaches the file through a shared ancestor is included. The
	 * owner is always present (getAccessList lists sharees, not the owner).
	 *
	 * @return string[]
	 */
	private function uploadRecipients(File $node, string $ownerUid): array {
		$uids = [$ownerUid => true];
		$access = $this->shareManager->getAccessList($node, true, true);
		foreach (array_keys($access['users'] ?? []) as $uid) {
			$uids[(string)$uid] = true;
		}
		return array_keys($uids);
	}

	/**
	 * updateShare edits the mutable fields of a share owned by $ownerUid (mode, cap,
	 * expiry). The recipient email and file id are the share's identity and are
	 * never touched — editing instead of re-creating prevents duplicate rows. When
	 * the invitation was not sent yet and is now requested it is sent once (the
	 * resend affordance); an already notified recipient is never mailed again.
	 *
	 * @throws DoesNotExistException when the share does not exist
	 * @throws NotPermittedException when $ownerUid is not the share owner
	 */
	public function updateShare(
		int $shareId,
		string $ownerUid,
		int $permissions,
		?int $maxDownloads,
		?\DateTime $expiresAt,
		bool $sendEmail,
		string $fileName = '',
	): AtriumShare {
		$share = $this->mapper->find($shareId);
		if ($share->getOwnerUid() !== $ownerUid) {
			throw new NotPermittedException('caller does not own this share');
		}
		$share->setPermissions($permissions);
		$share->setMaxDownloads($maxDownloads);
		$share->setExpiresAt($expiresAt);
		$share = $this->mapper->update($share);

		if (!$share->getEmailSent() && $this->mailService->sendInvitation($share, $fileName, $sendEmail)) {
			$share->setEmailSent(true);
			$share = $this->mapper->update($share);
		}

		return $share;
	}

	/**
	 * revokeShare hard-deletes a share owned by $ownerUid (the row is removed, so no
	 * PII lingers) and records it in the owner's native activity stream. $fileName
	 * names the node in the stream subject; an empty value still logs the revocation.
	 *
	 * @throws DoesNotExistException when the share does not exist
	 * @throws NotPermittedException when $ownerUid is not the share owner
	 */
	public function revokeShare(int $shareId, string $ownerUid, string $fileName = ''): void {
		$share = $this->mapper->find($shareId);
		if ($share->getOwnerUid() !== $ownerUid) {
			throw new NotPermittedException('caller does not own this share');
		}
		$this->mapper->delete($share);

		$this->publishShareActivity(Provider::SUBJECT_UNSHARED_SELF, $share, $fileName);
	}

	/**
	 * findByFileForOwner returns the owner's shares of one node for the file
	 * sidebar. Active shares are always included; expired/exhausted ones are kept
	 * for the retention grace window ($retentionDays measured from the share's
	 * terminal instant) so the owner still sees why a share stopped and can
	 * reactivate it. $retentionDays of 0 means no grace. Owner scoping is enforced
	 * here so a caller can never list another user's shares.
	 *
	 * @return AtriumShare[]
	 */
	public function findByFileForOwner(int $fileId, string $ownerUid, int $retentionDays): array {
		$now = new \DateTime();
		return array_values(array_filter(
			$this->mapper->findByFileId($fileId),
			static function (AtriumShare $share) use ($ownerUid, $retentionDays, $now): bool {
				if ($share->getOwnerUid() !== $ownerUid) {
					return false;
				}
				if ($share->isActive()) {
					return true;
				}
				$reference = $share->statusReferenceTime();
				if ($reference === null) {
					return false;
				}
				$deadline = (clone $reference)->modify("+{$retentionDays} days");
				return $now <= $deadline;
			},
		));
	}

	/**
	 * findActiveByOwner returns the owner's active shares across all their files,
	 * newest first (backing the shares overview view). It stays strictly
	 * active-only — grace is a file-sidebar affordance.
	 *
	 * @return AtriumShare[]
	 */
	public function findActiveByOwner(string $ownerUid): array {
		return array_values(array_filter(
			$this->mapper->findByOwner($ownerUid),
			static fn(AtriumShare $share): bool => $share->isActive(),
		));
	}

	/**
	 * findByRecipientEmail returns only the recipient's active shares. Filtering is
	 * server-side because the plugin is the authorization instance.
	 *
	 * @return AtriumShare[]
	 */
	public function findByRecipientEmail(string $email): array {
		return array_values(array_filter(
			$this->mapper->findByRecipientEmail(EmailCanonicalizer::canonical($email)),
			static fn(AtriumShare $share): bool => $share->isActive(),
		));
	}

	/**
	 * incrementDownloadCount atomically counts a download and enforces the cap in a
	 * single guarded UPDATE that only matches while the limit is not yet reached, so
	 * parallel requests cannot exceed it (no read-modify-write TOCTOU). It also
	 * stamps last_download_at, which for the cap-reaching download is exactly the
	 * exhaustion instant the retention deadline is measured from. Must be called
	 * before the file stream is served.
	 *
	 * @throws DownloadLimitReachedException when the cap is (already) reached
	 */
	public function incrementDownloadCount(int $shareId): void {
		$affected = $this->db->executeStatement(
			'UPDATE `*PREFIX*atrium_shares` SET `download_count` = `download_count` + 1, `last_download_at` = ? '
			. 'WHERE `id` = ? AND (`max_downloads` IS NULL OR `download_count` < `max_downloads`)',
			[(new \DateTime())->format('Y-m-d H:i:s'), $shareId],
		);
		if ($affected === 0) {
			throw new DownloadLimitReachedException('download limit reached for share ' . $shareId);
		}
	}

	/**
	 * @throws DoesNotExistException when no share has the given id
	 */
	public function getById(int $shareId): AtriumShare {
		return $this->mapper->find($shareId);
	}

	/**
	 * @throws DoesNotExistException when no share has the given token
	 */
	public function getByToken(string $token): AtriumShare {
		return $this->mapper->findByToken($token);
	}
}
