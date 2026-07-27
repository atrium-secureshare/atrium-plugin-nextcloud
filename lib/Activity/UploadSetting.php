<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Activity;

use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/**
 * UploadSetting registers the upload activity type. This registration is what
 * makes the upload entry visible to a folder's co-recipients: the stream query
 * restricts to registered setting identifiers, so without it the entry is stored
 * but filtered out of every non-author's stream. Stream visibility uses the
 * default-enabled, not-user-disableable defaults so "who uploaded into your shared
 * folder" cannot be hidden; only the optional email digest is opt-in.
 */
class UploadSetting extends ActivitySettings {
	public function __construct(
		private readonly IL10N $l,
		private readonly AdminConfigService $adminConfig,
	) {
	}

	#[\Override]
	public function getIdentifier(): string {
		return Provider::TYPE_UPLOADED;
	}

	#[\Override]
	public function getName(): string {
		return $this->l->t('A file has been <strong>uploaded</strong> to a folder you share via %s', [$this->adminConfig->getWhitelabelName()]);
	}

	#[\Override]
	public function getGroupIdentifier(): string {
		return 'files';
	}

	#[\Override]
	public function getGroupName(): string {
		return $this->l->t('Files');
	}

	#[\Override]
	public function getPriority(): int {
		return 51;
	}

	#[\Override]
	public function canChangeMail(): bool {
		return true;
	}

	#[\Override]
	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
