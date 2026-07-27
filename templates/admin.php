<?php

declare(strict_types=1);

use OCA\AtriumSecureShare\AppInfo\Application;
use OCP\Util;

// The bundle mounts into #atrium-admin-settings and reads the seeded `adminConfig`
// initial state (see AdminSettings::getForm).
Util::addScript(Application::APP_ID, 'atrium-admin');
?>

<div id="atrium-admin-settings" class="section"></div>
