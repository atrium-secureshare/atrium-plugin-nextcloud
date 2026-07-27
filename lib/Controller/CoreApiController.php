<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Controller;

/**
 * CoreApiController marks the controllers that make up the Atrium core-facing
 * API (/api/v1/*). Only these are guarded by CoreAuthMiddleware, so the trust
 * boundary is enforced by type rather than URL matching and cannot be forgotten
 * when a new core-facing endpoint is added.
 */
interface CoreApiController {
	/**
	 * requiredAction returns the JWT "action" claim required to invoke $method.
	 * Returning null DENIES access (deny-by-default), binding a token minted for
	 * one purpose to exactly the matching endpoint.
	 */
	public function requiredAction(string $method): ?string;
}
