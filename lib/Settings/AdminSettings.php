<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Settings;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Settings\IDelegatedSettings;

/**
 * AdminSettings renders the app's admin settings form, seeding the current config
 * into the initial page state so the Vue form paints without a round-trip. It is
 * an IDelegatedSettings so it can be referenced by AuthorizedAdminSetting on the
 * save controller and, optionally, delegated to non-admin groups.
 */
class AdminSettings implements IDelegatedSettings {
	public function __construct(
		private readonly AdminConfigService $configService,
		private readonly IInitialState $initialState,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('adminConfig', $this->configService->getAll());
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}

	/** getName differentiates the setting inside its section; null shows the section name. */
	public function getName(): ?string {
		return null;
	}

	/**
	 * getAuthorizedAppConfig lists the app config keys a delegated admin may change.
	 * The trust key (core_public_key) is intentionally omitted — installing the
	 * core signing key stays a full-admin action.
	 *
	 * @return array<string,string[]>
	 */
	public function getAuthorizedAppConfig(): array {
		return [
			Application::APP_ID => [
				AdminConfigService::KEY_PORTAL_URL,
				AdminConfigService::KEY_EMAIL_ENABLED,
				AdminConfigService::KEY_EMAIL_OPT_OUT_ALLOWED,
				AdminConfigService::KEY_ALLOWED_MODES,
				AdminConfigService::KEY_MAX_SHARE_DURATION_DAYS,
				AdminConfigService::KEY_RETENTION_DAYS,
				AdminConfigService::KEY_WHITELABEL_NAME,
			],
		];
	}
}
