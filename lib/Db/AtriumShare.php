<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Db;

use OCP\AppFramework\Db\Entity;

/**
 * AtriumShare is one identity-bound external share: a Nextcloud node (file_id,
 * owned by owner_uid) exposed to a single recipient email, reachable through the
 * Atrium core via the unguessable token. Access is bounded by expiry and an
 * optional download cap. Revocation is a hard delete, so there is no revoked state
 * to represent here.
 *
 * @method void setToken(string $token)
 * @method string getToken()
 * @method void setFileId(int $fileId)
 * @method int getFileId()
 * @method void setOwnerUid(string $ownerUid)
 * @method string getOwnerUid()
 * @method void setRecipientEmail(string $recipientEmail)
 * @method string getRecipientEmail()
 * @method void setPermissions(int $permissions)
 * @method int getPermissions()
 * @method void setMaxDownloads(?int $maxDownloads)
 * @method ?int getMaxDownloads()
 * @method void setDownloadCount(int $downloadCount)
 * @method int getDownloadCount()
 * @method void setExpiresAt(?\DateTime $expiresAt)
 * @method ?\DateTime getExpiresAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getCreatedAt()
 * @method void setLastDownloadAt(?\DateTime $lastDownloadAt)
 * @method ?\DateTime getLastDownloadAt()
 * @method void setEmailSent(bool $emailSent)
 * @method bool getEmailSent()
 */
class AtriumShare extends Entity {
	/** Retention states, exposed to the owner sidebar (see getStatus). */
	public const STATUS_ACTIVE = 'active';
	public const STATUS_EXPIRED = 'expired';
	public const STATUS_EXHAUSTED = 'exhausted';

	/**
	 * Sharing modes stored in the `permissions` column — a mode enum, NOT a
	 * Nextcloud permission bitmask. The capability helpers below are the single
	 * source of truth for what a mode may do; callers consult them instead of
	 * comparing the raw integer.
	 */
	public const MODE_READ_ONLY = 0;
	public const MODE_WRITE_OWN = 1;
	public const MODE_WRITE_ALL = 2;
	public const MODE_DROPZONE = 3;

	protected ?string $token = null;
	protected ?int $fileId = null;
	protected ?string $ownerUid = null;
	protected ?string $recipientEmail = null;
	protected ?int $permissions = 1;
	protected ?int $maxDownloads = null;
	protected ?int $downloadCount = 0;
	protected ?\DateTime $expiresAt = null;
	protected ?\DateTime $createdAt = null;
	protected ?\DateTime $lastDownloadAt = null;
	protected ?bool $emailSent = false;

	public function __construct() {
		$this->addType('fileId', 'integer');
		$this->addType('permissions', 'integer');
		$this->addType('maxDownloads', 'integer');
		$this->addType('downloadCount', 'integer');
		$this->addType('expiresAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('lastDownloadAt', 'datetime');
		$this->addType('emailSent', 'boolean');
	}

	public function isExpired(): bool {
		return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
	}

	public function isDownloadLimitReached(): bool {
		return $this->maxDownloads !== null && ($this->downloadCount ?? 0) >= $this->maxDownloads;
	}

	/** isActive is true only when the share may still be listed and downloaded. */
	public function isActive(): bool {
		return !$this->isExpired() && !$this->isDownloadLimitReached();
	}

	/**
	 * getStatus is the owner-facing retention state: 'expired', 'exhausted' or
	 * 'active' (expiry takes precedence when both hold). Derived purely from the
	 * share's own fields, so it costs no extra query.
	 */
	public function getStatus(): string {
		if ($this->isExpired()) {
			return self::STATUS_EXPIRED;
		}
		if ($this->isDownloadLimitReached()) {
			return self::STATUS_EXHAUSTED;
		}
		return self::STATUS_ACTIVE;
	}

	/**
	 * statusReferenceTime returns the instant a non-active share entered its
	 * terminal state (expires_at when expired, last_download_at when exhausted),
	 * from which the retention deadline is measured. Null for an active share, and
	 * for an exhausted share with no recorded download instant (then treated as
	 * outside the grace window).
	 */
	public function statusReferenceTime(): ?\DateTime {
		if ($this->isExpired()) {
			return $this->expiresAt;
		}
		if ($this->isDownloadLimitReached()) {
			return $this->lastDownloadAt;
		}
		return null;
	}

	public function getMode(): int {
		return $this->permissions ?? self::MODE_READ_ONLY;
	}

	/** canRead: the recipient may see and download folder contents. */
	public function canRead(): bool {
		$mode = $this->getMode();
		return $mode === self::MODE_READ_ONLY || $mode === self::MODE_WRITE_OWN || $mode === self::MODE_WRITE_ALL;
	}

	/** canReadAll: the recipient sees every file, not only their own uploads. */
	public function canReadAll(): bool {
		$mode = $this->getMode();
		return $mode === self::MODE_READ_ONLY || $mode === self::MODE_WRITE_ALL;
	}

	/** canWrite: the recipient may upload into the folder. */
	public function canWrite(): bool {
		$mode = $this->getMode();
		return $mode === self::MODE_WRITE_OWN || $mode === self::MODE_WRITE_ALL || $mode === self::MODE_DROPZONE;
	}
}
