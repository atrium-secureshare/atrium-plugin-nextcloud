<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Controller;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * StatusController is the public install probe: it verifies the app is installed
 * and reachable, revealing only the app id and a static "ok".
 */
class StatusController extends Controller {
	public function __construct(IRequest $request) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		return new JSONResponse(
			[
				'app' => Application::APP_ID,
				'status' => 'ok',
			],
			Http::STATUS_OK,
		);
	}
}
