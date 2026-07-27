<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Controller;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * AdminSettingsController is the JSON API the admin settings Vue form calls to
 * persist configuration. Every method is gated by #[AuthorizedAdminSetting], so
 * only full (or delegated) admins reach it. It is a plain AppFramework controller
 * (not the sidebar's OCSController), behind Nextcloud's login + CSRF gate.
 */
class AdminSettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private readonly AdminConfigService $configService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Only non-null fields are applied, so the form can send a partial update; a
	 * rejected value maps to 400 with the reason.
	 *
	 * @param int[]|null $allowedModes
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function update(
		?string $corePublicKey = null,
		?string $portalUrl = null,
		?bool $emailEnabled = null,
		?bool $emailOptOutAllowed = null,
		?array $allowedModes = null,
		?int $maxShareDurationDays = null,
		?int $retentionDays = null,
		?string $whitelabelName = null,
	): DataResponse {
		try {
			// Only touch the trust key when it actually changes, so a plain save of
			// other fields does not re-log a key rotation that never happened.
			if ($corePublicKey !== null && trim($corePublicKey) !== $this->configService->getPublicKey()) {
				$this->configService->setPublicKey($corePublicKey);
			}
			if ($portalUrl !== null) {
				$this->configService->setPortalUrl($portalUrl);
			}
			if ($emailEnabled !== null) {
				$this->configService->setEmailEnabled($emailEnabled);
			}
			if ($emailOptOutAllowed !== null) {
				$this->configService->setEmailOptOutAllowed($emailOptOutAllowed);
			}
			if ($allowedModes !== null) {
				$this->configService->setAllowedModes($allowedModes);
			}
			if ($maxShareDurationDays !== null) {
				$this->configService->setMaxShareDurationDays($maxShareDurationDays);
			}
			if ($retentionDays !== null) {
				$this->configService->setRetentionDays($retentionDays);
			}
			if ($whitelabelName !== null) {
				$this->configService->setWhitelabelName($whitelabelName);
			}
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse($this->configService->getAll());
	}
}
