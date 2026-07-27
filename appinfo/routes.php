<?php

declare(strict_types=1);

// `url` values are app-relative: Nextcloud prepends `/apps/atrium_secureshare`,
// so the generic-looking paths cannot collide with core or other apps. /status is
// the public install probe; /api/v1/* is the signed-token core-facing API guarded
// by CoreAuthMiddleware. The `ocs` entries are the session-authenticated sidebar
// API, served in a SEPARATE URL space (/ocs/v2.php/apps/...) behind Nextcloud's
// login + CSRF gate, so the identical-looking path never collides with the
// signed-token core route and is not touched by CoreAuthMiddleware.
return [
	'routes' => [
		['name' => 'status#index', 'url' => '/status', 'verb' => 'GET'],
		['name' => 'health#index', 'url' => '/api/v1/health', 'verb' => 'GET'],
		['name' => 'shares#index', 'url' => '/api/v1/shares', 'verb' => 'GET'],
		['name' => 'shares#content', 'url' => '/api/v1/shares/{shareId}/content', 'verb' => 'GET'],
		['name' => 'shares#folder', 'url' => '/api/v1/shares/{shareId}/folder', 'verb' => 'GET'],
		['name' => 'shares#folderFile', 'url' => '/api/v1/shares/{shareId}/folder/{fileId}/content', 'verb' => 'GET'],
		['name' => 'shares#upload', 'url' => '/api/v1/shares/{shareId}/upload', 'verb' => 'POST'],
		// Admin settings API (session + CSRF + AuthorizedAdminSetting, NOT the
		// signed-token boundary).
		['name' => 'adminSettings#update', 'url' => '/admin/settings', 'verb' => 'PUT'],
	],
	'ocs' => [
		['name' => 'sidebarShare#index', 'url' => '/api/v1/shares', 'verb' => 'GET'],
		['name' => 'sidebarShare#overview', 'url' => '/api/v1/overview', 'verb' => 'GET'],
		['name' => 'sidebarShare#create', 'url' => '/api/v1/shares', 'verb' => 'POST'],
		['name' => 'sidebarShare#update', 'url' => '/api/v1/shares/{id}', 'verb' => 'PUT'],
		['name' => 'sidebarShare#destroy', 'url' => '/api/v1/shares/{id}', 'verb' => 'DELETE'],
		['name' => 'sidebarShare#policy', 'url' => '/api/v1/policy', 'verb' => 'GET'],
	],
];
