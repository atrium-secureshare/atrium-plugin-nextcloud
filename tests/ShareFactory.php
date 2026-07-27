<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests;

use OCA\AtriumSecureShare\Db\AtriumShare;

/**
 * Overrides are keyed by AtriumShare property name. Nullable fields (id,
 * maxDownloads, expiresAt, lastDownloadAt) are only set when the key is present,
 * so their entity defaults (null) hold otherwise.
 */
final class ShareFactory {
	/**
	 * @param array<string,mixed> $o field overrides, keyed by AtriumShare property
	 */
	public static function make(array $o = []): AtriumShare {
		$share = new AtriumShare();
		if (array_key_exists('id', $o)) {
			$share->setId($o['id']);
		}
		$share->setToken($o['token'] ?? 'tok');
		$share->setFileId($o['fileId'] ?? 1);
		$share->setOwnerUid($o['ownerUid'] ?? 'alice');
		$share->setRecipientEmail($o['recipientEmail'] ?? 'bob@example.com');
		$share->setPermissions($o['permissions'] ?? AtriumShare::MODE_WRITE_OWN);
		$share->setDownloadCount($o['downloadCount'] ?? 0);
		$share->setCreatedAt($o['createdAt'] ?? new \DateTime());
		$share->setEmailSent($o['emailSent'] ?? false);
		if (array_key_exists('maxDownloads', $o)) {
			$share->setMaxDownloads($o['maxDownloads']);
		}
		if (array_key_exists('expiresAt', $o)) {
			$share->setExpiresAt($o['expiresAt']);
		}
		if (array_key_exists('lastDownloadAt', $o)) {
			$share->setLastDownloadAt($o['lastDownloadAt']);
		}
		return $share;
	}
}
