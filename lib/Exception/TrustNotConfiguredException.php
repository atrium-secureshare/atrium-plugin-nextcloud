<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Exception;

/**
 * TrustNotConfiguredException signals that no core public key is installed in
 * the app settings, so the trust relationship cannot be verified. It maps to
 * 403 trust_not_configured, which the core turns into actionable setup guidance.
 */
class TrustNotConfiguredException extends CoreAuthException {
	public function __construct(string $message = 'core public key not configured') {
		parent::__construct(403, 'trust_not_configured', $message);
	}
}
