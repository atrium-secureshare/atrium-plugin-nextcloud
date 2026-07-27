<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Share;

use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Share\Exceptions\GenericShareException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use OCP\Share\IShareProvider;

/**
 * AtriumShareProvider makes Nextcloud natively aware of external Atrium shares, so
 * the Files list renders its OWN default "Shared" indicator for them — no custom
 * component, no custom DAV property, no frontend. It is read by the core through
 * Manager::getSharesInFolder(), the same path that surfaces every other share type.
 *
 * Scope — READ-ONLY and indicator-only:
 * - Only getSharesInFolder() is on the hot path: it runs ONE batched, indexed
 *   query per folder (owner-scoped), matching the core SharesPlugin's
 *   O(1)-queries-per-listing profile.
 * - Shares report IShare::TYPE_EMAIL: an Atrium share IS an external, email-
 *   addressed share. This also keeps the provider off the native sharing sidebar
 *   (Manager::getSharesBy routes TYPE_EMAIL to the sharebymail provider, never
 *   here), so the Atrium sidebar section stays the single place to manage shares.
 * - Access is governed by the Atrium core gateway, NOT Nextcloud. Recipients are
 *   external, so recipient-side lookups return empty and token/id lookups throw;
 *   the mutation methods are inert (Nextcloud must never create, alter or expire them).
 */
final class AtriumShareProvider implements IShareProvider {
	public function __construct(
		private readonly AtriumShareMapper $mapper,
		private readonly IManager $shareManager,
	) {
	}

	/** Provider id (only [a-zA-Z0-9]); used by the ProviderFactory registry. */
	public function identifier(): string {
		return 'atrium';
	}

	/**
	 * getSharesInFolder is the ONLY method the native indicator needs: the caller's
	 * active Atrium shares among the folder's direct children, keyed by file id, in
	 * one batched query.
	 *
	 * @return array<int, list<IShare>>
	 */
	public function getSharesInFolder($userId, Folder $node, $reshares, $shallow = true): array {
		$result = [];
		foreach ($this->mapper->findByParentFolder($node->getId(), (string)$userId) as $share) {
			if ($share->isActive()) {
				$result[$share->getFileId()][] = $this->toShare($share);
			}
		}
		return $result;
	}

	/**
	 * getSharesBy is not reached for Atrium shares in practice (TYPE_EMAIL routes to
	 * the sharebymail provider), but is implemented for correctness if called directly.
	 *
	 * @return IShare[]
	 */
	public function getSharesBy($userId, $shareType, $node, $reshares, $limit, $offset): array {
		if (!$node instanceof Node) {
			return [];
		}
		$shares = [];
		foreach ($this->mapper->findByFileId($node->getId()) as $share) {
			if ($share->getOwnerUid() === (string)$userId && $share->isActive()) {
				$shares[] = $this->toShare($share);
			}
		}
		return $shares;
	}

	/** @return IShare[] */
	public function getSharesByPath(Node $path): array {
		$shares = [];
		foreach ($this->mapper->findByFileId($path->getId()) as $share) {
			if ($share->isActive()) {
				$shares[] = $this->toShare($share);
			}
		}
		return $shares;
	}

	public function getShareById($id, $recipientId = null): IShare {
		try {
			$share = $this->mapper->find((int)$id);
		} catch (\Throwable) {
			throw new ShareNotFound();
		}
		if (!$share->isActive()) {
			throw new ShareNotFound();
		}
		return $this->toShare($share);
	}

	/**
	 * Recipients are external identities resolved by the Atrium core, never
	 * Nextcloud users — so nothing is ever "shared with" an NC user here.
	 *
	 * @return IShare[]
	 */
	public function getSharedWith($userId, $shareType, $node, $limit, $offset): array {
		return [];
	}

	/** Atrium tokens are redeemed at the Atrium portal, not through Nextcloud. */
	public function getShareByToken(string $token): IShare {
		throw new ShareNotFound();
	}

	/**
	 * No Nextcloud-side access is granted by an Atrium share (the core gateway
	 * governs access), so the access list is empty.
	 *
	 * @return array{users: array<string, mixed>, public: bool}
	 */
	public function getAccessList($nodes, $currentAccess): array {
		return ['users' => [], 'public' => false];
	}

	/** This app owns the Atrium share lifecycle; keep NC housekeeping out. */
	public function getAllShares(): iterable {
		return [];
	}

	/** @return IShare[] */
	public function getChildren(IShare $parent): array {
		return [];
	}

	// Mutations are inert: Atrium shares are managed by the plugin/core, so
	// create/update fail loud (unreachable) and the rest are harmless no-ops.

	public function create(IShare $share): IShare {
		throw new GenericShareException('Atrium shares are managed by Atrium, not Nextcloud');
	}

	public function update(IShare $share): IShare {
		throw new GenericShareException('Atrium shares are managed by Atrium, not Nextcloud');
	}

	public function delete(IShare $share): void {
	}

	public function deleteFromSelf(IShare $share, $recipient): void {
	}

	public function restore(IShare $share, string $recipient): IShare {
		return $share;
	}

	public function move(IShare $share, $recipient): IShare {
		return $share;
	}

	public function userDeleted($uid, $shareType): void {
	}

	public function groupDeleted($gid): void {
	}

	public function userDeletedFromGroup($uid, $gid): void {
	}

	/**
	 * toShare maps an Atrium share onto a native IShare carrying just what the
	 * indicator needs: an email-type share of the owner's node, referenced by id
	 * (Nextcloud resolves it lazily only if a consumer needs the node).
	 */
	private function toShare(AtriumShare $share): IShare {
		$dto = $this->shareManager->newShare();
		$dto->setId((string)$share->getId())
			->setProviderId($this->identifier())
			->setShareType(IShare::TYPE_EMAIL)
			->setNodeId($share->getFileId())
			->setShareOwner($share->getOwnerUid())
			->setSharedBy($share->getOwnerUid())
			->setSharedWith($share->getRecipientEmail())
			->setPermissions(Constants::PERMISSION_READ)
			->setShareTime($share->getCreatedAt() ?? new \DateTime());
		if ($share->getExpiresAt() !== null) {
			$dto->setExpirationDate($share->getExpiresAt());
		}
		return $dto;
	}
}
