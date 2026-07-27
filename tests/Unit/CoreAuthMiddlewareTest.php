<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Controller\CoreApiController;
use OCA\AtriumSecureShare\Exception\CoreAuthException;
use OCA\AtriumSecureShare\Exception\InvalidTokenException;
use OCA\AtriumSecureShare\Middleware\CoreAuthMiddleware;
use OCA\AtriumSecureShare\Service\CoreContext;
use OCA\AtriumSecureShare\Service\JWTValidator;
use OCA\AtriumSecureShare\Service\NullShareLookup;
use OCA\AtriumSecureShare\Service\ShareInfo;
use OCA\AtriumSecureShare\Service\ShareLookup;
use OCA\AtriumSecureShare\Tests\TokenFactory;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FakeApiController extends Controller implements CoreApiController {
	public function __construct(IRequest $request, private readonly ?string $action) {
		parent::__construct('atrium_secureshare', $request);
	}

	public function requiredAction(string $method): ?string {
		return $this->action;
	}
}

/**
 * PlainController is not part of the core-facing API, so the middleware must ignore
 * it entirely.
 */
final class PlainController extends Controller {
}

final class CoreAuthMiddlewareTest extends TestCase {
	private TokenFactory $tokens;
	private CoreContext $context;

	protected function setUp(): void {
		$this->tokens = new TokenFactory();
		$this->context = new CoreContext();
	}

	private function middleware(IRequest $request, ShareLookup $lookup, JWTValidator $validator): CoreAuthMiddleware {
		return new CoreAuthMiddleware(
			$request,
			$validator,
			$lookup,
			$this->context,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function realValidator(): JWTValidator {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn($this->tokens->publicKeyPem);
		return new JWTValidator($appConfig);
	}

	/**
	 * @param array<string,mixed> $claims
	 */
	private function validatorReturning(array $claims): JWTValidator {
		$validator = $this->createMock(JWTValidator::class);
		$validator->method('validate')->willReturn($claims);
		return $validator;
	}

	private function validatorThrowing(\Throwable $e): JWTValidator {
		$validator = $this->createMock(JWTValidator::class);
		$validator->method('validate')->willThrowException($e);
		return $validator;
	}

	private function requestWith(?string $authHeader): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn(string $name): string => $name === 'Authorization' ? ($authHeader ?? '') : '',
		);
		return $request;
	}

	private function fakeLookup(?ShareInfo $share): ShareLookup {
		return new class($share) implements ShareLookup {
			public function __construct(private readonly ?ShareInfo $share) {
			}

			public function find(string $shareId): ?ShareInfo {
				return $this->share;
			}
		};
	}

	/**
	 * dispatch executes beforeController and returns the mapped error response, or
	 * null when the request passed the boundary.
	 */
	private function dispatch(IRequest $request, ShareLookup $lookup, JWTValidator $validator, Controller $controller, string $method = 'index'): ?JSONResponse {
		$mw = $this->middleware($request, $lookup, $validator);
		try {
			$mw->beforeController($controller, $method);
			return null;
		} catch (CoreAuthException $e) {
			return $mw->afterException($controller, $method, $e);
		}
	}

	private function assertRejected(?JSONResponse $response, int $status, string $errorCode): void {
		$this->assertNotNull($response, 'expected the request to be rejected');
		$this->assertSame($status, $response->getStatus());
		$this->assertSame(['error' => $errorCode], $response->getData());
	}

	public function testMissingHeaderRejected(): void {
		$request = $this->requestWith(null);
		$controller = new FakeApiController($request, 'healthcheck');
		$this->assertRejected($this->dispatch($request, new NullShareLookup(), $this->validatorReturning([]), $controller), 401, 'missing_token');
	}

	public function testMalformedHeaderRejected(): void {
		$request = $this->requestWith('Token abc');
		$controller = new FakeApiController($request, 'healthcheck');
		$this->assertRejected($this->dispatch($request, new NullShareLookup(), $this->validatorReturning([]), $controller), 401, 'missing_token');
	}

	public function testInvalidTokenRejectedWith403(): void {
		// A validator rejection must propagate through authenticate() to a 403 JSON
		// body carrying the error code. The crypto that produces it is JWTValidator's
		// job (covered by JWTValidatorTest), so it is mocked to throw here.
		$request = $this->requestWith('Bearer whatever');
		$controller = new FakeApiController($request, 'healthcheck');
		$validator = $this->validatorThrowing(new InvalidTokenException('invalid_signature'));
		$this->assertRejected($this->dispatch($request, new NullShareLookup(), $validator, $controller), 403, 'invalid_signature');
	}

