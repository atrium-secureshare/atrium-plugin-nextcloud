<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Activity;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Provider renders the activities this app emits (external share created/revoked,
 * recipient upload into a shared folder). Downloads are deliberately not surfaced
 * (noise; the access audit trail lives in the Atrium core). The brand name is read
 * from AdminConfigService at render time, not frozen into the stored event, so it
 * stays a single source of truth and pre-existing entries pick up a renamed brand.
 */
class Provider implements IProvider {
	public const SUBJECT_SHARED_SELF = 'shared_self';
	public const SUBJECT_UNSHARED_SELF = 'unshared_self';
	public const SUBJECT_UPLOADED = 'uploaded';

	/**
	 * The upload event is addressed to co-recipients who are not its author, so it
	 * is only shown in their stream if this type is a registered activity setting.
	 * UploadSetting registers it; keep the two in lockstep.
	 */
	public const TYPE_UPLOADED = 'file_uploaded';

	public function __construct(
		private readonly IFactory $languageFactory,
		private readonly IURLGenerator $url,
		private readonly IManager $activityManager,
		private readonly AdminConfigService $adminConfig,
	) {
	}

	#[\Override]
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}

		$l = $this->languageFactory->get(Application::APP_ID, $language);

		// The filtered-object (single-file Activity tab) view omits the file name,
		// which is already the context; the stream view names the file.
		$filtered = $this->activityManager->isFormattingFilteredObject();
		$subject = match ($event->getSubject()) {
			self::SUBJECT_SHARED_SELF => $filtered
				? $l->t('Shared with {email} via {brand}')
				: $l->t('You shared {file} with {email} via {brand}'),
			self::SUBJECT_UNSHARED_SELF => $filtered
				? $l->t('Unshared from {email} via {brand}')
				: $l->t('You unshared {file} from {email} via {brand}'),
			self::SUBJECT_UPLOADED => $filtered
				? $l->t('Uploaded via {brand} by {email}')
				: $l->t('{email} uploaded {file} via {brand}'),
			default => throw new UnknownActivityException(),
		};

		// Match the native core icons (both ship a PNG twin for the digest e-mail renderer).
		$iconName = $event->getSubject() === self::SUBJECT_UPLOADED ? 'actions/upload' : 'actions/share';
		$iconFile = $iconName . ($this->activityManager->getRequirePNG() ? '.png' : '.svg');
		$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath('core', $iconFile)));
		$event->setRichSubject($subject, $this->parameters($event));

		return $event;
	}

	/**
	 * The brand is a render-time value from the admin config, not a stored
	 * parameter, so it is added here as a plain highlighted string.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function parameters(IEvent $event): array {
		[$fileName, $email] = array_pad($event->getSubjectParameters(), 2, '');
		$fileId = (string)$event->getObjectId();
		$brand = $this->adminConfig->getWhitelabelName();

		return [
			'file' => [
				'type' => 'file',
				'id' => $fileId,
				'name' => basename((string)$fileName),
				'path' => trim((string)$fileName, '/'),
				'link' => $this->url->linkToRouteAbsolute('files.viewcontroller.showFile', ['fileid' => $fileId]),
			],
			'email' => [
				'type' => 'email',
				'id' => (string)$email,
				'name' => (string)$email,
			],
			'brand' => [
				'type' => 'highlight',
				'id' => $brand,
				'name' => $brand,
			],
		];
	}
}
