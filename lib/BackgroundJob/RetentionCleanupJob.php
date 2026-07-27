<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\BackgroundJob;

use OCA\AtriumSecureShare\Db\AtriumShareMapper;
use OCA\AtriumSecureShare\Service\AdminConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * RetentionCleanupJob hard-deletes expired/exhausted shares once their retention
 * grace window has elapsed, so recipient PII is not kept indefinitely. It is
 * time-insensitive: access already ends the instant a share expires or hits its
 * cap, so this job only frees the storage afterwards and a delayed run is harmless.
 */
class RetentionCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private readonly AtriumShareMapper $mapper,
		private readonly AdminConfigService $config,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$cutoff = $this->time->getDateTime()->modify('-' . $this->config->getRetentionDays() . ' days');
		$deleted = $this->mapper->deleteRetiredBefore($cutoff);
		if ($deleted > 0) {
			$this->logger->info('atrium retention cleanup removed retired shares', ['deleted' => $deleted]);
		}
	}
}
