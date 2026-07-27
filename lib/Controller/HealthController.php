<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Controller;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * HealthController answers the core's signed healthcheck. It is a #[PublicPage],
 * so CoreAuthMiddleware is the only gate: a 200 proves the trust relationship
 * end to end.
 */
class HealthController extends Controller implements CoreApiController {
	public function __construct(
		IRequest $request,
		private readonly IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function requiredAction(string $method): ?string {
		return $method === 'index' ? 'healthcheck' : null;
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'status' => 'ok',
				'app_version' => $this->appManager->getAppVersion(Application::APP_ID),
			],
			Http::STATUS_OK,
		);
	}
}
