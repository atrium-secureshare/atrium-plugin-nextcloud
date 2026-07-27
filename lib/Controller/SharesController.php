<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Controller;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Exception\DownloadLimitReachedException;
use OCA\AtriumSecureShare\Service\CoreContext;
use OCA\AtriumSecureShare\Service\FileResolver;
use OCA\AtriumSecureShare\Service\FolderService;
use OCA\AtriumSecureShare\Service\ShareService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IRequest;

/**
 * SharesController is the core-facing API for share listing and file download
 * (/api/v1/shares...). Every method is a #[PublicPage]: the signed ES256 token
 * verified by CoreAuthMiddleware is the only credential, and requiredAction()
 * binds each token to exactly its endpoint. The middleware has already
 * re-validated the share before any method here runs.
 */
class SharesController extends Controller implements CoreApiController {
	public function __construct(
		IRequest $request,
		private readonly ShareService $shareService,
		private readonly CoreContext $context,
		private readonly FileResolver $fileResolver,
		private readonly FolderService $folderService,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function requiredAction(string $method): ?string {
		return match ($method) {
			'index' => 'list-shares',
			'content' => 'download',
			'folder' => 'list-folder',
			'folderFile' => 'download-file',
			'upload' => 'upload',
			default => null,
		};
	}

	/**
	 * index lists the recipient's active shares. The recipient identity comes from
	 * the validated token, never a request parameter, so a token can only ever list
	 * its own bearer's shares. The response is a bare JSON array (core contract).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$email = $this->context->getEmail() ?? '';
		$shares = $this->shareService->findByRecipientEmail($email);
		return new JSONResponse(array_map($this->formatShare(...), $shares));
	}

	/**
	 * content streams the shared file. The atomic download counter is incremented
	 * before the stream is served, so a reached cap blocks delivery (410).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function content(string $shareId): Response {
		// The token in the path must match the one the middleware authorized, so a
		// download token minted for one share cannot fetch another.
		if ($shareId !== ($this->context->getShareId() ?? '')) {
			return $this->error(Http::STATUS_FORBIDDEN, 'share_mismatch');
		}

		try {
			$share = $this->shareService->getByToken($shareId);
		} catch (DoesNotExistException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'share_not_found');
		}

		$node = $this->resolveNode($share);
		if (!$node instanceof File) {
			$code = $node === null ? 'file_not_found' : 'not_a_file';
			return $this->error($node === null ? Http::STATUS_NOT_FOUND : Http::STATUS_BAD_REQUEST, $code);
		}

		// Count-and-cap before streaming: past the limit nothing is served.
		try {
			$this->shareService->incrementDownloadCount($share->getId());
		} catch (DownloadLimitReachedException) {
			return $this->error(Http::STATUS_GONE, 'download_limit_reached');
		}

		return $this->downloadResponse($node);
	}

	/**
	 * folder browses the shared folder, filtered by the share's mode. The optional
	 * `path` query parameter selects a sub-folder or file relative to the share
	 * root, resolved traversal-safely (a `..` escape or out-of-folder id yields a
	 * clean 404, no oracle). The response is self-describing:
	 * `{is_file:false, entries:[…]}` for a folder, `{is_file:true, entry:{…}}` for a file.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function folder(string $shareId): Response {
		$share = $this->authorizedShare($shareId);
		if (!$share instanceof AtriumShare) {
			return $share;
		}
		$result = $this->folderService->browse($share, (string)$this->request->getParam('path', ''));
		if ($result === null) {
			return $this->error(Http::STATUS_NOT_FOUND, 'path_not_found');
		}
		return new JSONResponse($result);
	}

	/**
	 * folderFile streams one file from within the shared folder. Folder shares
	 * carry no download cap, so nothing is counted here.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function folderFile(string $shareId, int $fileId): Response {
		$share = $this->authorizedShare($shareId);
		if (!$share instanceof AtriumShare) {
			return $share;
		}
		$file = $this->folderService->resolveChild($share, $fileId);
		if ($file === null) {
			return $this->error(Http::STATUS_NOT_FOUND, 'file_not_found');
		}
		return $this->downloadResponse($file);
	}

	/**
	 * upload streams the request body into the shared folder. The file name comes
	 * from X-Atrium-Filename (percent-encoded so non-ASCII survives an HTTP header).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function upload(string $shareId): Response {
		$share = $this->authorizedShare($shareId);
		if (!$share instanceof AtriumShare) {
			return $share;
		}
		$filename = rawurldecode($this->request->getHeader('X-Atrium-Filename'));
		if (trim($filename) === '') {
			return $this->error(Http::STATUS_BAD_REQUEST, 'missing_filename');
		}
		$stream = fopen('php://input', 'r');
		if ($stream === false) {
			return $this->error(Http::STATUS_BAD_REQUEST, 'no_body');
		}
		$uploaderEmail = $this->context->getEmail() ?? '';
		try {
			$node = $this->folderService->handleUpload($share, $uploaderEmail, $stream, $filename, (string)$this->request->getParam('path', ''));
			// Failure-swallowing, so it never turns a stored upload into an error.
			$this->shareService->publishUploadActivity($share, $node, $uploaderEmail);
		} catch (NotPermittedException) {
			return $this->error(Http::STATUS_FORBIDDEN, 'upload_forbidden');
		} catch (NotFoundException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'folder_not_found');
		} catch (\InvalidArgumentException) {
			return $this->error(Http::STATUS_BAD_REQUEST, 'invalid_filename');
		} finally {
			if (is_resource($stream)) {
				fclose($stream);
			}
		}
		return new JSONResponse([], Http::STATUS_CREATED);
	}

	/**
	 * authorizedShare resolves the share for a folder endpoint, enforcing that the
	 * path token matches the token's share_id claim (so a token minted for one
	 * share cannot act on another). Returns the share, or an error Response.
	 */
	private function authorizedShare(string $shareId): AtriumShare|Response {
		if ($shareId !== ($this->context->getShareId() ?? '')) {
			return $this->error(Http::STATUS_FORBIDDEN, 'share_mismatch');
		}
		try {
			return $this->shareService->getByToken($shareId);
		} catch (DoesNotExistException) {
			return $this->error(Http::STATUS_NOT_FOUND, 'share_not_found');
		}
	}

	/**
	 * formatShare serialises a share for the core (snake_case contract). `id` is
	 * the opaque token, never the internal numeric id; `mode` is the sharing mode
	 * (0-3), not a permission bitmask.
	 *
	 * @return array<string,mixed>
	 */
	private function formatShare(AtriumShare $share): array {
		$node = $this->resolveNode($share);
		$fileName = $node?->getName() ?? '';
		return [
			'id' => $share->getToken(),
			'recipient_email' => $share->getRecipientEmail(),
			'display_name' => $fileName,
			'file_id' => $share->getFileId(),
			'file_name' => $fileName,
			'size' => $node instanceof File ? $node->getSize() : null,
			'is_folder' => $node !== null && !($node instanceof File),
			'mode' => $share->getMode(),
			'max_downloads' => $share->getMaxDownloads(),
			'download_count' => $share->getDownloadCount(),
			'expires_at' => $this->formatDate($share->getExpiresAt()),
			'created_at' => $this->formatDate($share->getCreatedAt()),
			'email_sent' => $share->getEmailSent(),
		];
	}

	/**
	 * downloadResponse serves a file to the caller with the download headers.
	 * Nextcloud's StreamResponse turns an empty file into a 400: Output::setReadfile
	 * returns false when stream_copy_to_stream copies 0 bytes, so
	 * StreamResponse::callback() rewrites the status. A 0-byte file is valid, so
	 * answer it as an empty 200 carrying the same headers instead of streaming it.
	 */
	private function downloadResponse(File $file): Response {
		$headers = [
			'Content-Type' => $file->getMimeType(),
			'Content-Length' => (string)$file->getSize(),
			'Content-Disposition' => 'attachment; filename="' . addcslashes($file->getName(), '"\\') . '"',
		];
		if ($file->getSize() === 0) {
			return new Response(Http::STATUS_OK, $headers);
		}
		return new StreamResponse($file->fopen('r'), Http::STATUS_OK, $headers);
	}

	/**
	 * resolveNode looks up the shared node in the owner's storage. Returns null
	 * when the file was moved out of reach or deleted since the share was made.
	 */
	private function resolveNode(AtriumShare $share): ?Node {
		return $this->fileResolver->resolve($share->getOwnerUid(), $share->getFileId());
	}

	private function formatDate(?\DateTime $date): ?string {
		return $date?->format(\DateTimeInterface::RFC3339);
	}

	private function error(int $status, string $code): JSONResponse {
		return new JSONResponse(['error' => $code], $status);
	}
}
