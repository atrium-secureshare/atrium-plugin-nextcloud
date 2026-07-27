<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Service\EmailCanonicalizer;
use PHPUnit\Framework\TestCase;

final class EmailCanonicalizerTest extends TestCase {
	/**
	 * @dataProvider cases
	 */
	public function testCanonical(string $input, string $expected): void {
		self::assertSame($expected, EmailCanonicalizer::canonical($input));
	}

	public static function cases(): array {
		return [
			'lower-cases and trims' => ['  Bob@Example.COM ', 'bob@example.com'],
			'already canonical is stable' => ['bob@example.com', 'bob@example.com'],
			'empty stays empty' => ['', ''],
			// NFKC compatibility folding: these previously survived a lower-only
			// canonicalization as distinct strings.
			'fullwidth folds to ascii' => ["\u{FF22}\u{FF2F}\u{FF22}@example.com", 'bob@example.com'],
			'ffi ligature expands' => ["o\u{FB03}ce@x.com", 'office@x.com'],
		];
	}

	public function testNfcAndNfdSpellingsAgree(): void {
		// Composed é vs. e + combining acute: the same address in two encodings.
		$nfc = EmailCanonicalizer::canonical("jos\u{00E9}@example.com");
		$nfd = EmailCanonicalizer::canonical("jose\u{0301}@example.com");
		self::assertSame($nfc, $nfd);
	}
}
