<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\AppInfo;

use OCA\AtriumSecureShare\BackgroundJob\RetentionCleanupJob;
use OCA\AtriumSecureShare\Listener\LoadSidebarScriptListener;
use OCA\AtriumSecureShare\Middleware\CoreAuthMiddleware;
use OCA\AtriumSecureShare\Service\FileResolver;
use OCA\AtriumSecureShare\Service\PersistedShareLookup;
use OCA\AtriumSecureShare\Service\RootFileResolver;
use OCA\AtriumSecureShare\Service\ShareLookup;
use OCA\AtriumSecureShare\Share\AtriumShareProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Share\IManager as IShareManager;

// Guarded so a checkout without `composer install` degrades to a clear
// class-not-found rather than fataling the whole app on load.
$vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
if (is_file($vendorAutoload)) {
	require_once $vendorAutoload;
}

/**
 * Application bootstraps the Atrium Secureshare Nextcloud app.
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'atrium_secureshare';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(ShareLookup::class, PersistedShareLookup::class);
		$context->registerServiceAlias(FileResolver::class, RootFileResolver::class);
		$context->registerMiddleware(CoreAuthMiddleware::class);

		// Bound by FQCN: the event ships with the Files app, not OCP, so there is
		// no load-time dependency on it here.
		$context->registerEventListener(
			'OCA\\Files\\Event\\LoadAdditionalScriptsEvent',
			LoadSidebarScriptListener::class,
		);
	}

	public function boot(IBootContext $context): void {
		// Register the native share provider so Nextcloud renders its own "Shared"
		// indicator for Atrium shares (read-only/indicator-only, see AtriumShareProvider).
		$context->injectFn(static function (IShareManager $shareManager): void {
			$shareManager->registerShareProvider(AtriumShareProvider::class);
		});

		// IJobList::add is idempotent, so booting repeatedly never registers it twice.
		$context->injectFn(static function (IJobList $jobList): void {
			$jobList->add(RetentionCleanupJob::class);
		});
	}
}
