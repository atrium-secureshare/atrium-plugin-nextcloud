<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Controller;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCA\AtriumSecureShare\Service\FileResolver;
use OCA\AtriumSecureShare\Service\PortalConfig;
use OCA\AtriumSecureShare\Service\ShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCSController;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotPermittedException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * SidebarShareController is the session-authenticated API the Files sharing
 * sidebar calls to manage external Atrium shares (list, create, revoke). Unlike
 * the core-facing SharesController it is NOT a CoreApiController: it runs behind
 * Nextcloud's normal login + CSRF protection (OCSController), and the logged-in
 * user is the authorization subject. It lives in the OCS URL space, distinct from
 * the plain /apps/.../api/v1/* core-facing routes, so the identical-looking path
 * never collides. Ownership is enforced twice: a file the caller cannot see in
 * their own storage yields 404 (no existence oracle), and revocation is
 * owner-checked in the service.
 */
class SidebarShareController extends OCSController {
	private const MODE_READ_ONLY = AtriumShare::MODE_READ_ONLY;
	private const MODE_MAX = AtriumShare::MODE_DROPZONE;

	public function __construct(
		IRequest $request,
		private readonly ShareService $shareService,
		private readonly FileResolver $fileResolver,
		private readonly PortalConfig $portalConfig,
		private readonly AdminConfigService $adminConfig,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * policy exposes the public share policy the sidebar needs to shape the create
	 * form. It deliberately never returns the trust key — any logged-in user may
	 * read it, but only an admin sees the full config.
	 */
	#[NoAdminRequired]
	public function policy(): DataResponse {
		return new DataResponse($this->adminConfig->getPolicy());
	}

	/**
	 * index lists the caller's shares of one node for the sidebar: active shares
	 * plus expired/exhausted ones still inside the retention grace window (each
	 * carries a `status`), so the owner sees why a share stopped and can reactivate
	 * it. A file they cannot see in their own storage returns 404 (no oracle).
	 */
	#[NoAdminRequired]
	public function index(int $fileId): DataResponse {
		$uid = $this->requireUid();
		if ($this->fileResolver->resolve($uid, $fileId) === null) {
			throw new OCSNotFoundException('file not found');
		}
		$shares = $this->shareService->findByFileForOwner($fileId, $uid, $this->adminConfig->getRetentionDays());
		return new DataResponse(array_map($this->formatShare(...), $shares));
	}

	/**
	 * overview lists ALL of the caller's active external shares across their files,
	 * one entry per share (no deduplication). It backs the "Shared via {brand}"
	 * navigation view. A node that no longer resolves (deleted or moved out of
	 * reach) is skipped silently — no oracle, and a stale share never breaks the
	 * listing.
	 */
	#[NoAdminRequired]
	public function overview(): DataResponse {
		$uid = $this->requireUid();
		$entries = [];
		foreach ($this->shareService->findActiveByOwner($uid) as $share) {
			$node = $this->fileResolver->resolve($uid, $share->getFileId());
			if ($node === null) {
				continue;
			}
			$entries[] = $this->formatOverviewEntry($share, $node, $uid);
		}
		return new DataResponse($entries);
	}

	/**
	 * create makes a new external share of $fileId for one recipient email,
	 * validating against the share model before delegating to the service.
	 */
	#[NoAdminRequired]
	public function create(
		int $fileId,
		string $recipientEmail,
		int $permissions = self::MODE_READ_ONLY,
		?string $expiresAt = null,
		?int $maxDownloads = null,
		bool $sendEmail = true,
	): DataResponse {
		$uid = $this->requireUid();

		$node = $this->fileResolver->resolve($uid, $fileId);
		if ($node === null) {
			throw new OCSNotFoundException('file not found');
		}
		$isFolder = !($node instanceof File);

		$email = trim($recipientEmail);
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new OCSBadRequestException('invalid recipient email');
		}
		$this->assertPermissions($permissions, $isFolder);
		$maxDownloads = $this->validateMaxDownloads($maxDownloads, $isFolder);
		$expires = $this->parseExpiry($expiresAt);
		$this->enforceSharePolicy($permissions, $expires);

		$share = $this->shareService->createShare(
			$fileId,
			$uid,
			$email,
			$permissions,
			$maxDownloads,
			$expires,
			$sendEmail,
			$node->getName(),
		);

		return new DataResponse($this->formatShare($share));
	}

