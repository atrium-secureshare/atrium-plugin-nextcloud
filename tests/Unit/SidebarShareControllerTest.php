<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Controller\SidebarShareController;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Service\FileResolver;
use OCA\AtriumSecureShare\Service\PortalConfig;
use OCA\AtriumSecureShare\Service\ShareService;
use OCA\AtriumSecureShare\Tests\MocksNodes;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class SidebarShareControllerTest extends TestCase {
	use MocksNodes;

	public function testIndexReturns404ForFileTheUserCannotSee(): void {
		$resolver = $this->createMock(FileResolver::class);
		$resolver->method('resolve')->willReturn(null);
		$controller = $this->controller($this->createMock(ShareService::class), $resolver);

		$this->expectException(OCSNotFoundException::class);
		$controller->index(99);
	}

	public function testIndexListsOwnerSharesAsCamelCaseDtos(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		// The retention window (default 7 in the test's adminConfig) is passed through.
		$service->method('findByFileForOwner')->with(99, 'alice', 7)->willReturn([$share]);

		$controller = $this->controller($service, $this->resolverReturning($this->file('report.pdf')));
		$data = $controller->index(99)->getData();

		self::assertCount(1, $data);
		self::assertSame(7, $data[0]['id']);
		self::assertSame('bob@example.com', $data[0]['recipientEmail']);
		self::assertSame('report.pdf', $data[0]['fileName']);
		self::assertFalse($data[0]['isFolder']);
		self::assertSame('active', $data[0]['status']);
		self::assertSame('https://portal.example', $data[0]['shareUrl']);
	}

	/**
	 * The model validation (independent of policy) rejects each of these before any
	 * share is created: address shape, file vs. folder capabilities, and past expiry.
	 *
	 * @dataProvider invalidCreateInputs
	 *
	 * @param array<string,mixed> $opts
	 */
	public function testCreateRejectsInvalidModel(string $nodeType, string $email, array $opts): void {
		$node = $nodeType === 'folder' ? $this->createMock(Folder::class) : $this->file('a.txt');
		$controller = $this->controller($this->createMock(ShareService::class), $this->resolverReturning($node));
		$this->expectException(OCSBadRequestException::class);
		$controller->create(99, $email, ...$opts);
	}

	/** @return array<string,array{0:string,1:string,2:array<string,mixed>}> */
	public static function invalidCreateInputs(): array {
		return [
			'invalid email' => ['file', 'not-an-email', []],
			'non-read-only permission on file' => ['file', 'bob@example.com', ['permissions' => 2]],
			'download cap on folder' => ['folder', 'bob@example.com', ['permissions' => 0, 'maxDownloads' => 5]],
			'unknown folder mode' => ['folder', 'bob@example.com', ['permissions' => 9]],
			'past expiry' => ['file', 'bob@example.com', ['permissions' => 0, 'expiresAt' => '2000-01-01T00:00:00Z']],
		];
	}

	public function testCreateReturns404WhenFileMissing(): void {
		$resolver = $this->createMock(FileResolver::class);
		$resolver->method('resolve')->willReturn(null);
		$controller = $this->controller($this->createMock(ShareService::class), $resolver);
		$this->expectException(OCSNotFoundException::class);
		$controller->create(99, 'bob@example.com');
	}

	public function testCreatePersistsValidFileShareAndReturnsDto(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		// The controller trims but leaves normalization (lower-casing) to the
		// service, the single source of truth for email canonicalization.
		$service->expects(self::once())
			->method('createShare')
			->with(99, 'alice', 'Bob@Example.com', 0, 3, self::isInstanceOf(\DateTime::class), true, 'report.pdf')
			->willReturn($share);

		$controller = $this->controller($service, $this->resolverReturning($this->file('report.pdf')));
		$data = $controller->create(99, ' Bob@Example.com ', permissions: 0, expiresAt: '2999-01-01T00:00:00Z', maxDownloads: 3, sendEmail: true)->getData();

		self::assertSame('bob@example.com', $data['recipientEmail']);
	}

	/**
	 * With a valid model, the share policy still rejects a mode outside the allowed
	 * set or an expiry that violates the configured maximum duration.
	 *
	 * @dataProvider policyViolations
	 *
	 * @param array<string,mixed> $config
	 * @param array<string,mixed> $opts
	 */
	public function testCreateRejectsByPolicy(array $config, string $nodeType, array $opts): void {
		$node = $nodeType === 'folder' ? $this->createMock(Folder::class) : $this->file('a.txt');
		$controller = $this->controller(
			$this->createMock(ShareService::class),
			$this->resolverReturning($node),
			$this->adminConfig(...$config),
		);
		$this->expectException(OCSBadRequestException::class);
		$controller->create(99, 'bob@example.com', ...$opts);
	}

	/** @return array<string,array{0:array<string,mixed>,1:string,2:array<string,mixed>}> */
	public static function policyViolations(): array {
		$farFuture = (new \DateTime('+30 days'))->format(\DateTimeInterface::RFC3339);
		return [
			'mode disallowed by policy' => [['allowedModes' => [0, 1]], 'folder', ['permissions' => 2]],
			'duration exceeds policy max' => [['maxDays' => 7], 'file', ['permissions' => 0, 'expiresAt' => $farFuture]],
			'expiry required when policy caps duration' => [['maxDays' => 7], 'file', ['permissions' => 0, 'expiresAt' => null]],
		];
	}

	public function testCreateAcceptsModeAndDurationWithinPolicy(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		$service->expects(self::once())->method('createShare')->willReturn($share);

		$controller = $this->controller(
			$service,
			$this->resolverReturning($this->file('a.txt')),
			$this->adminConfig(allowedModes: [0], maxDays: 7),
		);
		$within = (new \DateTime())->modify('+5 days')->format(\DateTimeInterface::RFC3339);
		$data = $controller->create(99, 'bob@example.com', permissions: 0, expiresAt: $within)->getData();

		self::assertSame('bob@example.com', $data['recipientEmail']);
	}

	public function testPolicyReturnsPublicSubset(): void {
		$config = $this->createMock(AdminConfigService::class);
		$config->method('getPolicy')->willReturn([
			'emailEnabled' => true,
			'emailOptOutAllowed' => false,
			'allowedModes' => [0, 1],
			'maxShareDurationDays' => 7,
			'whitelabelName' => 'Acme Share',
		]);
		$controller = $this->controller($this->createMock(ShareService::class), $this->createMock(FileResolver::class), $config);

		$data = $controller->policy()->getData();

		self::assertSame([0, 1], $data['allowedModes']);
		self::assertSame('Acme Share', $data['whitelabelName']);
		self::assertArrayNotHasKey('corePublicKey', $data);
	}

	public function testUpdateReturns404WhenShareMissing(): void {
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->willThrowException(new DoesNotExistException('gone'));
		$controller = $this->controller($service, $this->createMock(FileResolver::class));
		$this->expectException(OCSNotFoundException::class);
		$controller->update(7);
	}

	public function testUpdateRejectsForeignOwner(): void {
		$share = $this->share(fileId: 99, ownerUid: 'mallory');
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->willReturn($share);
		$controller = $this->controller($service, $this->createMock(FileResolver::class));
		$this->expectException(OCSForbiddenException::class);
		$controller->update(7);
	}

	public function testUpdateRejectsNonReadOnlyPermissionOnFile(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->willReturn($share);
		$controller = $this->controller($service, $this->resolverReturning($this->file('a.txt')));
		$this->expectException(OCSBadRequestException::class);
		$controller->update(7, permissions: 2);
	}

	public function testUpdatePersistsMutableFieldsAndReturnsDto(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->willReturn($share);
		$service->expects(self::once())
			->method('updateShare')
			->with(7, 'alice', 0, 5, self::isInstanceOf(\DateTime::class), true, 'report.pdf')
			->willReturn($share);

		$controller = $this->controller($service, $this->resolverReturning($this->file('report.pdf')));
		$data = $controller->update(7, permissions: 0, expiresAt: '2999-01-01T00:00:00Z', maxDownloads: 5, sendEmail: true)->getData();

		self::assertSame('bob@example.com', $data['recipientEmail']);
	}

	public function testDestroyMapsMissingShareTo404(): void {
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->willThrowException(new DoesNotExistException('gone'));
		$controller = $this->controller($service, $this->createMock(FileResolver::class));
		$this->expectException(OCSNotFoundException::class);
		$controller->destroy(7);
	}

	public function testDestroyMapsForeignOwnerTo403(): void {
		$share = $this->share(fileId: 99, ownerUid: 'mallory');
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->willReturn($share);
		$service->method('revokeShare')->willThrowException(new NotPermittedException('not owner'));
		$controller = $this->controller($service, $this->createMock(FileResolver::class));
		$this->expectException(OCSForbiddenException::class);
		$controller->destroy(7);
	}

	public function testDestroyRevokesOwnedShareWithResolvedFileName(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		$service->method('getById')->with(7)->willReturn($share);
		// The resolved node name is threaded through so the revocation activity
		// names the file in the stream, mirroring create.
		$service->expects(self::once())->method('revokeShare')->with(7, 'alice', 'report.pdf');
		$controller = $this->controller($service, $this->resolverReturning($this->file('report.pdf')));
		self::assertSame([], $controller->destroy(7)->getData());
	}

	public function testOverviewMapsActiveSharesToNodeEntries(): void {
		$share = $this->share(fileId: 99, ownerUid: 'alice');
		$service = $this->createMock(ShareService::class);
		$service->method('findActiveByOwner')->with('alice')->willReturn([$share]);
		$node = $this->overviewFile('report.pdf', '/alice/files/docs/report.pdf', 99, 1_700_000_000, 4096, 'application/pdf');

		$controller = $this->controller($service, $this->resolverReturning($node));
		$data = $controller->overview()->getData();

		self::assertCount(1, $data);
		self::assertSame(7, $data[0]['id']);
		self::assertSame('bob@example.com', $data[0]['recipientEmail']);
		// permissions carries the sharing mode (0), not an NC bitmask.
		self::assertSame(0, $data[0]['permissions']);
		self::assertSame(99, $data[0]['fileId']);
		// path is relative to the owner's files root, no leading slash.
		self::assertSame('docs/report.pdf', $data[0]['path']);
		self::assertSame('report.pdf', $data[0]['name']);
		self::assertFalse($data[0]['isFolder']);
		self::assertSame(1_700_000_000, $data[0]['mtime']);
		self::assertSame(4096, $data[0]['size']);
		self::assertSame('application/pdf', $data[0]['mimetype']);
	}

	public function testOverviewSkipsSharesWhoseNodeNoLongerResolves(): void {
		$resolvable = $this->share(fileId: 99, ownerUid: 'alice', id: 7);
		$stale = $this->share(fileId: 100, ownerUid: 'alice', id: 8);
		$service = $this->createMock(ShareService::class);
		$service->method('findActiveByOwner')->willReturn([$resolvable, $stale]);

		$node = $this->overviewFile('report.pdf', '/alice/files/report.pdf', 99, 1, 1, 'text/plain');
		$resolver = $this->createMock(FileResolver::class);
		$resolver->method('resolve')->willReturnCallback(
			static fn(string $uid, int $fileId) => $fileId === 99 ? $node : null,
		);

		$data = $this->controller($service, $resolver)->overview()->getData();

		self::assertCount(1, $data);
		self::assertSame(99, $data[0]['fileId']);
	}

	public function testOverviewReturnsOneEntryPerShareWithoutDedup(): void {
		// Two active shares of the SAME file (different recipients) must yield two
		// entries — the overview never deduplicates by file.
		$first = $this->share(fileId: 99, ownerUid: 'alice', id: 7);
		$second = $this->share(fileId: 99, ownerUid: 'alice', id: 8);
		$service = $this->createMock(ShareService::class);
		$service->method('findActiveByOwner')->willReturn([$first, $second]);

		$node = $this->overviewFile('report.pdf', '/alice/files/report.pdf', 99, 1, 1, 'application/pdf');
		$data = $this->controller($service, $this->resolverReturning($node))->overview()->getData();

		self::assertCount(2, $data);
		self::assertSame([7, 8], [$data[0]['id'], $data[1]['id']]);
		self::assertSame(99, $data[0]['fileId']);
		self::assertSame(99, $data[1]['fileId']);
	}

	private function controller(ShareService $service, FileResolver $resolver, ?AdminConfigService $adminConfig = null): SidebarShareController {
		return new SidebarShareController(
			$this->createMock(IRequest::class),
			$service,
			$resolver,
			$this->portalConfig(),
			$adminConfig ?? $this->adminConfig(),
			$this->userSession('alice'),
		);
	}

	/**
	 * adminConfig builds an AdminConfigService whose policy permits everything, so
	 * the base create tests exercise the model validation, not the policy. The
	 * policy-enforcement tests pass their own with allowedModes/maxDays configured.
	 */
	private function adminConfig(array $allowedModes = [0, 1, 2, 3], ?int $maxDays = null, int $retentionDays = 7): AdminConfigService {
		$config = $this->createMock(AdminConfigService::class);
		$config->method('getAllowedModes')->willReturn($allowedModes);
		$config->method('getMaxShareDurationDays')->willReturn($maxDays);
		$config->method('getRetentionDays')->willReturn($retentionDays);
		return $config;
	}

	/** portalConfig builds a real PortalConfig (final, so not mockable) over mocks. */
	private function portalConfig(): PortalConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getBaseUrl')->willReturn('https://portal.example');
		return new PortalConfig($appConfig, $url);
	}

	private function userSession(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		return $file;
	}

	private function overviewFile(string $name, string $path, int $fileId, int $mtime, int $size, string $mime): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);
		$file->method('getId')->willReturn($fileId);
		$file->method('getMTime')->willReturn($mtime);
		$file->method('getSize')->willReturn($size);
		$file->method('getMimetype')->willReturn($mime);
		return $file;
	}

	private function share(int $fileId, string $ownerUid, int $id = 7): AtriumShare {
		return ShareFactory::make(['id' => $id, 'fileId' => $fileId, 'ownerUid' => $ownerUid, 'permissions' => 0, 'maxDownloads' => 3, 'emailSent' => true]);
	}
}
