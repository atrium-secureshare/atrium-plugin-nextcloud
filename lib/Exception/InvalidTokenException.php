<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Exception;

/**
 * InvalidTokenException signals a token that failed signature, algorithm or
 * claim validation. It maps to 403 with a machine-readable reason code so the
 * core can distinguish setup problems from tampering without leaking detail.
 */
class InvalidTokenException extends CoreAuthException {
	public function __construct(string $errorCode = 'invalid_token', string $message = '') {
		parent::__construct(403, $errorCode, $message);
	}
}
