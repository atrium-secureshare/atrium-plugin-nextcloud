<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\Controller\SharesController;
use OCA\AtriumSecureShare\Db\AtriumShare;
use OCA\AtriumSecureShare\Exception\DownloadLimitReachedException;
use OCA\AtriumSecureShare\Service\CoreContext;
use OCA\AtriumSecureShare\Service\FileResolver;
use OCA\AtriumSecureShare\Service\FolderService;
use OCA\AtriumSecureShare\Service\ShareService;
use OCA\AtriumSecureShare\Tests\MocksNodes;
use OCA\AtriumSecureShare\Tests\ShareFactory;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\StreamResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class SharesControllerTest extends TestCase {
	use MocksNodes;

	public function testRequiredActionMapsEndpointsAndDeniesByDefault(): void {
		$controller = $this->controller(new CoreContext(), $this->createMock(ShareService::class), $this->createMock(FileResolver::class));
		self::assertSame('list-shares', $controller->requiredAction('index'));
		self::assertSame('download', $controller->requiredAction('content'));
		self::assertSame('list-folder', $controller->requiredAction('folder'));
		self::assertSame('download-file', $controller->requiredAction('folderFile'));
		self::assertSame('upload', $controller->requiredAction('upload'));
		self::assertNull($controller->requiredAction('somethingElse'));
	}

	public function testIndexReturnsActiveSharesAsArrayKeyedByToken(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('findByRecipientEmail')->with('bob@example.com')->willReturn([$share]);

		$resolver = $this->resolverReturningFile('report.pdf', 'application/pdf', 10);
		$controller = $this->controller($this->contextFor('bob@example.com', ''), $service, $resolver);

		$response = $controller->index();
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		self::assertCount(1, $data);
		self::assertSame('tokA', $data[0]['id']);
		self::assertSame('bob@example.com', $data[0]['recipient_email']);
		self::assertSame('report.pdf', $data[0]['file_name']);
		self::assertFalse($data[0]['is_folder']);
		// The recipient-facing contract carries the sharing mode (0-3), not a
		// Nextcloud permission bitmask, and no legacy `permissions` key.
		self::assertSame(1, $data[0]['mode']);
		self::assertArrayNotHasKey('permissions', $data[0]);
	}

	public function testContentRejectsPathTokenNotMatchingClaim(): void {
		$service = $this->createMock(ShareService::class);
		$service->expects(self::never())->method('getByToken');
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class));

		$response = $controller->content('tokB');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'share_mismatch'], $response->getData());
	}

	public function testContentReturns410WhenDownloadLimitReached(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->with('tokA')->willReturn($share);
		$service->method('incrementDownloadCount')->willThrowException(new DownloadLimitReachedException('limit'));

		$resolver = $this->resolverReturningFile('report.pdf', 'application/pdf', 10);
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $resolver);

		$response = $controller->content('tokA');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_GONE, $response->getStatus());
		self::assertSame(['error' => 'download_limit_reached'], $response->getData());
	}

	public function testContentReturns404WhenShareGone(): void {
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willThrowException(new DoesNotExistException('gone'));
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class));

		$response = $controller->content('tokA');
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testContentReturns404WhenFileDeleted(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);
		$service->expects(self::never())->method('incrementDownloadCount');

		$resolver = $this->createMock(FileResolver::class);
		$resolver->method('resolve')->willReturn(null);
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $resolver);

		$response = $controller->content('tokA');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'file_not_found'], $response->getData());
	}

	public function testContentStreamsFileAndCountsBeforeServing(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);
		$service->expects(self::once())->method('incrementDownloadCount')->with($share->getId());

		$resolver = $this->resolverReturningFile('report.pdf', 'application/pdf', 1234);
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $resolver);

		$response = $controller->content('tokA');
		self::assertInstanceOf(StreamResponse::class, $response);
		// Response::getHeaders() reaches into the server container (merges CSP etc.),
		// so read the headers we set directly off the response instead.
		$headers = $this->responseHeaders($response);
		self::assertSame('application/pdf', $headers['Content-Type']);
		self::assertSame('1234', $headers['Content-Length']);
		self::assertStringContainsString('attachment; filename="report.pdf"', $headers['Content-Disposition']);
	}

	public function testContentServesEmptyFileWithoutStreaming(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);
		// An empty file is still a download: it must be counted before serving.
		$service->expects(self::once())->method('incrementDownloadCount')->with($share->getId());

		$resolver = $this->resolverReturningFile('empty.md', 'text/markdown', 0);
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $resolver);

		$response = $controller->content('tokA');
		// Not a StreamResponse: NC's StreamResponse rewrites a 0-byte stream to a
		// 400, so an empty file is served as a plain empty 200 with the same headers.
		self::assertNotInstanceOf(StreamResponse::class, $response);
		self::assertInstanceOf(Response::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('', $response->render());
		$headers = $this->responseHeaders($response);
		self::assertSame('text/markdown', $headers['Content-Type']);
		self::assertSame('0', $headers['Content-Length']);
		self::assertStringContainsString('attachment; filename="empty.md"', $headers['Content-Disposition']);
	}

	public function testFolderRejectsPathTokenNotMatchingClaim(): void {
		$service = $this->createMock(ShareService::class);
		$service->expects(self::never())->method('getByToken');
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class));

		$response = $controller->folder('tokB');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'share_mismatch'], $response->getData());
	}

	public function testFolderReturns404WhenShareGone(): void {
		// folder/folderFile/upload share authorizedShare(); a missing share there is
		// a clean 404 share_not_found (no oracle), just like content's inline path.
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willThrowException(new DoesNotExistException('gone'));
		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class));

		$response = $controller->folder('tokA');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'share_not_found'], $response->getData());
	}

	public function testFolderReturnsListingFromService(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->with('tokA')->willReturn($share);

		$result = ['is_file' => false, 'entries' => [['id' => '42', 'name' => 'report.pdf', 'is_folder' => false, 'is_own' => true]]];
		$folder = $this->createMock(FolderService::class);
		$folder->expects(self::once())->method('browse')->with($share, '')->willReturn($result);

		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class), $folder);
		$response = $controller->folder('tokA');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame($result, $response->getData());
	}

	public function testFolderReturns404WhenPathUnresolvable(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->with('tokA')->willReturn($share);

		$folder = $this->createMock(FolderService::class);
		$folder->method('browse')->with($share, '')->willReturn(null);

		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class), $folder);
		$response = $controller->folder('tokA');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'path_not_found'], $response->getData());
	}

	public function testFolderFileReturns404WhenChildNotResolvable(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);
		$folder = $this->createMock(FolderService::class);
		$folder->method('resolveChild')->with($share, 42)->willReturn(null);

		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class), $folder);
		$response = $controller->folderFile('tokA', 42);
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'file_not_found'], $response->getData());
	}

	public function testFolderFileStreamsResolvedChild(): void {
		// folderFile delegates to the same downloadResponse() as content, so the
		// header/empty-file behaviour is covered by the content tests above; here we
		// only assert the folder-specific wiring: the resolved child is what streams.
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);

		$file = $this->fileMock('inner.pdf', 'application/pdf', 7);
		$folder = $this->createMock(FolderService::class);
		$folder->method('resolveChild')->with($share, 42)->willReturn($file);

		$controller = $this->controller($this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class), $folder);
		$response = $controller->folderFile('tokA', 42);
		self::assertInstanceOf(StreamResponse::class, $response);
		self::assertStringContainsString('attachment; filename="inner.pdf"', $this->responseHeaders($response)['Content-Disposition']);
	}

	public function testUploadRejectsMissingFilename(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);
		$folder = $this->createMock(FolderService::class);
		$folder->expects(self::never())->method('handleUpload');

		$controller = $this->controller(
			$this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class),
			$folder, $this->requestWithFilename(''),
		);
		$response = $controller->upload('tokA');
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		self::assertSame(['error' => 'missing_filename'], $response->getData());
	}

	public function testUploadDecodesFilenameAndReturns201(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);

		$file = $this->fileMock('Jahres bericht.pdf', 'application/pdf', 3);
		$folder = $this->createMock(FolderService::class);
		// The percent-encoded header must reach the service decoded.
		$folder->expects(self::once())->method('handleUpload')
			->with($share, 'bob@example.com', self::anything(), 'Jahres bericht.pdf', '')
			->willReturn($file);
		// The stored upload is surfaced in the activity stream (uploader by email).
		$service->expects(self::once())->method('publishUploadActivity')
			->with($share, $file, 'bob@example.com');

		$controller = $this->controller(
			$this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class),
			$folder, $this->requestWithFilename('Jahres%20bericht.pdf'),
		);
		$response = $controller->upload('tokA');
		self::assertInstanceOf(JSONResponse::class, $response);
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testUploadMapsForbiddenModeTo403(): void {
		$share = $this->share('tokA');
		$service = $this->createMock(ShareService::class);
		$service->method('getByToken')->willReturn($share);

		$folder = $this->createMock(FolderService::class);
		$folder->method('handleUpload')->willThrowException(new \OCP\Files\NotPermittedException('read-only'));

		$controller = $this->controller(
			$this->contextFor('bob@example.com', 'tokA'), $service, $this->createMock(FileResolver::class),
			$folder, $this->requestWithFilename('report.pdf'),
		);
		$response = $controller->upload('tokA');
		self::assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		self::assertSame(['error' => 'upload_forbidden'], $response->getData());
	}

	private function requestWithFilename(string $encodedName): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn(string $name): string => $name === 'X-Atrium-Filename' ? $encodedName : '',
		);
		return $request;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function responseHeaders(Response $response): array {
		$property = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		/** @var array<string,mixed> $headers */
		$headers = $property->getValue($response);
		return $headers;
	}

	private function controller(
		CoreContext $context,
		ShareService $service,
		FileResolver $resolver,
		?FolderService $folder = null,
		?IRequest $request = null,
	): SharesController {
		return new SharesController(
			$request ?? $this->createMock(IRequest::class),
			$service,
			$context,
			$resolver,
			$folder ?? $this->createMock(FolderService::class),
		);
	}

	private function contextFor(string $email, string $shareId): CoreContext {
		$context = new CoreContext();
		$context->setClaims(['email' => $email, 'share_id' => $shareId]);
		return $context;
	}

	private function resolverReturningFile(string $name, string $mime, int $size): FileResolver {
		return $this->resolverReturning($this->fileMock($name, $mime, $size));
	}

	private function share(string $token): AtriumShare {
		return ShareFactory::make(['token' => $token, 'id' => 7, 'fileId' => 99, 'emailSent' => true]);
	}
}
