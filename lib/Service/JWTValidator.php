<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use DomainException;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use InvalidArgumentException;
use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Exception\InvalidTokenException;
use OCA\AtriumSecureShare\Exception\TrustNotConfiguredException;
use OCP\IAppConfig;
use UnexpectedValueException;

/**
 * JWTValidator verifies the per-request ES256 tokens the Atrium core signs. It
 * only ever decodes with an ES256 key, so HS256/none downgrade attacks are
 * structurally impossible (firebase/php-jwt rejects any token whose header
 * algorithm does not match the pinned one). This layer checks the signature and
 * structural claims only; per-share authorization is the middleware's job.
 */
class JWTValidator {
	private const ALGORITHM = 'ES256';
	private const ISSUER = 'atrium-core';
	private const AUDIENCE = 'atrium-plugin-nextcloud';
	private const CONFIG_KEY = 'core_public_key';

	/** Clock-skew tolerance in seconds for exp/iat/nbf checks. */
	private const LEEWAY = 5;

	/**
	 * Maximum accepted remaining validity in seconds. The core mints 30s tokens;
	 * anything valid much longer was not minted by a well-behaved core and would
	 * extend the replay window (critical if the key ever leaks).
	 */
	private const MAX_TOKEN_TTL = 60;

	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	/**
	 * Validate the compact JWT and return its claims as an associative array.
	 *
	 * @throws TrustNotConfiguredException when no core public key is installed.
	 * @throws InvalidTokenException when the token fails signature or claim checks.
	 */
	public function validate(string $token): array {
		$publicKey = $this->publicKey();

		JWT::$leeway = self::LEEWAY;
		try {
			// Pinning the Key to ES256 is the algorithm allow-list: a token with
			// alg=HS256, none or anything else is rejected here.
			$decoded = JWT::decode($token, new Key($publicKey, self::ALGORITHM));
		} catch (SignatureInvalidException $e) {
			throw new InvalidTokenException('invalid_signature', 'signature verification failed');
		} catch (ExpiredException $e) {
			throw new InvalidTokenException('token_expired', 'token has expired');
		} catch (BeforeValidException $e) {
			throw new InvalidTokenException('token_not_yet_valid', 'token is not yet valid');
		} catch (UnexpectedValueException | DomainException | InvalidArgumentException $e) {
			// Malformed token, disallowed algorithm header, or bad key material.
			throw new InvalidTokenException('invalid_token', 'token could not be decoded');
		}

		$claims = (array)$decoded;
		if (($claims['iss'] ?? null) !== self::ISSUER) {
			throw new InvalidTokenException('invalid_issuer', 'unexpected issuer');
		}
		if (!$this->audienceMatches($claims['aud'] ?? null)) {
			throw new InvalidTokenException('invalid_audience', 'unexpected audience');
		}
		// firebase/php-jwt validates exp/iat only when present; a token without
		// exp would be valid forever. Require both, and cap the remaining
		// lifetime so an over-long token is rejected even with a valid signature.
		if (!isset($claims['exp']) || !is_int($claims['exp'])) {
			throw new InvalidTokenException('missing_exp', 'token must contain an integer exp claim');
		}
		if (!isset($claims['iat']) || !is_int($claims['iat'])) {
			throw new InvalidTokenException('missing_iat', 'token must contain an integer iat claim');
		}
		if ($claims['exp'] - time() > self::MAX_TOKEN_TTL + self::LEEWAY) {
			throw new InvalidTokenException('ttl_exceeded', 'token validity period exceeds the maximum');
		}
		return $claims;
	}

	/**
	 * publicKey returns the configured core public key PEM, or throws when it is
	 * missing/empty so the trust relationship is treated as unconfigured.
	 */
	private function publicKey(): string {
		$key = $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, '');
		if (trim($key) === '') {
			throw new TrustNotConfiguredException();
		}
		return $key;
	}

	/**
	 * audienceMatches accepts the audience whether the token encodes it as a
	 * string or as a list (both are valid per RFC 7519).
	 */
	private function audienceMatches(mixed $aud): bool {
		if (is_string($aud)) {
			return $aud === self::AUDIENCE;
		}
		if (is_array($aud)) {
			return in_array(self::AUDIENCE, $aud, true);
		}
		return false;
	}
}