	/**
	 * update edits an existing share the caller owns. Only the mutable fields are
	 * accepted — the sharing mode, the download cap and the expiry; the recipient
	 * email and the file id are the share's identity and cannot change (editing
	 * instead of re-creating is what avoids duplicate rows). Ownership is checked
	 * here (403) and again in the service.
	 */
	#[NoAdminRequired]
	public function update(
		int $id,
		int $permissions = self::MODE_READ_ONLY,
		?string $expiresAt = null,
		?int $maxDownloads = null,
		bool $sendEmail = true,
	): DataResponse {
		$uid = $this->requireUid();

		try {
			$share = $this->shareService->getById($id);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('share not found');
		}
		if ($share->getOwnerUid() !== $uid) {
			throw new OCSForbiddenException('not the share owner');
		}

		$node = $this->fileResolver->resolve($uid, $share->getFileId());
		if ($node === null) {
			throw new OCSNotFoundException('file not found');
		}
		$isFolder = !($node instanceof File);

		$this->assertPermissions($permissions, $isFolder);
		$maxDownloads = $this->validateMaxDownloads($maxDownloads, $isFolder);
		$expires = $this->parseExpiry($expiresAt);
		$this->enforceSharePolicy($permissions, $expires);

		try {
			$updated = $this->shareService->updateShare(
				$id,
				$uid,
				$permissions,
				$maxDownloads,
				$expires,
				$sendEmail,
				$node->getName(),
			);
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('share not found');
		} catch (NotPermittedException) {
			throw new OCSForbiddenException('not the share owner');
		}

		return new DataResponse($this->formatShare($updated));
	}

	/**
	 * destroy revokes a share the caller owns. A share owned by someone else yields
	 * 403; an unknown id yields 404 (both re-checked in the service). The node name
	 * is resolved best-effort so the revocation activity can name the file.
	 */
	#[NoAdminRequired]
	public function destroy(int $id): DataResponse {
		$uid = $this->requireUid();
		try {
			$share = $this->shareService->getById($id);
			$node = $this->fileResolver->resolve($uid, $share->getFileId());
			$this->shareService->revokeShare($id, $uid, $node?->getName() ?? '');
		} catch (DoesNotExistException) {
			throw new OCSNotFoundException('share not found');
		} catch (NotPermittedException) {
			throw new OCSForbiddenException('not the share owner');
		}
		return new DataResponse([]);
	}

