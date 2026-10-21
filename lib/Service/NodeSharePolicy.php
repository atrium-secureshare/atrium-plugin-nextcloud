<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\Db\AtriumShare;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

/**
 * NodeSharePolicy answers what a user may actually do with one node, as opposed
 * to what the admin allows in general (AdminConfigService). Both answers come
 * from the node's own Nextcloud permissions, so the plugin never promises more
 * than the storage layer grants:
 *
 * - May the user share this node externally at all? Being able to read a node
 *   does not imply being allowed to pass it on, so Nextcloud's resharing rules
 *   take part in this decision.
 * - Which Atrium modes can they exercise on it? An upload mode on a folder the
 *   sharing user may only read would advertise an upload that Nextcloud's
 *   storage layer then refuses with `403 upload_forbidden`, so it is not on
 *   offer.
 *
 * Several independent restrictions can clear the node's `PERMISSION_SHARE` bit,
 * and the bit alone does not say which one did: the file owner's per-share
 * grant reaches the node through the share's own permissions, while the global
 * "allow resharing" switch, a user excluded from sharing and a mount with
 * sharing disabled are applied on top as a storage wrapper
 * (`SetupManager`'s `sharing_mask`). Telling the owner's decision apart from
 * the admin's therefore needs the share itself, which `ISharedStorage::getShare()`
 * hands over unmasked. That is what makes the permissive option possible: the
 * owner's denial is absolute, the global one is the admin's to reinterpret.
 */
final class NodeSharePolicy {
	/** The folder modes that let the recipient upload (see AtriumShare::canWrite()). */
	private const UPLOAD_MODES = [
		AtriumShare::MODE_WRITE_OWN,
		AtriumShare::MODE_WRITE_ALL,
		AtriumShare::MODE_DROPZONE,
	];

	public function __construct(
		private readonly AdminConfigService $adminConfig,
		private readonly IShareManager $shareManager,
	) {
	}

	/**
	 * canShare tells whether $uid may create an external share of $node. A node
	 * they did not receive through a share is governed by Nextcloud's verdict
	 * alone. For a received one the owner's grant decides first (and is never
	 * overridden), then the admin's resharing option decides how Nextcloud's
	 * global setting applies.
	 */
	public function canShare(Node $node, string $uid): bool {
		// Nextcloud's own verdict, with every restriction already folded in.
		$allowedByNextcloud = ($node->getPermissions() & Constants::PERMISSION_SHARE) !== 0;

		$received = $this->receivedShare($node);
		if ($received === null) {
			return $allowedByNextcloud;
		}
		// The share's own permissions are the file owner's decision, unmasked by
		// anything the admin configured. Missing means "do not pass this on", and
		// no admin option overrides it.
		if (($received->getPermissions() & Constants::PERMISSION_SHARE) === 0) {
			return false;
		}
		return match ($this->adminConfig->getResharingPolicy()) {
			AdminConfigService::RESHARING_STRICT => false,
			// Permissive drops the global resharing restriction, and only that one:
			// a user or mount excluded from sharing stays excluded.
			AdminConfigService::RESHARING_PERMISSIVE => !$this->shareManager->sharingDisabledForUser($uid)
				&& $node->getMountPoint()->getOption('enable_sharing', true),
			default => $allowedByNextcloud,
		};
	}

	/**
	 * availableModes returns the sharing modes the sharing user can actually
	 * exercise on $node, in ascending order. Files are read-only by model; a
	 * folder offers the upload modes only when the user may create files in it.
	 *
	 * @return int[]
	 */
	public function availableModes(Node $node): array {
		if ($node instanceof File) {
			return [AtriumShare::MODE_READ_ONLY];
		}
		if (($node->getPermissions() & Constants::PERMISSION_CREATE) === 0) {
			return [AtriumShare::MODE_READ_ONLY];
		}
		return [AtriumShare::MODE_READ_ONLY, ...self::UPLOAD_MODES];
	}

	/**
	 * receivedShare returns the share $node reached the user through, or null when
	 * the node is not a received share (their own file, a group folder, an external
	 * storage). This is the same seam Nextcloud's own DAV layer uses to report
	 * share permissions. A node whose storage is gone counts as not received: the
	 * masked verdict then decides, which is the stricter of the two answers.
	 */
	private function receivedShare(Node $node): ?IShare {
		try {
			$storage = $node->getStorage();
		} catch (\Throwable) {
			return null;
		}
		if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return null;
		}
		/** @var ISharedStorage $storage */
		return $storage->getShare();
	}
}
