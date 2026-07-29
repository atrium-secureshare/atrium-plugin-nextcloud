<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * AdminConfigService is the single source of truth for the app's admin
 * configuration: it owns reading, typed defaults, validation and persistence so
 * every consumer reads the same values through one place. The `core_public_key`
 * and `portal_url` keys predate this service (JWTValidator and PortalConfig read
 * them directly); the constants here name the same keys.
 */
class AdminConfigService {
	/** Core signing key (PEM) the plugin verifies tokens against — see JWTValidator. */
	public const KEY_CORE_PUBLIC_KEY = 'core_public_key';
	/** Atrium portal base URL — see PortalConfig (which adds the base-URL fallback). */
	public const KEY_PORTAL_URL = 'portal_url';
	/** Whether invitation emails are sent at all (global master switch). */
	public const KEY_EMAIL_ENABLED = 'email_enabled';
	/** Whether an owner may opt out of notifying the recipient on a per-share basis. */
	public const KEY_EMAIL_OPT_OUT_ALLOWED = 'email_opt_out_allowed';
	/** JSON array of allowed sharing modes (subset of 0..3). */
	public const KEY_ALLOWED_MODES = 'allowed_modes';
	/** Maximum share duration in days; 0 means unlimited. */
	public const KEY_MAX_SHARE_DURATION_DAYS = 'max_share_duration_days';
	/**
	 * Days an expired/exhausted share stays visible to its owner (file sidebar)
	 * before the cleanup job hard-deletes it; 0 means no grace. Default 7.
	 */
	public const KEY_RETENTION_DAYS = 'retention_days';
	/** App-local brand name shown in the sidebar, shares view and activity. */
	public const KEY_WHITELABEL_NAME = 'whitelabel_name';

	/** Default retention grace window in days when the admin has set none. */
	private const DEFAULT_RETENTION_DAYS = 7;

	/** All sharing modes, the default when the admin has set no restriction. */
	private const ALL_MODES = [
		AtriumShare::MODE_READ_ONLY,
		AtriumShare::MODE_WRITE_OWN,
		AtriumShare::MODE_WRITE_ALL,
		AtriumShare::MODE_DROPZONE,
	];

	/** Neutral default brand name when the admin has set none. */
	private const DEFAULT_WHITELABEL_NAME = 'Atrium';

	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * getAll returns the full admin configuration for the settings UI, including
	 * the trust key and its fingerprint. Admin-only — see getPolicy() for the
	 * non-admin subset.
	 *
	 * @return array<string,mixed>
	 */
	public function getAll(): array {
		$publicKey = $this->getPublicKey();
		return [
			'corePublicKey' => $publicKey,
			'keyFingerprint' => $publicKey !== '' ? $this->computeFingerprint($publicKey) : null,
			'portalUrl' => $this->getPortalUrl(),
			'emailEnabled' => $this->isEmailEnabled(),
			'emailOptOutAllowed' => $this->isEmailOptOutAllowed(),
			'allowedModes' => $this->getAllowedModes(),
			'maxShareDurationDays' => $this->getMaxShareDurationDays(),
			'retentionDays' => $this->getRetentionDays(),
			'whitelabelName' => $this->getWhitelabelName(),
		];
	}

	/**
	 * getPolicy returns only the values the sidebar needs. It deliberately omits
	 * the trust key, so a non-admin user never learns the core key material.
	 *
	 * @return array<string,mixed>
	 */
	public function getPolicy(): array {
		return [
			'emailEnabled' => $this->isEmailEnabled(),
			'emailOptOutAllowed' => $this->isEmailOptOutAllowed(),
			'allowedModes' => $this->getAllowedModes(),
			'maxShareDurationDays' => $this->getMaxShareDurationDays(),
			'whitelabelName' => $this->getWhitelabelName(),
		];
	}

