<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Activity\Provider;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCA\AtriumSecureShare\Exception\DownloadLimitReachedException;
use OCA\AtriumSecureShare\Service\MailService;
use OCA\AtriumSecureShare\Service\ShareService;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\Activity\IEvent;
use OCP\Activity\IManager as IActivityManager;
use OCP\Files\File;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ShareServiceTest extends TestCase {
	private AtriumShareMapper&MockObject $mapper;
	private IDBConnection&MockObject $db;
	private ISecureRandom&MockObject $random;
	private MailService&MockObject $mail;
	private IActivityManager&MockObject $activityManager;
	private IShareManager&MockObject $shareManager;
	private IEvent&MockObject $event;
	private ShareService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(AtriumShareMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->mail = $this->createMock(MailService::class);
		$this->activityManager = $this->createMock(IActivityManager::class);
		$this->shareManager = $this->createMock(IShareManager::class);

		// A fluent IEvent stub so the setter chain in the activity publishers works;
		// the create/revoke tests add their own expects() on the two subject setters.
		$this->event = $this->fluentEvent();
		foreach (['setAffectedUser', 'setSubject'] as $setter) {
			$this->event->method($setter)->willReturnSelf();
		}
		$this->activityManager->method('generateEvent')->willReturn($this->event);

		$this->service = new ShareService(
			$this->mapper,
			$this->db,
			$this->random,
			$this->mail,
			$this->activityManager,
			$this->shareManager,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testCreateShareMintsTokenAndNormalizesEmail(): void {
		$this->random->expects(self::once())
			->method('generate')
			->with(64, ISecureRandom::CHAR_ALPHANUMERIC)
			->willReturn(str_repeat('a', 64));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->expects(self::never())->method('update');

		$share = $this->service->createShare(42, 'alice', '  Bob@Example.COM ', 1, null, null, false);

		self::assertSame(str_repeat('a', 64), $share->getToken());
		self::assertSame('bob@example.com', $share->getRecipientEmail());
		self::assertSame(42, $share->getFileId());
		self::assertSame(0, $share->getDownloadCount());
		self::assertFalse($share->getEmailSent());
		self::assertInstanceOf(\DateTime::class, $share->getCreatedAt());
	}

	public function testCreateShareStoresRecipientEmailNfkcCanonicalized(): void {
		// Storage now uses the same NFKC rule as download authz, so a share
		// created with a compatibility spelling is stored in the canonical form
		// the middleware and listing compare against (previously lower-only).
		$this->random->method('generate')->willReturn(str_repeat('a', 64));
		$this->mapper->method('insert')->willReturnArgument(0);

		$share = $this->service->createShare(1, 'alice', "\u{FF22}\u{FF2F}\u{FF22}@example.com", 1, null, null, false);

		self::assertSame('bob@example.com', $share->getRecipientEmail());
	}

	public function testFindByRecipientEmailCanonicalizesLookupWithNfkc(): void {
		// The list-shares lookup canonicalizes the queried address the same way,
		// so a fullwidth-spelled query finds a share stored under the ascii form.
		$this->mapper->expects(self::once())
			->method('findByRecipientEmail')
			->with('bob@example.com')
			->willReturn([]);

		$this->service->findByRecipientEmail("\u{FF22}\u{FF2F}\u{FF22}@example.com");
	}

	public function testCreateShareSendsEmailAndPersistsFlagOnSuccess(): void {
		$this->random->method('generate')->willReturn(str_repeat('b', 64));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mail->expects(self::once())->method('sendInvitation')->willReturn(true);
		$this->mapper->expects(self::once())->method('update')->willReturnArgument(0);

		$share = $this->service->createShare(1, 'alice', 'bob@example.com', 1, null, null, true, 'report.pdf');

		self::assertTrue($share->getEmailSent());
	}

	public function testCreateShareKeepsEmailFlagFalseWhenMailFails(): void {
		$this->random->method('generate')->willReturn(str_repeat('c', 64));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mail->method('sendInvitation')->willReturn(false);
		$this->mapper->expects(self::never())->method('update');

		$share = $this->service->createShare(1, 'alice', 'bob@example.com', 1, null, null, true);

		self::assertFalse($share->getEmailSent());
	}

	public function testCreateSharePublishesActivityToOwnerStream(): void {
		$this->random->method('generate')->willReturn(str_repeat('d', 64));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mail->method('sendInvitation')->willReturn(false);

		$this->event->expects(self::once())
			->method('setSubject')
			->with(Provider::SUBJECT_SHARED_SELF, ['report.pdf', 'bob@example.com']);
		$this->event->expects(self::once())
			->method('setAffectedUser')
			->with('alice');
		$this->event->expects(self::once())
			->method('setObject')
			->with('files', 42, 'report.pdf');
		$this->activityManager->expects(self::once())
			->method('publish')
			->with($this->event);

		$this->service->createShare(42, 'alice', 'Bob@Example.com', 0, null, null, false, 'report.pdf');
	}

	public function testCreateShareSucceedsWhenActivityPublishingFails(): void {
		$this->random->method('generate')->willReturn(str_repeat('e', 64));
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mail->method('sendInvitation')->willReturn(false);
		// A broken activity subsystem must not fail the share.
		$this->activityManager->method('publish')->willThrowException(new \RuntimeException('boom'));

		$share = $this->service->createShare(1, 'alice', 'bob@example.com', 0, null, null, false, 'report.pdf');

		self::assertSame('bob@example.com', $share->getRecipientEmail());
	}

	public function testUpdateShareChangesMutableFieldsButNotIdentity(): void {
		$share = $this->existingShare('alice');
		$share->setPermissions(AtriumShare::MODE_READ_ONLY);
		$share->setMaxDownloads(null);
		$share->setEmailSent(true);
		$this->mapper->method('find')->with(7)->willReturn($share);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mail->expects(self::never())->method('sendInvitation');
		$expiry = new \DateTime('+10 days');

		$updated = $this->service->updateShare(7, 'alice', AtriumShare::MODE_WRITE_ALL, 5, $expiry, false);

		self::assertSame(AtriumShare::MODE_WRITE_ALL, $updated->getPermissions());
		self::assertSame(5, $updated->getMaxDownloads());
		self::assertSame($expiry, $updated->getExpiresAt());
		// Identity is preserved: neither the recipient nor the file id ever changes.
		self::assertSame('bob@example.com', $updated->getRecipientEmail());
		self::assertSame(1, $updated->getFileId());
	}

	public function testUpdateShareRejectsNonOwner(): void {
		$share = $this->existingShare('alice');
		$this->mapper->method('find')->willReturn($share);
		$this->mapper->expects(self::never())->method('update');

		$this->expectException(NotPermittedException::class);
		$this->service->updateShare(7, 'mallory', 0, null, null, false);
	}

	public function testUpdateShareResendsInvitationWhenNotYetNotified(): void {
		$share = $this->existingShare('alice');
		$share->setEmailSent(false);
		$this->mapper->method('find')->willReturn($share);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mail->expects(self::once())->method('sendInvitation')->willReturn(true);

		$updated = $this->service->updateShare(7, 'alice', 0, null, null, true, 'report.pdf');

		self::assertTrue($updated->getEmailSent());
	}

	public function testUpdateShareDoesNotResendWhenAlreadyNotified(): void {
		$share = $this->existingShare('alice');
		$share->setEmailSent(true);
		$this->mapper->method('find')->willReturn($share);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mail->expects(self::never())->method('sendInvitation');

		$this->service->updateShare(7, 'alice', 0, null, null, true);
	}

	public function testRevokeShareHardDeletesForOwner(): void {
		$share = $this->existingShare('alice');
		$this->mapper->method('find')->with(7)->willReturn($share);
		// Revoke is a hard delete now (no soft revoked_at), so no PII lingers.
		$this->mapper->expects(self::once())->method('delete')->with($share);
		$this->mapper->expects(self::never())->method('update');

		$this->service->revokeShare(7, 'alice');
	}

	public function testRevokeSharePublishesUnshareActivityToOwnerStream(): void {
		$share = $this->existingShare('alice');
		$this->mapper->method('find')->with(7)->willReturn($share);
		$this->mapper->method('delete')->willReturnArgument(0);

		$this->event->expects(self::once())
			->method('setSubject')
			->with(Provider::SUBJECT_UNSHARED_SELF, ['report.pdf', 'bob@example.com']);
		$this->event->expects(self::once())
			->method('setAffectedUser')
			->with('alice');
		$this->event->expects(self::once())
			->method('setObject')
			->with('files', 1, 'report.pdf');
		$this->activityManager->expects(self::once())
			->method('publish')
			->with($this->event);

		$this->service->revokeShare(7, 'alice', 'report.pdf');
	}

	public function testRevokeShareRejectsNonOwner(): void {
		$share = $this->existingShare('alice');
		$this->mapper->method('find')->willReturn($share);
		$this->mapper->expects(self::never())->method('delete');

		$this->expectException(NotPermittedException::class);
		$this->service->revokeShare(7, 'mallory');
	}

	public function testPublishUploadActivityNotifiesOwnerAndEverySharee(): void {
		// The upload entry must reach the owner AND every internal user the folder
		// is shared with (getAccessList's users), each as a self-contained event
		// naming the uploader by email — that is how a co-recipient sees who
		// uploaded and can then ask the operator for the deeper audit trail.
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(55);
		$node->method('getName')->willReturn('report.pdf');

		$shareManager = $this->createMock(IShareManager::class);
		$shareManager->expects(self::once())
			->method('getAccessList')
			->with($node, true, true)
			->willReturn(['users' => ['carol' => [], 'dave' => []]]);

		$affected = [];
		$subjects = [];
		$event = $this->fluentEvent();
		$event->method('setAffectedUser')->willReturnCallback(function (string $uid) use (&$affected, $event) {
			$affected[] = $uid;
			return $event;
		});
		$event->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$subjects, $event) {
			$subjects[] = [$subject, $params];
			return $event;
		});
		$activityManager = $this->createMock(IActivityManager::class);
		$activityManager->method('generateEvent')->willReturn($event);
		$activityManager->expects(self::exactly(3))->method('publish')->with($event);

		$service = new ShareService(
			$this->mapper, $this->db, $this->random, $this->mail,
			$activityManager, $shareManager, $this->createMock(LoggerInterface::class),
		);
		// Uploader email is canonicalized into the subject, mirroring the recipient.
		$service->publishUploadActivity($this->existingShare('alice'), $node, 'Bob@Example.com');

		self::assertSame(['alice', 'carol', 'dave'], $affected);
		self::assertSame([Provider::SUBJECT_UPLOADED, ['report.pdf', 'bob@example.com']], $subjects[0]);
	}

	public function testPublishUploadActivitySucceedsWhenAccessListFails(): void {
		$node = $this->createMock(File::class);
		$node->method('getId')->willReturn(55);
		$node->method('getName')->willReturn('report.pdf');
		$shareManager = $this->createMock(IShareManager::class);
		// A broken share/activity subsystem must never fail the (already stored) upload.
		$shareManager->method('getAccessList')->willThrowException(new \RuntimeException('boom'));

		$service = new ShareService(
			$this->mapper, $this->db, $this->random, $this->mail,
			$this->activityManager, $shareManager, $this->createMock(LoggerInterface::class),
		);
		$service->publishUploadActivity($this->existingShare('alice'), $node, 'bob@example.com');

		$this->addToAssertionCount(1);
	}

	public function testFindByRecipientEmailReturnsOnlyActiveShares(): void {
		$active = $this->existingShare('alice');
		$expired = $this->existingShare('alice');
		$expired->setExpiresAt(new \DateTime('-1 hour'));
		$exhausted = $this->existingShare('alice');
		$exhausted->setMaxDownloads(1);
		$exhausted->setDownloadCount(1);

		$this->mapper->expects(self::once())
			->method('findByRecipientEmail')
			->with('bob@example.com')
			->willReturn([$active, $expired, $exhausted]);

		$result = $this->service->findByRecipientEmail('Bob@Example.com');

		self::assertSame([$active], $result);
	}

	public function testFindActiveByOwnerReturnsOnlyActiveShares(): void {
		$active = $this->existingShare('alice');
		$expired = $this->existingShare('alice');
		$expired->setExpiresAt(new \DateTime('-1 hour'));
		$exhausted = $this->existingShare('alice');
		$exhausted->setMaxDownloads(1);
		$exhausted->setDownloadCount(1);

		$this->mapper->expects(self::once())
			->method('findByOwner')
			->with('alice')
			->willReturn([$active, $expired, $exhausted]);

		$result = $this->service->findActiveByOwner('alice');

		self::assertSame([$active], $result);
	}

	public function testFindByFileForOwnerIncludesActiveAndGraceShares(): void {
		$active = $this->existingShare('alice');
		// Expired 2 days ago, 7-day grace → still shown.
		$graceExpired = $this->existingShare('alice');
		$graceExpired->setExpiresAt(new \DateTime('-2 days'));
		// Exhausted 1 day ago, still inside grace → shown.
		$graceExhausted = $this->existingShare('alice');
		$graceExhausted->setMaxDownloads(1);
		$graceExhausted->setDownloadCount(1);
		$graceExhausted->setLastDownloadAt(new \DateTime('-1 day'));
		// Expired 10 days ago, beyond the 7-day grace → omitted (cron will purge).
		$stale = $this->existingShare('alice');
		$stale->setExpiresAt(new \DateTime('-10 days'));
		// Another owner's share of the same file id → never listed.
		$foreign = $this->existingShare('mallory');

		$this->mapper->method('findByFileId')->with(1)
			->willReturn([$active, $graceExpired, $graceExhausted, $stale, $foreign]);

		$result = $this->service->findByFileForOwner(1, 'alice', 7);

		self::assertSame([$active, $graceExpired, $graceExhausted], $result);
	}

	public function testFindByFileForOwnerZeroRetentionShowsOnlyActive(): void {
		$active = $this->existingShare('alice');
		$expired = $this->existingShare('alice');
		$expired->setExpiresAt(new \DateTime('-1 minute'));

		$this->mapper->method('findByFileId')->willReturn([$active, $expired]);

		// Retention 0 → no grace: expired/exhausted drop out immediately.
		self::assertSame([$active], $this->service->findByFileForOwner(1, 'alice', 0));
	}

	public function testFindByFileForOwnerDropsExhaustedWithoutRecordedDownload(): void {
		// A legacy exhausted row with no last_download_at has no grace anchor and is
		// omitted even inside a positive window.
		$legacy = $this->existingShare('alice');
		$legacy->setMaxDownloads(1);
		$legacy->setDownloadCount(1);

		$this->mapper->method('findByFileId')->willReturn([$legacy]);

		self::assertSame([], $this->service->findByFileForOwner(1, 'alice', 7));
	}

	public function testIncrementDownloadCountStampsLastDownloadAndSucceeds(): void {
		$this->db->expects(self::once())
			->method('executeStatement')
			->with(
				self::stringContains('last_download_at'),
				self::callback(static fn(array $params): bool => is_string($params[0]) && $params[1] === 5),
			)
			->willReturn(1);

		$this->service->incrementDownloadCount(5);
	}

	public function testIncrementDownloadCountThrowsWhenLimitReached(): void {
		$this->db->method('executeStatement')->willReturn(0);

		$this->expectException(DownloadLimitReachedException::class);
		$this->service->incrementDownloadCount(5);
	}

	private function existingShare(string $ownerUid): AtriumShare {
		return ShareFactory::make(['token' => str_repeat('t', 64), 'ownerUid' => $ownerUid]);
	}

	private function fluentEvent(): IEvent&MockObject {
		$event = $this->createMock(IEvent::class);
		foreach (['setApp', 'setType', 'setAuthor', 'setObject'] as $setter) {
			$event->method($setter)->willReturnSelf();
		}
		return $event;
	}
}
