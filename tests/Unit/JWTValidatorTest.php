<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Exception\InvalidTokenException;
use OCA\AtriumSecureShare\Exception\TrustNotConfiguredException;
use OCA\AtriumSecureShare\Service\JWTValidator;
use OCA\AtriumSecureShare\Tests\TokenFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

final class JWTValidatorTest extends TestCase {
	private TokenFactory $tokens;

	protected function setUp(): void {
		$this->tokens = new TokenFactory();
	}

	private function validatorWithKey(string $publicKey): JWTValidator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($publicKey);
		return new JWTValidator($appConfig);
	}

	private function validator(): JWTValidator {
		return $this->validatorWithKey($this->tokens->publicKeyPem);
	}

	public function testValidTokenIsAccepted(): void {
		$claims = $this->validator()->validate($this->tokens->valid(['action' => 'download', 'share_id' => 's1']));
		$this->assertSame('atrium-core', $claims['iss']);
		$this->assertSame('download', $claims['action']);
		$this->assertSame('s1', $claims['share_id']);
	}

	public function testMissingPublicKeyIsTrustNotConfigured(): void {
		$this->expectException(TrustNotConfiguredException::class);
		$this->validatorWithKey('')->validate($this->tokens->valid());
	}

	/**
	 * @dataProvider manipulatedTokens
	 */
	public function testManipulatedTokensAreRejected(string $case, string $expectedErrorCode): void {
		try {
			$this->validator()->validate($this->tokenFor($case));
			self::fail('expected the token to be rejected with ' . $expectedErrorCode);
		} catch (InvalidTokenException $e) {
			self::assertSame($expectedErrorCode, $e->getErrorCode());
		}
	}

	public static function manipulatedTokens(): array {
		return [
			'HS256 downgrade' => ['hs256', 'invalid_token'],
			'alg none' => ['none', 'invalid_token'],
			'expired beyond leeway' => ['expired', 'token_expired'],
			'iat in the future' => ['future', 'token_not_yet_valid'],
			'wrong issuer' => ['bad_iss', 'invalid_issuer'],
			'wrong audience' => ['bad_aud', 'invalid_audience'],
			'foreign signing key' => ['foreign', 'invalid_signature'],
			'tampered payload' => ['tampered', 'invalid_signature'],
			'garbage' => ['garbage', 'invalid_token'],
			'missing exp claim' => ['missing_exp', 'missing_exp'],
			'missing iat claim' => ['missing_iat', 'missing_iat'],
			'ttl exceeded' => ['ttl_exceeded', 'ttl_exceeded'],
		];
	}

	private function tokenFor(string $case): string {
		return match ($case) {
			'hs256' => $this->tokens->hs256Downgrade(),
			'none' => $this->tokens->none(),
			'expired' => $this->tokens->valid(['iat' => time() - 120, 'exp' => time() - 60]),
			'future' => $this->tokens->valid(['iat' => time() + 120, 'exp' => time() + 180]),
			'bad_iss' => $this->tokens->valid(['iss' => 'evil']),
			'bad_aud' => $this->tokens->valid(['aud' => 'someone-else']),
			'foreign' => $this->tokens->signedWith(TokenFactory::generateKeypair()[0]),
			'tampered' => $this->tokens->tamperedPayload(),
			'garbage' => 'not.a.jwt',
			'missing_exp' => $this->tokens->withoutClaim('exp'),
			'missing_iat' => $this->tokens->withoutClaim('iat'),
			'ttl_exceeded' => $this->tokens->valid(['exp' => time() + 3600]),
			default => throw new \InvalidArgumentException($case),
		};
	}
}
