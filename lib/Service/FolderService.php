<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Service;

use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Db\AtriumUpload;
use OCA\AtriumSecureShare\Db\AtriumUploadMapper;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * FolderService is the folder-share domain logic: it browses a folder's contents
 * filtered by the share's mode, resolves a child for download under the same
 * rules, and stores uploads. It is the sole place that turns the entity's
 * capabilities (canRead/canReadAll/canWrite) into filesystem effects:
 *
 *  - Read-Only        list + download all, no upload
 *  - Write/Read-Own   upload + list/download only the recipient's own uploads
 *  - Write/Read-All   upload + list/download everything
 *  - Dropzone         upload only, no listing, no download
 *
 * Paths are resolved strictly inside the shared folder: a `..` segment is
 * rejected and the resolved node is re-checked to be a descendant of the share
 * root, so a crafted path can never escape. Own-visibility and stable display
 * names come from atrium_uploads, never the file name.
 */
class FolderService {
	public function __construct(
		private readonly FileResolver $fileResolver,
		private readonly AtriumUploadMapper $uploadMapper,
	) {
	}

	/**
	 * browse resolves a relative path within the shared folder and returns either
	 * the folder's mode-filtered listing or, when the path points at a file, that
	 * file's entry. Returns null when the path escapes the share or does not resolve
	 * (clean 404, no oracle). A non-readable share yields an empty listing.
	 *
	 * @return array{is_file: bool, entries?: array<int,array<string,mixed>>, entry?: array<string,mixed>}|null
	 */
	public function browse(AtriumShare $share, string $path = ''): ?array {
		if (!$share->canRead()) {
			return ['is_file' => false, 'entries' => []];
		}
		$root = $this->resolveFolder($share);
		if ($root === null) {
			return ['is_file' => false, 'entries' => []];
		}
		$node = $this->resolveWithin($root, $path);
		if ($node === null) {
			return null;
		}
		if ($node instanceof File) {
			$entry = $this->fileEntry($share, $node);
			return $entry === null ? null : ['is_file' => true, 'entry' => $entry];
		}
		if (!$node instanceof Folder) {
			return null;
		}
		return ['is_file' => false, 'entries' => $this->listNodes($share, $node)];
	}

	/**
	 * resolveChild returns a downloadable file within the shared folder, or null
	 * when the share may not read it, the id is not a file inside the shared folder,
	 * or (in Write/Read-Own) it was not uploaded by the recipient. Containment is
	 * checked with the share root's relative-path test, so a crafted id can never
	 * reach a file outside the folder.
	 */
	public function resolveChild(AtriumShare $share, int $fileId): ?File {
		if (!$share->canRead()) {
			return null;
		}
		$root = $this->resolveFolder($share);
		if ($root === null) {
			return null;
		}
		$node = $root->getById($fileId)[0] ?? null;
		if (!$node instanceof File || $root->getRelativePath($node->getPath()) === null) {
			return null;
		}
		if (!$share->canReadAll() && !$this->isOwnedByRecipient($share, $fileId)) {
			return null;
		}
		return $node;
	}

	/**
	 * handleUpload stores an uploaded stream into the sub-folder selected by $path.
	 * A re-upload of the recipient's own file (same original name in the same
	 * folder) overwrites it in place; otherwise a fresh file is created under a
	 * collision-free name. An existing file (whoever owns it) is never overwritten,
	 * so one recipient can never clobber another's upload.
	 *
	 * @param resource $stream the upload body
	 * @throws NotPermittedException when the share's mode forbids writing or the target is unwritable
	 * @throws NotFoundException when the shared folder or the sub-path is not resolvable
	 * @throws \InvalidArgumentException when the file name is empty or unsafe
	 */
	public function handleUpload(AtriumShare $share, string $uploaderEmail, $stream, string $filename, string $path = ''): File {
		if (!$share->canWrite()) {
			throw new NotPermittedException('share mode does not allow uploads');
		}
		$root = $this->resolveFolder($share);
		if ($root === null) {
			throw new NotFoundException('shared folder is not resolvable');
		}
		$folder = $this->resolveWithin($root, $path);
		if (!$folder instanceof Folder) {
			throw new NotFoundException('upload target folder is not resolvable');
		}
		$name = $this->sanitizeName($filename);
		$uploader = EmailCanonicalizer::canonical($uploaderEmail);

		$existing = $this->uploadMapper->findOwnUpload($share->getId(), $uploader, $name);
		if ($existing !== null) {
			// Only overwrite when the tracked node still lives in this very folder;
			// getById is scoped to $folder, so an own upload of the same name in a
			// different sub-folder is left alone and a fresh file is created here.
			$node = $folder->getById($existing->getFileId())[0] ?? null;
			if ($node instanceof File) {
				$node->putContent($stream);
				$existing->setUploadedAt(new \DateTime());
				$this->uploadMapper->update($existing);
				return $node;
			}
		}

		$node = $folder->newFile($this->resolveCollision($folder, $name), $stream);

		$upload = new AtriumUpload();
		$upload->setShareId($share->getId());
		$upload->setFileId($node->getId());
		$upload->setUploaderEmail($uploader);
		$upload->setOriginalName($name);
		$upload->setUploadedAt(new \DateTime());
		$this->uploadMapper->insert($upload);
		return $node;
	}