	public function getPublicKey(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::KEY_CORE_PUBLIC_KEY, '');
	}

	/**
	 * setPublicKey validates and stores the core signing key. An empty value clears
	 * it (trust becomes unconfigured); any non-empty value must be a valid ES256
	 * (P-256) public key in PEM form.
	 *
	 * @throws \InvalidArgumentException when the PEM is not a P-256 public key
	 */
	public function setPublicKey(string $pem): void {
		$pem = trim($pem);
		$this->validateES256PublicKey($pem);
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_CORE_PUBLIC_KEY, $pem);
		$this->logger->info('atrium core public key updated', [
			'fingerprint' => $pem !== '' ? $this->computeFingerprint($pem) : null,
		]);
	}

	public function getPortalUrl(): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, self::KEY_PORTAL_URL, ''));
	}

	public function setPortalUrl(string $url): void {
		$url = trim($url);
		if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new \InvalidArgumentException('portal url must be a valid URL');
		}
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_PORTAL_URL, $url);
	}

	public function isEmailEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_EMAIL_ENABLED, true);
	}

	public function setEmailEnabled(bool $enabled): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_EMAIL_ENABLED, $enabled);
	}

	public function isEmailOptOutAllowed(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_EMAIL_OPT_OUT_ALLOWED, true);
	}

	public function setEmailOptOutAllowed(bool $allowed): void {
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_EMAIL_OPT_OUT_ALLOWED, $allowed);
	}

	/**
	 * getAllowedModes returns the sharing modes the policy permits, defaulting to
	 * all four. Stored values are sanitised to the known modes so a malformed
	 * config can never widen the policy.
	 *
	 * @return int[]
	 */
	public function getAllowedModes(): array {
		$stored = $this->appConfig->getValueArray(Application::APP_ID, self::KEY_ALLOWED_MODES, self::ALL_MODES);
		$modes = array_values(array_unique(array_filter(
			array_map(static fn($m): int => (int)$m, $stored),
			static fn(int $m): bool => in_array($m, self::ALL_MODES, true),
		)));
		sort($modes);
		return $modes === [] ? self::ALL_MODES : $modes;
	}

	/**
	 * setAllowedModes stores the permitted modes, rejecting an empty set (which
	 * would forbid every share) and any value outside 0..3.
	 *
	 * @param int[] $modes
	 * @throws \InvalidArgumentException on an empty set or an unknown mode
	 */
	public function setAllowedModes(array $modes): void {
		$modes = array_values(array_unique(array_map(static fn($m): int => (int)$m, $modes)));
		if ($modes === []) {
			throw new \InvalidArgumentException('at least one sharing mode must be allowed');
		}
		foreach ($modes as $mode) {
			if (!in_array($mode, self::ALL_MODES, true)) {
				throw new \InvalidArgumentException('unknown sharing mode: ' . $mode);
			}
		}
		sort($modes);
		$this->appConfig->setValueArray(Application::APP_ID, self::KEY_ALLOWED_MODES, $modes);
	}

	/** getMaxShareDurationDays returns the cap in days, or null when unlimited. */
	public function getMaxShareDurationDays(): ?int {
		$days = $this->appConfig->getValueInt(Application::APP_ID, self::KEY_MAX_SHARE_DURATION_DAYS, 0);
		return $days > 0 ? $days : null;
	}

	/**
	 * setMaxShareDurationDays stores the cap; null or 0 means unlimited. A negative
	 * value is rejected.
	 *
	 * @throws \InvalidArgumentException on a negative value
	 */
	public function setMaxShareDurationDays(?int $days): void {
		$days ??= 0;
		if ($days < 0) {
			throw new \InvalidArgumentException('maximum share duration must not be negative');
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_MAX_SHARE_DURATION_DAYS, $days);
	}

	/**
	 * getRetentionDays returns the grace window (>= 0) an expired/exhausted share
	 * stays visible to its owner before the cleanup job removes it; defaults to 7.
	 * A negative stored value is clamped to 0 (no grace) so the cleanup can never
	 * be pushed into the past.
	 */
	public function getRetentionDays(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, self::KEY_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS));
	}

	/**
	 * setRetentionDays stores the grace window; 0 means no grace. A negative value
	 * is rejected.
	 *
	 * @throws \InvalidArgumentException on a negative value
	 */
	public function setRetentionDays(int $days): void {
		if ($days < 0) {
			throw new \InvalidArgumentException('retention days must not be negative');
		}
		$this->appConfig->setValueInt(Application::APP_ID, self::KEY_RETENTION_DAYS, $days);
	}

	public function getWhitelabelName(): string {
		$name = trim($this->appConfig->getValueString(Application::APP_ID, self::KEY_WHITELABEL_NAME, ''));
		return $name !== '' ? $name : self::DEFAULT_WHITELABEL_NAME;
	}

	public function setWhitelabelName(string $name): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_WHITELABEL_NAME, trim($name));
	}

	/**
	 * computeFingerprint returns a stable SHA-256 fingerprint of the DER-encoded
	 * public key, matching the `SHA256:base64` shape the core logs, so an admin can
	 * compare the two by eye. Returns '' for an unreadable key.
	 */
	public function computeFingerprint(string $pem): string {
		$key = openssl_pkey_get_public($pem);
		if ($key === false) {
			return '';
		}
		$details = openssl_pkey_get_details($key);
		if ($details === false || !isset($details['key'])) {
			return '';
		}
		return 'SHA256:' . base64_encode(hash('sha256', $details['key'], true));
	}

	/**
	 * validateES256PublicKey accepts an empty string (clearing the key) or a PEM
	 * that parses as an EC public key on the P-256 (prime256v1) curve — the only
	 * curve ES256 uses. RSA keys, other curves and malformed PEMs are rejected, so
	 * a wrong key can never be installed and silently break token verification.
	 *
	 * @throws \InvalidArgumentException when the PEM is not a P-256 public key
	 */
	private function validateES256PublicKey(string $pem): void {
		if ($pem === '') {
			return;
		}
		$key = openssl_pkey_get_public($pem);
		if ($key === false) {
			throw new \InvalidArgumentException('invalid PEM: not a readable public key');
		}
		$details = openssl_pkey_get_details($key);
		if ($details === false || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
			throw new \InvalidArgumentException('key must be an EC (ES256) public key');
		}
		if (($details['ec']['curve_name'] ?? '') !== 'prime256v1') {
			throw new \InvalidArgumentException('key must use the P-256 (prime256v1) curve');
		}
	}
}
