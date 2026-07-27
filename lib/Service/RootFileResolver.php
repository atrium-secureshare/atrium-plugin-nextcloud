<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * RootFileResolver is the IRootFolder-backed FileResolver. Any resolution failure
 * resolves to null so the controller answers a clean 404 instead of leaking a
 * filesystem error.
 */
final class RootFileResolver implements FileResolver {
	public function __construct(
		private readonly IRootFolder $rootFolder,
		private readonly LoggerInterface $logger,
	) {
	}

	public function resolve(string $ownerUid, int $fileId): ?Node {
		try {
			$nodes = $this->rootFolder->getUserFolder($ownerUid)->getById($fileId);
			return $nodes[0] ?? null;
		} catch (\Throwable $e) {
			$this->logger->warning('atrium shared node not resolvable', [
				'owner_uid' => $ownerUid,
				'file_id' => $fileId,
				'exception' => $e,
			]);
			return null;
		}
	}
}