	/**
	 * listNodes projects a folder's directory listing to the recipient-visible
	 * entries, applying the mode filter: Write/Read-Own hides everything the
	 * recipient did not upload (so sub-folders, never uploads, stay hidden — that
	 * mode is intentionally flat).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function listNodes(AtriumShare $share, Folder $folder): array {
		$children = $folder->getDirectoryListing();
		$uploads = $this->indexByFileId($children);
		$recipient = EmailCanonicalizer::canonical($share->getRecipientEmail());

		$entries = [];
		foreach ($children as $node) {
			$upload = $uploads[$node->getId()] ?? null;
			$isOwn = $upload !== null && EmailCanonicalizer::canonical($upload->getUploaderEmail()) === $recipient;
			if (!$share->canReadAll() && !$isOwn) {
				continue;
			}
			$entries[] = $this->entryFor($node, $upload, $isOwn);
		}
		return $entries;
	}

	/**
	 * fileEntry projects a single file reached by path to its recipient-visible
	 * entry, or null when Write/Read-Own and it is not the recipient's own upload
	 * (a deep-link must obey the same visibility as the listing).
	 *
	 * @return array<string,mixed>|null
	 */
	private function fileEntry(AtriumShare $share, File $node): ?array {
		$upload = $this->uploadMapper->findByFileIds([$node->getId()])[0] ?? null;
		$isOwn = $upload !== null
			&& EmailCanonicalizer::canonical($upload->getUploaderEmail()) === EmailCanonicalizer::canonical($share->getRecipientEmail());
		if (!$share->canReadAll() && !$isOwn) {
			return null;
		}
		return $this->entryFor($node, $upload, $isOwn);
	}

	/**
	 * entryFor builds one recipient-facing entry, preferring the tracked original
	 * upload name over the physical (possibly collision-suffixed) name.
	 *
	 * @return array<string,mixed>
	 */
	private function entryFor(Node $node, ?AtriumUpload $upload, bool $isOwn): array {
		return [
			'id' => (string)$node->getId(),
			'name' => $upload?->getOriginalName() ?? $node->getName(),
			'size' => $node instanceof File ? $node->getSize() : null,
			'mime_type' => $node->getMimeType(),
			'is_folder' => !($node instanceof File),
			'is_own' => $isOwn,
			'uploaded_at' => $upload?->getUploadedAt()->format(\DateTimeInterface::RFC3339),
		];
	}

	/** isOwnedByRecipient reports whether the tracked upload of $fileId is the recipient's. */
	private function isOwnedByRecipient(AtriumShare $share, int $fileId): bool {
		$upload = $this->uploadMapper->findByFileIds([$fileId])[0] ?? null;
		return $upload !== null
			&& EmailCanonicalizer::canonical($upload->getUploaderEmail()) === EmailCanonicalizer::canonical($share->getRecipientEmail());
	}

	/**
	 * indexByFileId fetches the upload records for the listing's children in one
	 * query and keys them by node id.
	 *
	 * @param \OCP\Files\Node[] $children
	 * @return array<int,AtriumUpload>
	 */
	private function indexByFileId(array $children): array {
		$ids = array_map(static fn($node): int => $node->getId(), $children);
		$byId = [];
		foreach ($this->uploadMapper->findByFileIds($ids) as $upload) {
			$byId[$upload->getFileId()] = $upload;
		}
		return $byId;
	}

	private function resolveFolder(AtriumShare $share): ?Folder {
		$node = $this->fileResolver->resolve($share->getOwnerUid(), $share->getFileId());
		return $node instanceof Folder ? $node : null;
	}

	/**
	 * resolveWithin resolves a relative path inside the share root and returns the
	 * node, or null when the path is unsafe (a `..` segment) or does not resolve.
	 * The empty path is the root itself. As defence in depth the resolved node is
	 * re-checked to be within the root via getRelativePath, so even a backend that
	 * normalised a path oddly cannot hand back a node outside the share.
	 */
	private function resolveWithin(Folder $root, string $path): ?Node {
		$clean = $this->sanitizePath($path);
		if ($clean === null) {
			return null;
		}
		if ($clean === '') {
			return $root;
		}
		try {
			$node = $root->get($clean);
		} catch (NotFoundException) {
			return null;
		}
		return $root->getRelativePath($node->getPath()) === null ? null : $node;
	}

	/**
	 * sanitizePath reduces a client path to a bare relative path of plain segments,
	 * or null on an explicit traversal attempt. Empty and "." segments are dropped;
	 * a ".." segment is refused outright rather than resolved, so the share root is
	 * a hard boundary.
	 */
	private function sanitizePath(string $path): ?string {
		$segments = [];
		foreach (explode('/', trim($path, '/')) as $segment) {
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				return null;
			}
			$segments[] = $segment;
		}
		return implode('/', $segments);
	}

	/**
	 * resolveCollision returns $name if free, else appends the lowest _N that is,
	 * matching Nextcloud's own copy-rename convention (report.pdf -> report_1.pdf)
	 * so the physical name stays readable to the owner.
	 */
	private function resolveCollision(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$base = pathinfo($name, PATHINFO_FILENAME);
		$ext = pathinfo($name, PATHINFO_EXTENSION);
		$counter = 1;
		do {
			$candidate = $ext !== '' ? "{$base}_{$counter}.{$ext}" : "{$base}_{$counter}";
			$counter++;
		} while ($folder->nodeExists($candidate));
		return $candidate;
	}

	/**
	 * sanitizeName reduces a client-supplied name to a bare, safe file name. The
	 * core forwards the name verbatim, so the plugin is the trust boundary for it.
	 *
	 * @throws \InvalidArgumentException when nothing safe remains
	 */
	private function sanitizeName(string $filename): string {
		$name = trim(basename($filename));
		if ($name === '' || $name === '.' || $name === '..') {
			throw new \InvalidArgumentException('invalid file name');
		}
		return $name;
	}
}