	public function testValidHealthcheckPasses(): void {
		// Integration smoke: the one test driving the real JWTValidator end to end
		// (token extraction + ES256 verification), proving the wiring the other
		// tests mock away.
		$request = $this->requestWith('Bearer ' . $this->tokens->valid(['action' => 'healthcheck']));
		$controller = new FakeApiController($request, 'healthcheck');
		$this->assertNull($this->dispatch($request, new NullShareLookup(), $this->realValidator(), $controller));
		$this->assertSame('healthcheck', $this->context->getClaims()['action']);
	}

	public function testActionMismatchRejected(): void {
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, 'download');
		$validator = $this->validatorReturning(['action' => 'list-shares']);
		$this->assertRejected($this->dispatch($request, new NullShareLookup(), $validator, $controller), 403, 'action_not_allowed');
	}

	public function testUnknownShareReturns404(): void {
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, 'download');
		$validator = $this->validatorReturning(['action' => 'download', 'share_id' => 'ghost', 'email' => 'a@b.c']);
		$this->assertRejected($this->dispatch($request, new NullShareLookup(), $validator, $controller), 404, 'share_not_found');
	}

	public function testInaccessibleShareReturns404(): void {
		// Expiry is the only reason a resolved share is inaccessible now — a revoked
		// share is hard-deleted and never resolves (it 404s as "unknown").
		$share = new ShareInfo('s1', 'a@b.c', true);
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, 'download');
		$validator = $this->validatorReturning(['action' => 'download', 'share_id' => 's1', 'email' => 'a@b.c']);
		$this->assertRejected($this->dispatch($request, $this->fakeLookup($share), $validator, $controller), 404, 'share_not_found');
	}

	public function testEmailMismatchReturns403(): void {
		$share = new ShareInfo('s1', 'owner@example.com', false);
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, 'download');
		$validator = $this->validatorReturning(['action' => 'download', 'share_id' => 's1', 'email' => 'intruder@example.com']);
		$this->assertRejected($this->dispatch($request, $this->fakeLookup($share), $validator, $controller), 403, 'email_mismatch');
	}

	public function testUnmappedMethodIsDeniedByDefault(): void {
		// requiredAction() === null must deny, not skip the action check.
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, null);
		$validator = $this->validatorReturning(['action' => 'healthcheck']);
		$this->assertRejected($this->dispatch($request, new NullShareLookup(), $validator, $controller), 403, 'action_not_allowed');
	}

	public function testMatchingShareAccessPassesAcrossCanonicalization(): void {
		// NFD spelling in the share vs. NFC in the token: the same address through
		// EmailCanonicalizer (NFKC + lower case), so the recipient binding matches.
		// Case- and NFKC-folding themselves are covered by EmailCanonicalizerTest;
		// this only proves the middleware wires the canonicalizer into the compare.
		$share = new ShareInfo('s1', "jose\u{0301}@Example.COM", false);
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, 'download');
		$validator = $this->validatorReturning(['action' => 'download', 'share_id' => 's1', 'email' => "jos\u{00E9}@example.com"]);
		$this->assertNull($this->dispatch($request, $this->fakeLookup($share), $validator, $controller));
	}

	public function testEmptyEmailClaimNeverMatches(): void {
		// A share-scoped token with an empty (or absent) email claim must not
		// authorize any share: the empty claim can never match a recipient.
		$share = new ShareInfo('s1', 'owner@example.com', false);
		$request = $this->requestWith('Bearer t');
		$controller = new FakeApiController($request, 'download');
		$validator = $this->validatorReturning(['action' => 'download', 'share_id' => 's1']); // no email claim
		$this->assertRejected($this->dispatch($request, $this->fakeLookup($share), $validator, $controller), 403, 'email_mismatch');
	}

	public function testNonApiControllerIsIgnored(): void {
		// No token at all, but a non-core-facing controller must pass untouched.
		$request = $this->requestWith(null);
		$controller = new PlainController('atrium_secureshare', $request);
		$this->assertNull($this->dispatch($request, new NullShareLookup(), $this->validatorReturning([]), $controller));
	}
}
