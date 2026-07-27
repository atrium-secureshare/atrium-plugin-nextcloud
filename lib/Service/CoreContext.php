<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

/**
 * CoreContext carries the validated JWT claims from the auth middleware to the
 * controller within a single request. Registered as a shared service so both see
 * the same instance, and controllers read the identity/scope here instead of
 * re-parsing the token.
 */
final class CoreContext {
	/** @var array<string,mixed> */
	private array $claims = [];

	/** @param array<string,mixed> $claims */
	public function setClaims(array $claims): void {
		$this->claims = $claims;
	}

	/** @return array<string,mixed> */
	public function getClaims(): array {
		return $this->claims;
	}

	public function getEmail(): ?string {
		$email = $this->claims['email'] ?? null;
		return is_string($email) ? $email : null;
	}

	public function getShareId(): ?string {
		$shareId = $this->claims['share_id'] ?? null;
		return is_string($shareId) ? $shareId : null;
	}
}
