<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Db\AtriumUpload;
use OCA\AtriumSecureShare\Db\AtriumUploadMapper;
use OCA\AtriumSecureShare\Service\FileResolver;
use OCA\AtriumSecureShare\Service\FolderService;
use OCA\AtriumSecureShare\Tests\MocksNodes;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use PHPUnit\Framework\TestCase;

final class FolderServiceTest extends TestCase {
	use MocksNodes;

	public function testReadAllListsEveryChildAndPrefersOriginalName(): void {
		// Owner's own file (no upload record) + one uploaded file whose physical
		// name was suffixed on collision; the recipient must see the original name.
		$ownerFile = $this->fileNode(1, 'owner.txt');
		$uploaded = $this->fileNode(2, 'report_1.pdf');
		$folder = $this->folderNode(10, [$ownerFile, $uploaded]);

		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findByFileIds')->with([1, 2])->willReturn([
			$this->upload(2, 'carol@example.com', 'report.pdf'),
		]);

		$service = new FolderService($this->resolverReturning($folder), $uploads);
		$result = $service->browse($this->share(AtriumShare::MODE_WRITE_ALL, 'bob@example.com'));

		self::assertFalse($result['is_file']);
		$entries = $result['entries'];
		self::assertCount(2, $entries);
		self::assertSame('owner.txt', $entries[0]['name']);
		self::assertSame('report.pdf', $entries[1]['name']); // original, not report_1.pdf
		self::assertFalse($entries[1]['is_own']); // uploaded by carol, listed for bob
	}

	public function testWriteOwnListsOnlyRecipientUploads(): void {
		$mine = $this->fileNode(1, 'mine.pdf');
		$others = $this->fileNode(2, 'others.pdf');
		$ownerFile = $this->fileNode(3, 'owner.txt');
		$folder = $this->folderNode(10, [$mine, $others, $ownerFile]);

		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findByFileIds')->willReturn([
			$this->upload(1, 'bob@example.com', 'mine.pdf'),
			$this->upload(2, 'carol@example.com', 'others.pdf'),
		]);

		$service = new FolderService($this->resolverReturning($folder), $uploads);
		$result = $service->browse($this->share(AtriumShare::MODE_WRITE_OWN, 'bob@example.com'));

		$entries = $result['entries'];
		self::assertCount(1, $entries);
		self::assertSame('mine.pdf', $entries[0]['name']);
		self::assertTrue($entries[0]['is_own']);
	}

	public function testDropzoneListsNothing(): void {
		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->expects(self::never())->method('findByFileIds');
		// Resolver must never even be consulted for a dropzone listing.
		$resolver = $this->createMock(FileResolver::class);
		$resolver->expects(self::never())->method('resolve');

		$service = new FolderService($resolver, $uploads);
		self::assertSame(
			['is_file' => false, 'entries' => []],
			$service->browse($this->share(AtriumShare::MODE_DROPZONE, 'bob@example.com')),
		);
	}

	public function testBrowseListsSubFolderReachedByPath(): void {
		$child = $this->fileNode(2, 'nested.pdf');
		$sub = $this->folderNode(20, [$child]);
		$root = $this->folderNode(10, []);
		$root->method('get')->with('docs/sub')->willReturn($sub);
		$root->method('getRelativePath')->willReturn('docs/sub');
		$sub->method('getPath')->willReturn('/alice/files/share/docs/sub');

		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findByFileIds')->willReturn([]);

		$service = new FolderService($this->resolverReturning($root), $uploads);
		$result = $service->browse($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), 'docs/sub');

		self::assertFalse($result['is_file']);
		self::assertCount(1, $result['entries']);
		self::assertSame('nested.pdf', $result['entries'][0]['name']);
	}

	public function testBrowseReturnsFileEntryForFilePath(): void {
		$file = $this->fileNode(7, 'deep.pdf');
		$file->method('getPath')->willReturn('/alice/files/share/docs/deep.pdf');
		$root = $this->folderNode(10, []);
		$root->method('get')->with('docs/deep.pdf')->willReturn($file);
		$root->method('getRelativePath')->willReturn('docs/deep.pdf');

		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findByFileIds')->with([7])->willReturn([]);

		$service = new FolderService($this->resolverReturning($root), $uploads);
		$result = $service->browse($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), 'docs/deep.pdf');

		self::assertTrue($result['is_file']);
		self::assertSame('deep.pdf', $result['entry']['name']);
		self::assertSame('7', $result['entry']['id']);
	}

	public function testBrowseRejectsTraversalPath(): void {
		$root = $this->folderNode(10, []);
		// A `..` segment must be refused before ever touching the filesystem.
		$root->expects(self::never())->method('get');

		$service = new FolderService($this->resolverReturning($root), $this->createMock(AtriumUploadMapper::class));
		self::assertNull($service->browse($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), '../secrets'));
	}

	public function testBrowseRejectsNodeThatEscapesRoot(): void {
		// Even if the backend resolved a node, a path outside the root (null
		// relative path) is refused as defence in depth.
		$stray = $this->fileNode(9, 'stray.pdf');
		$stray->method('getPath')->willReturn('/alice/files/other/stray.pdf');
		$root = $this->folderNode(10, []);
		$root->method('get')->with('x')->willReturn($stray);
		$root->method('getRelativePath')->willReturn(null);

		$service = new FolderService($this->resolverReturning($root), $this->createMock(AtriumUploadMapper::class));
		self::assertNull($service->browse($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), 'x'));
	}

	public function testBrowseReturnsNullWhenPathMissing(): void {
		$root = $this->folderNode(10, []);
		$root->method('get')->willThrowException(new NotFoundException('gone'));

		$service = new FolderService($this->resolverReturning($root), $this->createMock(AtriumUploadMapper::class));
		self::assertNull($service->browse($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), 'missing'));
	}

	public function testResolveChildRejectsForeignUploadInWriteOwn(): void {
		$child = $this->fileNode(2, 'others.pdf');
		$child->method('getPath')->willReturn('/alice/files/share/others.pdf');
		$folder = $this->folderNode(10, []);
		$folder->method('getById')->with(2)->willReturn([$child]);
		$folder->method('getRelativePath')->willReturn('others.pdf');

		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findByFileIds')->with([2])->willReturn([
			$this->upload(2, 'carol@example.com', 'others.pdf'),
		]);

		$service = new FolderService($this->resolverReturning($folder), $uploads);
		self::assertNull($service->resolveChild($this->share(AtriumShare::MODE_WRITE_OWN, 'bob@example.com'), 2));
	}

	public function testResolveChildRejectsNodeOutsideFolder(): void {
		$stray = $this->fileNode(9, 'stray.pdf');
		$stray->method('getPath')->willReturn('/alice/files/other/stray.pdf');
		$folder = $this->folderNode(10, []);
		$folder->method('getById')->with(9)->willReturn([$stray]);
		// A node outside the share root has no relative path to it.
		$folder->method('getRelativePath')->willReturn(null);

		$service = new FolderService($this->resolverReturning($folder), $this->createMock(AtriumUploadMapper::class));
		self::assertNull($service->resolveChild($this->share(AtriumShare::MODE_WRITE_ALL, 'bob@example.com'), 9));
	}

	public function testResolveChildAcceptsNestedDescendant(): void {
		// A file deep inside a sub-folder is downloadable in a read-all share.
		$nested = $this->fileNode(3, 'nested.pdf');
		$nested->method('getPath')->willReturn('/alice/files/share/docs/nested.pdf');
		$folder = $this->folderNode(10, []);
		$folder->method('getById')->with(3)->willReturn([$nested]);
		$folder->method('getRelativePath')->willReturn('docs/nested.pdf');

		$service = new FolderService($this->resolverReturning($folder), $this->createMock(AtriumUploadMapper::class));
		self::assertSame($nested, $service->resolveChild($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), 3));
	}

	public function testUploadRejectedWhenModeIsReadOnly(): void {
		$service = new FolderService($this->createMock(FileResolver::class), $this->createMock(AtriumUploadMapper::class));
		$this->expectException(NotPermittedException::class);
		$service->handleUpload($this->share(AtriumShare::MODE_READ_ONLY, 'bob@example.com'), 'bob@example.com', $this->emptyStream(), 'x.pdf');
	}

	public function testUploadCreatesCollisionFreeFileAndRecords(): void {
		$folder = $this->folderNode(10, []);
		// report.pdf is taken, report_1.pdf is free.
		$folder->method('nodeExists')->willReturnMap([['report.pdf', true], ['report_1.pdf', false]]);
		$created = $this->fileNode(5, 'report_1.pdf');
		// The stream must reach the cache-consistent newFile(path, content) API,
		// not a separate raw write that leaves oc_filecache at size 0.
		$folder->expects(self::once())->method('newFile')->with('report_1.pdf', self::isStream())->willReturn($created);

		$uploads = $this->newUploadMapper();

		$service = new FolderService($this->resolverReturning($folder), $uploads);
		$result = $service->handleUpload($this->share(AtriumShare::MODE_WRITE_ALL, 'bob@example.com', 7), 'bob@example.com', $this->emptyStream(), 'report.pdf');
		self::assertSame($created, $result);
	}

	public function testUploadPropagatesContentSizeToCache(): void {
		// The 0-byte bug was newFile()+raw fopen('w'), which
		// wrote the disk but left oc_filecache at size 0. The upload body must reach
		// newFile(path, content) so Nextcloud propagates the size — the created node
		// then reports the real content length, not 0.
		$content = 'Hello, this is upload content of a known size.';
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, $content);
		rewind($stream);

		$folder = $this->folderNode(10, []);
		$folder->method('nodeExists')->willReturn(false);
		$created = $this->createMock(File::class);
		$created->method('getId')->willReturn(5);
		$created->method('getName')->willReturn('report.pdf');
		$created->method('getSize')->willReturn(strlen($content));
		$folder->expects(self::once())
			->method('newFile')
			->with('report.pdf', self::isStream())
			->willReturn($created);

		$uploads = $this->newUploadMapper();

		$service = new FolderService($this->resolverReturning($folder), $uploads);
		$result = $service->handleUpload($this->share(AtriumShare::MODE_WRITE_ALL, 'bob@example.com', 7), 'bob@example.com', $stream, 'report.pdf');

		self::assertSame(strlen($content), $result->getSize());
	}

	public function testUploadOverwritesRecipientOwnFileInsteadOfDuplicating(): void {
		$existingNode = $this->fileNode(5, 'report.pdf');
		// An overwrite must go through putContent (cache-consistent), never a raw
		// stream write that would leave the cached size stale.
		$existingNode->expects(self::once())->method('putContent')->with(self::isStream());
		$folder = $this->folderNode(10, []);
		$folder->method('getById')->with(5)->willReturn([$existingNode]);
		$folder->expects(self::never())->method('newFile');

		$record = $this->upload(5, 'bob@example.com', 'report.pdf');
		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findOwnUpload')->with(7, 'bob@example.com', 'report.pdf')->willReturn($record);
		$uploads->expects(self::once())->method('update')->with($record);
		$uploads->expects(self::never())->method('insert');

		$service = new FolderService($this->resolverReturning($folder), $uploads);
		$result = $service->handleUpload($this->share(AtriumShare::MODE_WRITE_OWN, 'bob@example.com', 7), 'bob@example.com', $this->emptyStream(), 'report.pdf');
		self::assertSame($existingNode, $result);
	}

	public function testUploadIntoSubFolderResolvesTargetByPath(): void {
		$sub = $this->folderNode(20, []);
		$sub->method('nodeExists')->willReturn(false);
		$created = $this->fileNode(5, 'note.txt');
		$sub->expects(self::once())->method('newFile')->with('note.txt', self::isStream())->willReturn($created);
		$root = $this->folderNode(10, []);
		$root->method('get')->with('inbox')->willReturn($sub);
		$root->method('getRelativePath')->willReturn('inbox');
		$sub->method('getPath')->willReturn('/alice/files/share/inbox');

		$uploads = $this->newUploadMapper();

		$service = new FolderService($this->resolverReturning($root), $uploads);
		$result = $service->handleUpload($this->share(AtriumShare::MODE_WRITE_ALL, 'bob@example.com', 7), 'bob@example.com', $this->emptyStream(), 'note.txt', 'inbox');
		self::assertSame($created, $result);
	}

	public function testUploadRejectsTraversalPath(): void {
		$root = $this->folderNode(10, []);
		$root->expects(self::never())->method('get');

		$service = new FolderService($this->resolverReturning($root), $this->createMock(AtriumUploadMapper::class));
		$this->expectException(NotFoundException::class);
		$service->handleUpload($this->share(AtriumShare::MODE_WRITE_ALL, 'bob@example.com', 7), 'bob@example.com', $this->emptyStream(), 'note.txt', '../escape');
	}

	private function share(int $mode, string $recipient, int $id = 1): AtriumShare {
		return ShareFactory::make(['id' => $id, 'fileId' => 10, 'recipientEmail' => $recipient, 'permissions' => $mode]);
	}

	private function upload(int $fileId, string $uploader, string $originalName): AtriumUpload {
		$upload = new AtriumUpload();
		$upload->setShareId(1);
		$upload->setFileId($fileId);
		$upload->setUploaderEmail($uploader);
		$upload->setOriginalName($originalName);
		$upload->setUploadedAt(new \DateTime());
		return $upload;
	}

	private function fileNode(int $id, string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn(123);
		$file->method('getMimeType')->willReturn('application/octet-stream');
		return $file;
	}

	/**
	 * @param \OCP\Files\Node[] $children
	 */
	private function folderNode(int $id, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn($id);
		$folder->method('getDirectoryListing')->willReturn($children);
		return $folder;
	}

	/** newUploadMapper is a fresh-upload store: no existing own upload, exactly one insert. */
	private function newUploadMapper(): AtriumUploadMapper {
		$uploads = $this->createMock(AtriumUploadMapper::class);
		$uploads->method('findOwnUpload')->willReturn(null);
		$uploads->expects(self::once())->method('insert')->with(self::isInstanceOf(AtriumUpload::class));
		return $uploads;
	}

	/** @return resource */
	private function emptyStream() {
		return fopen('php://memory', 'r');
	}

	private static function isStream(): \PHPUnit\Framework\Constraint\Callback {
		return self::callback(static fn($arg): bool => is_resource($arg) && get_resource_type($arg) === 'stream');
	}
}
