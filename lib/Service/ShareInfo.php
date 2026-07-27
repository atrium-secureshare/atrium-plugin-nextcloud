<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

/**
 * ShareInfo is the minimal view of a share the trust boundary needs to make an
 * access decision; the middleware depends only on this shape.
 */
final class ShareInfo {
	public function __construct(
		public readonly string $id,
		public readonly string $recipientEmail,
		public readonly bool $expired,
	) {
	}

	/**
	 * isAccessible reports whether the share may still be acted on. Revocation is a
	 * hard delete, so a revoked share is never resolved here; expiry is the only
	 * remaining reason a found share is inaccessible.
	 */
	public function isAccessible(): bool {
		return !$this->expired;
	}
}
