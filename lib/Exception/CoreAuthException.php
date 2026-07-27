<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Exception;

use RuntimeException;

/**
 * CoreAuthException is the base for all trust-boundary rejections. It carries
 * the HTTP status and a machine-readable error code the middleware returns to
 * the core; the human message stays server-side and is never sent to clients.
 */
class CoreAuthException extends RuntimeException {
	public function __construct(
		private readonly int $httpStatus,
		private readonly string $errorCode,
		string $message = '',
	) {
		parent::__construct($message !== '' ? $message : $errorCode);
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	public function getErrorCode(): string {
		return $this->errorCode;
	}
}
