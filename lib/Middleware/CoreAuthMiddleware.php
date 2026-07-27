<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Middleware;

use Exception;
use OCA\AtriumSecureShare\Controller\CoreApiController;
use OCA\AtriumSecureShare\Exception\CoreAuthException;
use OCA\AtriumSecureShare\Service\CoreContext;
use OCA\AtriumSecureShare\Service\EmailCanonicalizer;
use OCA\AtriumSecureShare\Service\JWTValidator;
use OCA\AtriumSecureShare\Service\ShareLookup;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * CoreAuthMiddleware enforces the core<->plugin trust boundary in front of every
 * CoreApiController: it authenticates the signed ES256 token, re-validates the
 * share server-side (existence, expiry, recipient binding) and only then lets the
 * controller run. Failures map to JSON responses exposing a machine-readable code
 * and nothing else.
 */
class CoreAuthMiddleware extends Middleware {
	public function __construct(
		private readonly IRequest $request,
		private readonly JWTValidator $validator,
		private readonly ShareLookup $shareLookup,
		private readonly CoreContext $context,
		private readonly LoggerInterface $logger,
	) {
	}

	public function beforeController(Controller $controller, string $methodName): void {
		if (!$controller instanceof CoreApiController) {
			return;
		}

		$claims = $this->authenticate();
		$this->authorize($controller, $methodName, $claims);
		$this->context->setClaims($claims);
	}

	public function afterException(Controller $controller, string $methodName, Exception $exception): Response {
		if ($controller instanceof CoreApiController && $exception instanceof CoreAuthException) {
			return new JSONResponse(
				['error' => $exception->getErrorCode()],
				$exception->getHttpStatus(),
			);
		}
		throw $exception;
	}

	/**
	 * authenticate extracts and validates the bearer token, returning its claims.
	 *
	 * @return array<string,mixed>
	 */
	private function authenticate(): array {
		$header = $this->request->getHeader('Authorization');
		if ($header === '' || !str_starts_with($header, 'Bearer ')) {
			throw new CoreAuthException(401, 'missing_token', 'missing or malformed Authorization header');
		}
		return $this->validator->validate(substr($header, 7));
	}

	/**
	 * authorize enforces action/endpoint binding and, for share-scoped tokens,
	 * server-side share re-validation and recipient matching.
	 *
	 * @param array<string,mixed> $claims
	 */
	private function authorize(CoreApiController $controller, string $methodName, array $claims): void {
		$action = is_string($claims['action'] ?? null) ? $claims['action'] : '';
		$required = $controller->requiredAction($methodName);
		// Deny-by-default: a method without an action mapping is not reachable.
		if ($required === null) {
			throw new CoreAuthException(403, 'action_not_allowed', 'method not mapped to any action');
		}
		if ($action !== $required) {
			throw new CoreAuthException(403, 'action_not_allowed', 'token action does not match endpoint');
		}

		$shareId = is_string($claims['share_id'] ?? null) ? $claims['share_id'] : '';
		if ($shareId !== '') {
			$share = $this->shareLookup->find($shareId);
			// No oracle: non-existent and expired both look the same (404).
			if ($share === null || !$share->isAccessible()) {
				throw new CoreAuthException(404, 'share_not_found', 'share not found or no longer accessible');
			}
			$email = is_string($claims['email'] ?? null) ? $claims['email'] : '';
			if (!$this->emailMatches($share->recipientEmail, $email)) {
				throw new CoreAuthException(403, 'email_mismatch', 'token email does not match share recipient');
			}
		}

		// No token material and no recipient email are logged here.
		$this->logger->info('atrium core-facing api access', [
			'action' => $action,
			'share_id' => $shareId,
			'ip' => is_string($claims['ip'] ?? null) ? $claims['ip'] : '',
			'xff' => is_string($claims['xff'] ?? null) ? $claims['xff'] : '',
		]);
	}

	/**
	 * emailMatches compares recipient addresses through the shared identity rule
	 * (EmailCanonicalizer), so authorization decides "same recipient" exactly as
	 * storage, listing and own-visibility do.
	 */
	private function emailMatches(string $recipient, string $claimed): bool {
		if ($claimed === '') {
			return false;
		}
		return EmailCanonicalizer::canonical($recipient) === EmailCanonicalizer::canonical($claimed);
	}
}
