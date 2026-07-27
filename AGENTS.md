# atrium-plugin-nextcloud agent guide

Nextcloud app that integrates **Atrium Secureshare** into the native Nextcloud
Files sharing sidebar. An internal user creates an external, identity-bound share
(recipient identified by email); the Atrium core gateway governs access. It needs
the core running as a separate deployment. Published open source under **AGPLv3**.

[README.md](README.md) is the product overview and the case for Atrium.
[docs/internals.md](docs/internals.md) covers the internals and invariants (trust
boundary, persistence, folder modes, the native indicator, admin, retention).
Read it before changing behaviour in those areas, since it records decisions that
must not silently regress. This file covers only how to navigate, build,
test and release.

The app id is `atrium_secureshare`, the PHP namespace is `OCA\AtriumSecureShare`,
and it targets Nextcloud 34 (min 34, max 34, tracking the tested env).

## Tech stack

- **Backend:** PHP on the Nextcloud App Framework (`OCP\AppFramework`); classes
  under `lib/` autoload via the namespace in `appinfo/info.xml`. Composer manages
  PHP deps (`firebase/php-jwt` for ES256 verification). `vendor/` is git-ignored
  and rebuilt at build time; `composer.json`/`composer.lock` pin it.
- **Frontend:** Vue 3 + TypeScript via webpack (`@nextcloud/webpack-vue-config`),
  `@nextcloud/vue` components. `.npmrc` sets `legacy-peer-deps=true` so
  `npm install`/`npm ci` resolve the known-good tree.

## Layout

```
appinfo/info.xml            # app metadata, licence, NC version dependency, share-provider <types>
appinfo/routes.php          # status ping + /api/v1/* core-facing API + ocs sidebar API
lib/AppInfo/Application.php  # IBootstrap entry point (loads vendor/, registers middleware + listener + share provider)
lib/Controller/             # Status, Health, Shares [core], SidebarShare [sidebar], AdminSettings
lib/Middleware/             # CoreAuthMiddleware: enforces the signed-token trust boundary
lib/Listener/               # LoadSidebarScriptListener: injects the bundle into the Files app
lib/Share/                  # AtriumShareProvider: native IShareProvider driving the "Shared" indicator
lib/Service/                # JWTValidator, ShareService, FolderService, MailService, AdminConfigService, …
lib/Db/                     # AtriumShare/AtriumUpload entities + mappers
lib/Migration/              # Doctrine migrations (atrium_shares / atrium_uploads schema)
lib/BackgroundJob/          # RetentionCleanupJob (daily purge past the grace window)
src/                        # Vue/TS frontend; entries: atrium-sharing.ts (sidebar), admin/main.ts (settings)
tests/                      # PHPUnit unit tests (trust boundary + sidebar controller)
```

Create `lib/Db/` and `lib/Service/` classes only when a feature needs them. Do
not add empty placeholders.

## Build & test

Frontend (Node). The compiled `js/` and browser catalogs `l10n/*.js` are
git-ignored and produced at build time; `l10n/*.json` is the versioned source:

```bash
npm install
npm run build        # gen l10n/*.js from l10n/*.json, then webpack build -> js/
npm run dev          # webpack --watch
npm run l10n:build   # regenerate l10n/*.js from l10n/*.json only
```

PHP (backend):

```bash
composer install     # deps incl. dev (phpunit, nextcloud/ocp stubs)
vendor/bin/phpunit   # run the unit tests (tests/Unit)
php -l lib/**/*.php   # lint
```

Toolchain: PHP >= 8.1 (`composer.json`; CI builds and tests on 8.3), Composer,
and Node 24 (the version CI uses). How you provide them is up to you.

## Packaging & release

The app is distributed through the **Nextcloud App Store**, with release archives
also attached to **GitHub Releases**.

```bash
scripts/package.sh          # -> dist/atrium_secureshare-<version>.tar.gz
```

`package.sh` is the single definition of a release package: the built frontend, a
production `composer install --no-dev` `vendor/` and the generated license
notices, bundled under one top-level `atrium_secureshare/` directory (the layout
Nextcloud expects). To install a build, unpack the archive into the target's apps
path and enable the app.

Versioning is [Conventional Commits](https://www.conventionalcommits.org/) →
release-please (`.github/workflows/release-please.yml`): merging its release PR
tags the release, writes `CHANGELOG.md`, bumps `appinfo/info.xml` (annotated with
`x-release-please-version`) plus `package.json`, and the same workflow then
attaches the built archive to the GitHub Release. `appinfo/info.xml` is the one
source of truth for the app version, so the route-cache footgun below is handled
by the release itself: every release bumps it.

Signing the archive and pushing it to the App Store is a separate process, added
once the app id is registered there.

CI (`.github/workflows/ci.yml`, pull requests only) runs `composer validate`,
`php -l`, PHPUnit and the frontend build (ts-loader type-checks, so a type error
fails it).

To exercise a build on a Nextcloud instance, install the app there and
enable/upgrade it (`occ app:enable atrium_secureshare`). Health checks:
`GET /apps/atrium_secureshare/status` → `{"app":"…","status":"ok"}`, and the
trust boundary `GET /apps/atrium_secureshare/api/v1/health` → 200 once
`core_public_key` is set (403 otherwise).

**Two silent footguns:**

- **Editing `routes.php` requires bumping the version in `appinfo/info.xml`.**
  Nextcloud caches the route table; without a version bump the cache serves stale
  routes and new endpoints return `OCS 998`/404. The re-cache happens on the
  version change (app upgrade).
- **Migrations apply through the app upgrade routine, not `migrations:migrate`**
  (this target's `occ` lacks it): disable/enable the app after a version bump.
  Each `changeSchema` is `hasTable`-guarded, so repeating the cycle is safe.

## Critical invariants (see [docs/internals.md](docs/internals.md))

- **`<types><filesystem/></types>` in `info.xml` is REQUIRED**. WebDAV
  (`remote.php`) only boots `filesystem`/`logging` apps, so without it the share
  provider never registers and the native "Shared" indicator stays empty.
- **The core API (`/api/v1/*`) is guarded solely by the signed ES256 token**
  (`CoreAuthMiddleware`); the sidebar/admin surface is session+CSRF gated. Never
  mix the two.
- **`oc_share` is never written; Atrium shares report `TYPE_EMAIL`**. This keeps
  the Atrium sidebar section the single create/manage surface and routes native
  lookups to sharebymail, never here.
- **Revoke is a hard delete; recipient identity is one canonical rule**
  (`EmailCanonicalizer`, NFKC + lower case) everywhere.

## Minimalism ladder

Before implementing anything, walk this ladder top-down and stop at the first
step that applies:

1. Does it need to exist at all? If not, leave it out (YAGNI).
2. Already present in the codebase? Reuse it.
3. Can the platform (Nextcloud / PHP stdlib) do it? Use it.
4. Native platform feature? Use it.
5. Already-installed dependency? Use it.
6. One line? Write one line.
7. Only then: the minimum that works.

