<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCP\Files\Node;

/**
 * FileResolver resolves a share's stored (owner_uid, file_id) to the current
 * Nextcloud node. It is the narrow filesystem seam that keeps the controller
 * decoupled from IRootFolder (which cannot be exercised in unit tests).
 */
interface FileResolver {
	/**
	 * resolve returns the node the share points at, or null when it was deleted
	 * or moved out of the owner's reach since the share was created.
	 */
	public function resolve(string $ownerUid, int $fileId): ?Node;
}
