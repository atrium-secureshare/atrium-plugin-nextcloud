<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Service\NodeSharePolicy;
use OCA\AtriumSecureShare\Tests\MocksNodes;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\TestCase;

final class NodeSharePolicyTest extends TestCase {
	use MocksNodes;

	/** Nextcloud's verdict is the whole truth for a node the user did not receive. */
	public function testOwnNodeFollowsNextcloudAlone(): void {
		foreach (self::POLICIES as $policy) {
			self::assertTrue($this->policy($policy)->canShare($this->ownNode(Constants::PERMISSION_ALL), 'alice'), $policy);
			self::assertFalse(
				$this->policy($policy)->canShare($this->ownNode(Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE), 'alice'),
				$policy,
			);
		}
	}

	/**
	 * A share whose permissions carry no share bit is the file owner's own denial.
	 * It outranks every admin option, including the permissive one.
	 *
	 * @dataProvider resharingPolicies
	 */
	public function testOwnerDenialIsNeverOverridden(string $resharingPolicy): void {
		// The node mask still says yes, so only the share itself can refuse here.
		$node = $this->receivedNode(
			maskedPermissions: Constants::PERMISSION_ALL,
			sharePermissions: Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE,
		);

		self::assertFalse($this->policy($resharingPolicy)->canShare($node, 'alice'));
	}

	/** @return array<string,array{0:string}> */
	public static function resharingPolicies(): array {
		return array_combine(self::POLICIES, array_map(static fn(string $p): array => [$p], self::POLICIES));
	}

	/**
	 * With the owner's grant in place, the admin option decides how Nextcloud's
	 * global resharing setting applies. Nextcloud reports that setting by clearing
	 * the share bit from the node mask, which is why the two are read separately.
	 *
	 * @dataProvider reshareMatrix
	 */
	public function testReshareFollowsTheAdminOption(string $resharingPolicy, bool $globallyAllowed, bool $expected): void {
		$masked = $globallyAllowed ? Constants::PERMISSION_ALL : Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE;
		$node = $this->receivedNode($masked, Constants::PERMISSION_ALL);

		self::assertSame($expected, $this->policy($resharingPolicy)->canShare($node, 'alice'));
	}

	/** @return array<string,array{0:string,1:bool,2:bool}> */
	public static function reshareMatrix(): array {
		return [
			'follow, globally allowed' => [AdminConfigService::RESHARING_FOLLOW, true, true],
			'follow, globally denied' => [AdminConfigService::RESHARING_FOLLOW, false, false],
			'strict, globally allowed' => [AdminConfigService::RESHARING_STRICT, true, false],
			'strict, globally denied' => [AdminConfigService::RESHARING_STRICT, false, false],
			'permissive, globally allowed' => [AdminConfigService::RESHARING_PERMISSIVE, true, true],
			'permissive, globally denied' => [AdminConfigService::RESHARING_PERMISSIVE, false, true],
		];
	}

	/**
	 * Permissive ignores the global resharing restriction and nothing else: a user
	 * excluded from sharing altogether stays excluded.
	 */
	public function testPermissiveStillHonoursSharingDisabledForUser(): void {
		$node = $this->receivedNode(Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE, Constants::PERMISSION_ALL);
		$policy = $this->policy(AdminConfigService::RESHARING_PERMISSIVE, sharingDisabledForUser: true);

		self::assertFalse($policy->canShare($node, 'alice'));
	}

	/** The same holds for a mount whose options switch sharing off. */
	public function testPermissiveStillHonoursMountWithSharingDisabled(): void {
		$node = $this->receivedNode(
			Constants::PERMISSION_ALL & ~Constants::PERMISSION_SHARE,
			Constants::PERMISSION_ALL,
			mountAllowsSharing: false,
		);

		self::assertFalse($this->policy(AdminConfigService::RESHARING_PERMISSIVE)->canShare($node, 'alice'));
	}

	public function testFileOffersReadOnlyOnly(): void {
		$file = $this->createMock(File::class);
		$file->method('getPermissions')->willReturn(Constants::PERMISSION_ALL);

		self::assertSame([0], $this->policy()->availableModes($file));
	}

	public function testFolderWithCreatePermissionOffersEveryMode(): void {
		self::assertSame([0, 1, 2, 3], $this->policy()->availableModes($this->folderWith(Constants::PERMISSION_ALL)));
	}

	/**
	 * Without create permission the recipient's upload would die in the storage
	 * layer, so the upload modes are not on offer at all.
	 */
	public function testFolderWithoutCreatePermissionOffersReadOnlyOnly(): void {
		$folder = $this->folderWith(Constants::PERMISSION_ALL & ~Constants::PERMISSION_CREATE);

		self::assertSame([0], $this->policy()->availableModes($folder));
	}

	private const POLICIES = [
		AdminConfigService::RESHARING_FOLLOW,
		AdminConfigService::RESHARING_STRICT,
		AdminConfigService::RESHARING_PERMISSIVE,
	];

	private function policy(
		string $resharingPolicy = AdminConfigService::RESHARING_FOLLOW,
		bool $sharingDisabledForUser = false,
	): NodeSharePolicy {
		$adminConfig = $this->createMock(AdminConfigService::class);
		$adminConfig->method('getResharingPolicy')->willReturn($resharingPolicy);
		$shareManager = $this->createMock(IShareManager::class);
		$shareManager->method('sharingDisabledForUser')->willReturn($sharingDisabledForUser);
		return new NodeSharePolicy($adminConfig, $shareManager);
	}

	/** ownNode is a node on the user's own storage, so no share governs it. */
	private function ownNode(int $permissions): Node {
		$node = $this->createMock(Node::class);
		$node->method('getPermissions')->willReturn($permissions);
		$node->method('getStorage')->willReturn($this->storageMock());
		return $node;
	}

	/**
	 * receivedNode is a node the user reached through someone else's share:
	 * $maskedPermissions is what Nextcloud reports after applying every
	 * restriction, $sharePermissions what the file's owner actually granted.
	 */
	private function receivedNode(int $maskedPermissions, int $sharePermissions, bool $mountAllowsSharing = true): Node {
		$node = $this->createMock(Node::class);
		$node->method('getPermissions')->willReturn($maskedPermissions);
		$node->method('getStorage')->willReturn($this->sharedStorageMock($sharePermissions));
		$node->method('getMountPoint')->willReturn($this->mountMock($mountAllowsSharing));
		return $node;
	}

	private function folderWith(int $permissions): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPermissions')->willReturn($permissions);
		return $folder;
	}
}
