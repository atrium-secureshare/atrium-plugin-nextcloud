<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

/**
 * NullShareLookup resolves nothing, so share-scoped requests are rejected with
 * 404 — fail-closed. It is the reference implementation used by the negative
 * test suite.
 */
final class NullShareLookup implements ShareLookup {
	public function find(string $shareId): ?ShareInfo {
		return null;
	}
}
