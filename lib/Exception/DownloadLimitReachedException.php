<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Exception;

use RuntimeException;

/**
 * DownloadLimitReachedException signals that a share's download cap was reached.
 * It is raised by the atomic check-and-increment in ShareService (the guarded
 * UPDATE affected no row) and mapped to HTTP 410, so the stream is never served
 * past the cap.
 */
class DownloadLimitReachedException extends RuntimeException {
}
