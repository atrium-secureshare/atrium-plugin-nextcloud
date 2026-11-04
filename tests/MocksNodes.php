<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests;

use OCA\AtriumSecureShare\Service\FileResolver;
use OCP\Files\File;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\Files\Storage\IStorage;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\Stub;

/**
 * @mixin \PHPUnit\Framework\TestCase
 */
trait MocksNodes {
	private function fileMock(string $name, string $mime = 'application/octet-stream', int $size = 0): File&Stub {
		$file = $this->createStub(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getSize')->willReturn($size);
		$file->method('fopen')->willReturn(fopen('php://memory', 'r'));
		return $file;
	}

	/** storageMock is an ordinary storage: a node on it is nobody else's share. */
	private function storageMock(): IStorage&Stub {
		$storage = $this->createStub(IStorage::class);
		$storage->method('instanceOfStorage')->willReturn(false);
		return $storage;
	}

	/**
	 * sharedStorageMock is the storage of a node the user received through someone
	 * else's share; $sharePermissions is what the file's owner granted, unmasked
	 * by anything the admin configured.
	 */
	private function sharedStorageMock(int $sharePermissions): ISharedStorage&Stub {
		$share = $this->createStub(IShare::class);
		$share->method('getPermissions')->willReturn($sharePermissions);
		$storage = $this->createStub(ISharedStorage::class);
		$storage->method('instanceOfStorage')->willReturn(true);
		$storage->method('getShare')->willReturn($share);
		return $storage;
	}

	private function mountMock(bool $allowsSharing = true): IMountPoint&Stub {
		$mount = $this->createStub(IMountPoint::class);
		$mount->method('getOption')->willReturn($allowsSharing);
		return $mount;
	}

	private function resolverReturning(?Node $node): FileResolver&Stub {
		$resolver = $this->createStub(FileResolver::class);
		$resolver->method('resolve')->willReturn($node);
		return $resolver;
	}
}
