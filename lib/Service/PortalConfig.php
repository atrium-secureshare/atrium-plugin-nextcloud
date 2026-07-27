<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IURLGenerator;

/**
 * PortalConfig resolves the Atrium portal base URL from app config
 * (`atrium_secureshare portal_url`), falling back to this instance's base URL.
 * Shared by the invitation mail and the sidebar API so the link the owner copies
 * and the link the recipient is mailed never diverge.
 */
final class PortalConfig {
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getUrl(): string {
		$configured = trim($this->appConfig->getValueString(Application::APP_ID, 'portal_url', ''));
		return $configured !== '' ? $configured : $this->urlGenerator->getBaseUrl();
	}
}
