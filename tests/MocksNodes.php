<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests;

use OCA\AtriumSecureShare\Service\FileResolver;
use OCP\Files\File;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @mixin \PHPUnit\Framework\TestCase
 */
trait MocksNodes {
	private function fileMock(string $name, string $mime = 'application/octet-stream', int $size = 0): File&MockObject {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getSize')->willReturn($size);
		$file->method('fopen')->willReturn(fopen('php://memory', 'r'));
		return $file;
	}

	private function resolverReturning(?Node $node): FileResolver&MockObject {
		$resolver = $this->createMock(FileResolver::class);
		$resolver->method('resolve')->willReturn($node);
		return $resolver;
	}
}
