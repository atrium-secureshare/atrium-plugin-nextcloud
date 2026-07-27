<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * PersistedShareLookup is the persistence-backed ShareLookup the trust boundary
 * uses: it resolves the token the core carries as share_id and projects the
 * stored share onto the minimal ShareInfo the middleware needs. A missing share
 * resolves to null (no oracle).
 */
final class PersistedShareLookup implements ShareLookup {
	public function __construct(
		private readonly AtriumShareMapper $mapper,
	) {
	}

	public function find(string $shareId): ?ShareInfo {
		try {
			$share = $this->mapper->findByToken($shareId);
		} catch (DoesNotExistException | MultipleObjectsReturnedException) {
			return null;
		}
		return new ShareInfo(
			$share->getToken(),
			$share->getRecipientEmail(),
			$share->isExpired(),
		);
	}
}
