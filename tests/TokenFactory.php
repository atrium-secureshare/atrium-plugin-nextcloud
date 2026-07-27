<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests;

use Firebase\JWT\JWT;

final class TokenFactory {
	public readonly string $privateKeyPem;
	public readonly string $publicKeyPem;

	public function __construct() {
		[$this->privateKeyPem, $this->publicKeyPem] = self::generateKeypair();
	}

	/** @return array{0:string,1:string} private PEM, public PEM */
	public static function generateKeypair(): array {
		$res = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		]);
		openssl_pkey_export($res, $privatePem);
		$publicPem = openssl_pkey_get_details($res)['key'];
		return [$privatePem, $publicPem];
	}

	public function validClaims(array $overrides = []): array {
		$now = time();
		return array_merge([
			'iss' => 'atrium-core',
			'aud' => 'atrium-plugin-nextcloud',
			'iat' => $now,
			'exp' => $now + 30,
			'action' => 'healthcheck',
		], $overrides);
	}

	public function valid(array $overrides = []): string {
		return JWT::encode($this->validClaims($overrides), $this->privateKeyPem, 'ES256');
	}

	public function withoutClaim(string $claim): string {
		$claims = $this->validClaims();
		unset($claims[$claim]);
		return JWT::encode($claims, $this->privateKeyPem, 'ES256');
	}

	public function signedWith(string $foreignPrivatePem, array $overrides = []): string {
		return JWT::encode($this->validClaims($overrides), $foreignPrivatePem, 'ES256');
	}

	/**
	 * hs256Downgrade mints an HS256 token using the PUBLIC key PEM as the HMAC
	 * secret — the classic downgrade attack against servers that pass a public
	 * key to a verifier that also accepts HS256.
	 */
	public function hs256Downgrade(array $overrides = []): string {
		return JWT::encode($this->validClaims($overrides), $this->publicKeyPem, 'HS256');
	}

	public function none(array $overrides = []): string {
		$header = self::segment(['typ' => 'JWT', 'alg' => 'none']);
		$payload = self::segment($this->validClaims($overrides));
		return $header . '.' . $payload . '.';
	}

	/**
	 * tamperedPayload mints a valid token, then rewrites the payload while
	 * keeping the original signature — signature verification must fail.
	 */
	public function tamperedPayload(array $overrides = []): string {
		$token = $this->valid();
		[$header, , $signature] = explode('.', $token);
		$payload = self::segment($this->validClaims($overrides + ['email' => 'attacker@evil.example']));
		return $header . '.' . $payload . '.' . $signature;
	}

	private static function segment(array $data): string {
		return rtrim(strtr(base64_encode((string)json_encode($data)), '+/', '-_'), '=');
	}
}
