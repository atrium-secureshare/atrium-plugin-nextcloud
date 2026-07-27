<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests;

use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @mixin \PHPUnit\Framework\TestCase
 */
trait MocksL10N {
	private function mockL10N(): IL10N&MockObject {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return $l;
	}
}
