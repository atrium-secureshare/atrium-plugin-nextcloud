<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

/**
 * EmailCanonicalizer is the single definition of recipient-email identity in the
 * plugin. Every place that stores or compares a recipient address MUST route
 * through it, so "same recipient" means one thing everywhere. The rule matches
 * the core's identity binding (audit.Canonical): NFKC normalization then
 * lower-casing, so NFC/NFD and case variations agree on both sides of the boundary.
 */
final class EmailCanonicalizer {
	/** canonical returns the NFKC-normalized, lower-cased, trimmed address. */
	public static function canonical(string $email): string {
		$email = trim($email);
		// Invalid Unicode fails normalization; falling back to the raw string is
		// fail-closed (it can only cause a mismatch, never a false match).
		$normalized = \Normalizer::normalize($email, \Normalizer::FORM_KC);
		return mb_strtolower($normalized === false ? $email : $normalized);
	}
}
