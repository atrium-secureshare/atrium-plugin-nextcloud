<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Tests\Unit;

use OCA\AtriumSecureShare\BackgroundJob\RetentionCleanupJob;
use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RetentionCleanupJobTest extends TestCase {
	#[DataProvider('retentionWindows')]
	public function testDeletesUsingNowMinusRetentionWindow(int $retentionDays, string $expectedCutoff): void {
		$now = new \DateTime('2026-07-10 12:00:00');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getDateTime')->willReturn($now);

		$config = $this->createStub(AdminConfigService::class);
		$config->method('getRetentionDays')->willReturn($retentionDays);

		$mapper = $this->createMock(AtriumShareMapper::class);
		$mapper->expects(self::once())
			->method('deleteRetiredBefore')
			->with(self::callback(
				static fn(\DateTime $cutoff): bool => $cutoff->format('Y-m-d H:i:s') === $expectedCutoff,
			))
			->willReturn(0);

		$this->invokeRun(new RetentionCleanupJob($time, $mapper, $config, $this->createStub(LoggerInterface::class)));
	}

	/** @return array<string,array{0:int,1:string}> */
	public static function retentionWindows(): array {
		return [
			'seven-day window' => [7, '2026-07-03 12:00:00'],
			'zero retention purges from now' => [0, '2026-07-10 12:00:00'],
		];
	}

	/** run invokes the job's protected run() the scheduler would call. */
	private function invokeRun(RetentionCleanupJob $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}
}
