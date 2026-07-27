<?php

declare(strict_types=1);

namespace OCA\AtriumSecureShare\Listener;

use OCA\AtriumSecureShare\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * LoadSidebarScriptListener injects the app's frontend bundle whenever the Files
 * app loads its additional scripts, so the Atrium section appears exactly where
 * the native sharing UI is. It binds to LoadAdditionalScriptsEvent by FQCN (the
 * class ships with the Files app, not OCP), so the listener signature stays on
 * the base Event type.
 *
 * @template-implements IEventListener<Event>
 */
class LoadSidebarScriptListener implements IEventListener {
	public function handle(Event $event): void {
		Util::addScript(Application::APP_ID, 'atrium-sharing');
	}
}