	/** requireUid returns the logged-in user id; the login gate makes null a bug. */
	private function requireUid(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('no user session');
		}
		return $user->getUID();
	}

	/**
	 * enforceSharePolicy applies the admin share policy server-side (the sidebar UI
	 * only mirrors it, the server is the authority): the mode must be allowed, and a
	 * configured maximum duration makes expiry mandatory and bounded.
	 */
	private function enforceSharePolicy(int $permissions, ?\DateTime $expiresAt): void {
		if (!in_array($permissions, $this->adminConfig->getAllowedModes(), true)) {
			throw new OCSBadRequestException('this sharing mode is not allowed by policy');
		}
		$maxDays = $this->adminConfig->getMaxShareDurationDays();
		if ($maxDays === null) {
			return;
		}
		if ($expiresAt === null) {
			throw new OCSBadRequestException('an expiry date is required by policy');
		}
		$maxExpiry = (new \DateTime())->modify("+{$maxDays} days");
		if ($expiresAt > $maxExpiry) {
			throw new OCSBadRequestException("share duration exceeds the maximum of {$maxDays} days");
		}
	}

	/** assertPermissions enforces read-only for files and the 0..3 range for folders. */
	private function assertPermissions(int $permissions, bool $isFolder): void {
		if (!$isFolder) {
			if ($permissions !== self::MODE_READ_ONLY) {
				throw new OCSBadRequestException('files can only be shared read-only');
			}
			return;
		}
		if ($permissions < self::MODE_READ_ONLY || $permissions > self::MODE_MAX) {
			throw new OCSBadRequestException('unknown sharing mode');
		}
	}

	/** validateMaxDownloads rejects a cap on folders and non-positive caps on files. */
	private function validateMaxDownloads(?int $maxDownloads, bool $isFolder): ?int {
		if ($maxDownloads === null) {
			return null;
		}
		if ($isFolder) {
			throw new OCSBadRequestException('download limit applies to files only');
		}
		if ($maxDownloads < 1) {
			throw new OCSBadRequestException('download limit must be at least 1');
		}
		return $maxDownloads;
	}

	/** parseExpiry accepts null or a future ISO-8601 instant; the past is rejected. */
	private function parseExpiry(?string $expiresAt): ?\DateTime {
		if ($expiresAt === null || trim($expiresAt) === '') {
			return null;
		}
		try {
			$expires = new \DateTime($expiresAt);
		} catch (\Exception) {
			throw new OCSBadRequestException('invalid expiry date');
		}
		if ($expires <= new \DateTime()) {
			throw new OCSBadRequestException('expiry date must be in the future');
		}
		return $expires;
	}

	/**
	 * formatShare serialises a share for the sidebar UI in camelCase. `id` is the
	 * internal numeric id (the revoke path parameter); the opaque token is never
	 * exposed here.
	 *
	 * @return array<string,mixed>
	 */
	private function formatShare(AtriumShare $share): array {
		$node = $this->fileResolver->resolve($share->getOwnerUid(), $share->getFileId());
		return [
			'id' => $share->getId(),
			'recipientEmail' => $share->getRecipientEmail(),
			'permissions' => $share->getPermissions(),
			'maxDownloads' => $share->getMaxDownloads(),
			'downloadCount' => $share->getDownloadCount(),
			'expiresAt' => $this->formatDate($share->getExpiresAt()),
			'createdAt' => $this->formatDate($share->getCreatedAt()),
			'emailSent' => $share->getEmailSent(),
			'status' => $share->getStatus(),
			'isFolder' => $node !== null && !($node instanceof File),
			'fileName' => $node?->getName() ?? '',
			'shareUrl' => $this->portalConfig->getUrl(),
		];
	}

	private function formatDate(?\DateTime $date): ?string {
		return $date?->format(\DateTimeInterface::RFC3339);
	}

	/**
	 * formatOverviewEntry serialises one share plus its resolved node for the
	 * shares-overview view, camelCase. `permissions` is the sharing mode (0..3),
	 * NOT an NC bitmask.
	 *
	 * @return array<string,mixed>
	 */
	private function formatOverviewEntry(AtriumShare $share, Node $node, string $ownerUid): array {
		return [
			'id' => $share->getId(),
			'recipientEmail' => $share->getRecipientEmail(),
			'permissions' => $share->getPermissions(),
			'maxDownloads' => $share->getMaxDownloads(),
			'downloadCount' => $share->getDownloadCount(),
			'expiresAt' => $this->formatDate($share->getExpiresAt()),
			'createdAt' => $this->formatDate($share->getCreatedAt()),
			'emailSent' => $share->getEmailSent(),
			'fileId' => $node->getId(),
			'path' => $this->relativePath($node, $ownerUid),
			'name' => $node->getName(),
			'isFolder' => !($node instanceof File),
			'mtime' => $node->getMTime(),
			'size' => $node->getSize(),
			'mimetype' => $node->getMimetype(),
		];
	}

	/**
	 * relativePath returns the node path relative to the owner's files root
	 * (`/{uid}/files`), the shape the WebDAV DAV root expects. The bare name is a
	 * safe fallback if the path unexpectedly lacks that prefix.
	 */
	private function relativePath(Node $node, string $ownerUid): string {
		$prefix = '/' . $ownerUid . '/files';
		$path = $node->getPath();
		if (str_starts_with($path, $prefix)) {
			return ltrim(substr($path, strlen($prefix)), '/');
		}
		return $node->getName();
	}
}
