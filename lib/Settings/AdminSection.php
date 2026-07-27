<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Settings;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * AdminSection registers the "Atrium Secureshare" entry in the Nextcloud admin
 * settings navigation. Its id is the app id, so AdminSettings attaches by
 * returning the same section id.
 */
class AdminSection implements IIconSection {
	public function __construct(
		private readonly IL10N $l,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Atrium Secureshare');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
