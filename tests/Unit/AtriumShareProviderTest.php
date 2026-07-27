<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCA\AtriumSecureShare\Share\AtriumShareProvider;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\Files\Folder;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

final class AtriumShareProviderTest extends TestCase {
	public function testGetSharesInFolderReturnsActiveOwnerSharesKeyedByFileIdAsEmailType(): void {
		$active1 = ShareFactory::make(['id' => 1, 'fileId' => 10, 'recipientEmail' => 'bob@example.com', 'permissions' => 0]);
		$expired = ShareFactory::make(['id' => 2, 'fileId' => 11, 'recipientEmail' => 'x@example.com', 'permissions' => 0, 'expiresAt' => new \DateTime('-1 hour')]);
		$active2 = ShareFactory::make(['id' => 3, 'fileId' => 10, 'recipientEmail' => 'carol@example.com', 'permissions' => 0]);

		$mapper = $this->createMock(AtriumShareMapper::class);
		$mapper->method('findByParentFolder')->with(99, 'alice')->willReturn([$active1, $expired, $active2]);

		$seenTypes = [];
		$manager = $this->fluentShareManager($seenTypes);

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(99);

		$provider = new AtriumShareProvider($mapper, $manager);
		$result = $provider->getSharesInFolder('alice', $folder, false);

		// The expired share (file 11) is filtered out; only the active file-10 shares remain.
		self::assertSame([10], array_keys($result));
		self::assertCount(2, $result[10]);
		self::assertSame([IShare::TYPE_EMAIL, IShare::TYPE_EMAIL], $seenTypes);
	}

	public function testGetSharedWithIsAlwaysEmpty(): void {
		self::assertSame([], $this->inertProvider()->getSharedWith('someone', IShare::TYPE_EMAIL, null, -1, 0));
	}

	public function testGetShareByTokenThrows(): void {
		$this->expectException(ShareNotFound::class);
		$this->inertProvider()->getShareByToken('whatever');
	}

	public function testGetAllSharesIsEmptySoNextcloudDoesNotManageLifecycle(): void {
		self::assertSame([], iterator_to_array($this->toIterator($this->inertProvider()->getAllShares())));
	}

	/** inertProvider builds the provider for the recipient-side / lifecycle contract tests, which touch no data. */
	private function inertProvider(): AtriumShareProvider {
		return new AtriumShareProvider($this->createMock(AtriumShareMapper::class), $this->createMock(IManager::class));
	}

	private function fluentShareManager(array &$seenTypes): IManager {
		$manager = $this->createMock(IManager::class);
		$manager->method('newShare')->willReturnCallback(function () use (&$seenTypes): IShare {
			$share = $this->createMock(IShare::class);
			foreach ([
				'setId', 'setProviderId', 'setNodeId', 'setShareOwner', 'setSharedBy',
				'setSharedWith', 'setPermissions', 'setShareTime', 'setExpirationDate',
			] as $setter) {
				$share->method($setter)->willReturnSelf();
			}
			$share->method('setShareType')->willReturnCallback(function (int $type) use ($share, &$seenTypes): IShare {
				$seenTypes[] = $type;
				return $share;
			});
			return $share;
		});
		return $manager;
	}

	private function toIterator(iterable $it): \Iterator {
		return is_array($it) ? new \ArrayIterator($it) : $it;
	}
}
