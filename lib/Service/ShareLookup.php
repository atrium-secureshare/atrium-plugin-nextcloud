<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

/**
 * ShareLookup is the narrow contract the trust boundary uses to resolve a share
 * id to the information it needs (PersistedShareLookup is the bound
 * implementation).
 */
interface ShareLookup {
	/**
	 * find returns the share for $shareId, or null when no such share is
	 * accessible. Implementations must not distinguish "never existed" from
	 * "expired" — both are simply null (no oracle).
	 */
	public function find(string $shareId): ?ShareInfo;
}
