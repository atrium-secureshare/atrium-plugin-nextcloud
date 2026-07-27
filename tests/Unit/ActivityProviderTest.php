<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Activity\Provider;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Tests\MocksL10N;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ActivityProviderTest extends TestCase {
	use MocksL10N;

	private IFactory&MockObject $languageFactory;
	private IURLGenerator&MockObject $url;
	private IManager&MockObject $activityManager;
	private Provider $provider;

	protected function setUp(): void {
		$this->languageFactory = $this->createMock(IFactory::class);
		$this->url = $this->createMock(IURLGenerator::class);
		$this->activityManager = $this->createMock(IManager::class);

		$this->languageFactory->method('get')->willReturn($this->mockL10N());
		$this->url->method('getAbsoluteURL')->willReturnArgument(0);
		// Echo the requested image path so the icon assertion can distinguish the
		// share icon from the upload icon.
		$this->url->method('imagePath')->willReturnCallback(static fn(string $app, string $file): string => $file);
		$this->url->method('linkToRouteAbsolute')->willReturn('https://nc/f/42');

		$adminConfig = $this->createMock(AdminConfigService::class);
		$adminConfig->method('getWhitelabelName')->willReturn('Atrium');

		$this->provider = new Provider($this->languageFactory, $this->url, $this->activityManager, $adminConfig);
	}

	public function testRejectsForeignApp(): void {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('some_other_app');

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('en', $event);
	}

	public function testRejectsUnknownSubject(): void {
		$event = $this->makeEvent('an_unrelated_subject');

		$this->expectException(UnknownActivityException::class);
		$this->provider->parse('en', $event);
	}

	/**
	 * @dataProvider subjectRenderings
	 */
	public function testRendersSubject(string $subject, bool $filtered, string $expectedText, string $expectedIcon): void {
		$this->activityManager->method('isFormattingFilteredObject')->willReturn($filtered);
		$event = $this->makeEvent($subject, ['report.pdf', 'bob@example.com'], 42);

		$event->expects(self::once())->method('setRichSubject')->with($expectedText, self::anything());
		$event->expects(self::once())->method('setIcon')->with($expectedIcon);

		self::assertSame($event, $this->provider->parse('en', $event));
	}

	/** @return array<string,array{0:string,1:bool,2:string,3:string}> */
	public static function subjectRenderings(): array {
		return [
			'share, stream' => [Provider::SUBJECT_SHARED_SELF, false, 'You shared {file} with {email} via {brand}', 'actions/share.svg'],
			'share, filtered' => [Provider::SUBJECT_SHARED_SELF, true, 'Shared with {email} via {brand}', 'actions/share.svg'],
			'unshare, stream' => [Provider::SUBJECT_UNSHARED_SELF, false, 'You unshared {file} from {email} via {brand}', 'actions/share.svg'],
			'unshare, filtered' => [Provider::SUBJECT_UNSHARED_SELF, true, 'Unshared from {email} via {brand}', 'actions/share.svg'],
			'upload, stream' => [Provider::SUBJECT_UPLOADED, false, '{email} uploaded {file} via {brand}', 'actions/upload.svg'],
			'upload, filtered' => [Provider::SUBJECT_UPLOADED, true, 'Uploaded via {brand} by {email}', 'actions/upload.svg'],
		];
	}

	public function testStreamParametersCarryFileEmailBrand(): void {
		$this->activityManager->method('isFormattingFilteredObject')->willReturn(false);
		$event = $this->makeEvent(Provider::SUBJECT_SHARED_SELF, ['report.pdf', 'bob@example.com'], 42);

		$event->expects(self::once())
			->method('setRichSubject')
			->with(
				self::anything(),
				self::callback(function (array $params): bool {
					return $params['file']['type'] === 'file'
						&& $params['file']['id'] === '42'
						&& $params['file']['name'] === 'report.pdf'
						&& $params['email']['type'] === 'email'
						&& $params['email']['id'] === 'bob@example.com'
						&& $params['brand']['type'] === 'highlight'
						&& $params['brand']['name'] === 'Atrium';
				}),
			);

		$this->provider->parse('en', $event);
	}

	/**
	 * @param list<string> $parameters
	 */
	private function makeEvent(string $subject, array $parameters = [], int $objectId = 1): IEvent&MockObject {
		$event = $this->createMock(IEvent::class);
		$event->method('getApp')->willReturn('atrium_secureshare');
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($parameters);
		$event->method('getObjectId')->willReturn($objectId);
		return $event;
	}
}
