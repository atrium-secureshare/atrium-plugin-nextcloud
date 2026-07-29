# Plugin internals & invariants

How the plugin works and the decisions that must not silently regress. For the
product overview see [README.md](README.md); for build/test/release see
[AGENTS.md](AGENTS.md).

The app id is `atrium_secureshare` and the PHP namespace is
`OCA\AtriumSecureShare`. It targets Nextcloud 34 (min 34, max 34, tracking the
tested env).

The backend is PHP on the Nextcloud App Framework (`OCP\AppFramework`); the
frontend is Vue 2.7 + TypeScript bundled with webpack. Two surfaces exist, kept
strictly separate: a **signed-token core API** (`/api/v1/*`, the core is the only
caller) and a **session-authenticated sidebar/admin UI** (the logged-in
Nextcloud user).

## Core ↔ plugin trust boundary

The plugin enforces the trust boundary the core signs into every request. The
core is a confidential client that mints a short-lived **ES256** JWT per call;
this app verifies it before any core-facing endpoint runs.

- **Guarded surface:** every controller implementing
  `Controller\CoreApiController` (routed under `/api/v1/*`) sits behind
  `Middleware\CoreAuthMiddleware`. Controllers are `#[PublicPage]` (no NC user
  session). The signed token is the only credential, so a 200 proves trust.
- **`Service\JWTValidator`** decodes **only** with an ES256 `Key`, so `HS256`/
  `none` downgrades are structurally rejected. It pins `iss=atrium-core`,
  `aud=atrium-plugin-nextcloud`, applies a 5s leeway for `exp`/`iat`, requires
  integer `exp`/`iat` claims (a token without `exp` would never expire) and caps
  the remaining validity at 60s. The core public key comes from app config
  `atrium_secureshare core_public_key`.
- **Middleware** re-validates share-scoped tokens server-side: unknown/expired
  share → `404 share_not_found` (no oracle; a revoked share no longer exists,
  revocation is a hard delete, so it falls under "unknown"), recipient email
  mismatch → `403 email_mismatch` (via `Service\EmailCanonicalizer`, the single
  recipient-identity rule: NFKC + lower case, matching the core's
  `audit.Canonical`; the same rule is used for storing the recipient email, the
  list-shares lookup and own-upload visibility, so "same recipient" means one
  thing everywhere), action/endpoint mismatch or unmapped method (deny-by-
  default) → `403 action_not_allowed`. Validated claims are exposed to the
  controller via the request-scoped `Service\CoreContext`. `ip`/`xff` are logged
  for audit.
- **`Service\ShareLookup`** is the narrow contract the boundary needs.
  `Service\PersistedShareLookup` (bound in `Application`) resolves the token the
  core carries as `share_id` against `atrium_shares`. `NullShareLookup` remains as
  the fail-closed reference used by the negative test suite.

**Trust setup (one-time, manual).** Install the core's public key so the plugin
can verify its tokens (the core logs this key and the exact command at startup):

```bash
occ config:app:set atrium_secureshare core_public_key --value "$(cat provider-signing.pub)"
```

Without it, `GET /api/v1/health` answers `403 {"error":"trust_not_configured"}`
and the core reports itself degraded until the key is set.

**Negative-test matrix** (`tests/Unit`). Every manipulated token must yield
401/403/404, never 200:

| Manipulation                          | Expected            |
| ------------------------------------- | ------------------- |
| Missing/malformed `Authorization`     | 401 missing_token   |
| HS256 downgrade (pubkey as HMAC secret) | 403 invalid_*     |
| `alg: none`                           | 403 invalid_*       |
| Expired beyond leeway / future `iat`  | 403 token_*         |
| Wrong `iss` / wrong `aud`             | 403 invalid_*       |
| Foreign ES256 key / tampered payload  | 403 invalid_signature |
| Missing core public key               | 403 trust_not_configured |
| Missing `exp` / missing `iat`         | 403 missing_exp / missing_iat |
| Remaining TTL > 60s (over-long token) | 403 ttl_exceeded    |
| Unknown/expired share (revoked = deleted = unknown) | 404 share_not_found |
| Recipient email mismatch (NFKC-normalized compare) | 403 email_mismatch |
| Action ≠ endpoint / unmapped method   | 403 action_not_allowed |

## Share persistence & core API

Shares live in `oc_atrium_shares` (migration `Version000001Date20260701`): each
row binds a Nextcloud node (`file_id`, owned by `owner_uid`) to one
`recipient_email`, reachable through the core via an unguessable 64-char
alphanumeric `token`. Access is bounded by `expires_at` and an optional
`max_downloads` cap (with `download_count`; `last_download_at` records the
last counted download, i.e. the exhaustion instant of a capped share).

- **`Db\AtriumShare` / `AtriumShareMapper`**: entity + QBMapper.
  `AtriumShare::isActive()` (not expired, under the cap) is the single source of
  truth the service and trust boundary consult; `getStatus()` derives the
  owner-facing `active`/`expired`/`exhausted` label from the same fields (no
  extra query). Email lookups compare case-insensitively (`LOWER`).
- **`Service\ShareService`**, domain logic: `createShare` (mints the token,
  normalises the email, optionally sends the invitation), the server-side
  active-only filter (`findByRecipientEmail`), owner-checked `revokeShare`
  (hard delete: the row is removed, no PII lingers), and
  `incrementDownloadCount`, a single guarded `UPDATE ... WHERE download_count <
  max_downloads` (also stamping `last_download_at`) so the cap holds under
  concurrent requests (no read-modify-write TOCTOU). It always runs before the
  stream is served; 0 affected rows → `DownloadLimitReachedException`.
- **`Service\MailService`**: `IMailer` invitation. It carries no token: the link
  points at the Atrium portal (`atrium_secureshare portal_url`, base URL
  fallback) where the recipient authenticates via OIDC. Send failures are logged,
  never propagated; `email_sent` stays false so the UI can flag/resend.
- **`Service\FileResolver`** (`RootFileResolver`): the filesystem seam the
  controller resolves nodes through (`getUserFolder(owner)->getById(fileId)`),
  kept behind an interface because `IRootFolder` can't be exercised in unit
  tests. Any resolution failure → null → clean 404.
- **`Controller\SharesController`**: the core-facing API, matching
  `internal/provider/client.go` in atrium-core:
  - `GET /api/v1/shares` (action `list-shares`) → **bare JSON array** of the
    recipient's active shares. `id` is the token; the core reads `id`,
    `recipient_email`, `display_name`, `expires_at` (extra fields serve the UI).
    The recipient identity comes from the validated token, never a request param.
  - `GET /api/v1/shares/{shareId}/content` (action `download`) → streams the file;
    counts before serving. The path token must equal the token's `share_id`
    claim. Errors: 403 `share_mismatch`, 404 `share_not_found`/`file_not_found`,
    400 `not_a_file`, 410 `download_limit_reached`.
  - `GET /api/v1/shares/{shareId}/folder` (action `list-folder`) → browses the
    folder at the optional `?path=` (relative sub-folder/file, empty = root),
    already filtered by the share's mode. Answers a self-describing object:
    `{is_file:false,entries:[…]}` for a folder, `{is_file:true,entry:{…}}` when the
    path points at a file (resolved by physical or original upload name, so a
    file deep-link resolves in one call). A `..` escape or an unresolvable path is
    a clean `404 path_not_found` (no oracle).
  - `GET /api/v1/shares/{shareId}/folder/{fileId}/content` (action
    `download-file`) → streams one file within the shared folder at any depth
    (descendant + mode-checked; containment via the root's relative-path test).
  - `POST /api/v1/shares/{shareId}/upload` (action `upload`) → stores the request
    body into the `?path=` sub-folder (traversal-safe, empty = root); the name
    comes from `X-Atrium-Filename` (percent-encoded). Errors: 403
    `share_mismatch`/`upload_forbidden`, 404 `share_not_found`/`folder_not_found`,
    400 `missing_filename`/`invalid_filename`.

  The recipient-facing DTO carries `mode` (0-3), the sharing mode, not a
  permission bitmask. The core forwards it to the frontend, which derives
  visibility and upload affordances from it.

## Folder shares (four modes)

A folder share takes one of four modes (stored in the `permissions` column as an
enum, exposed as `mode`; `Db\AtriumShare` capability helpers `canRead`,
`canReadAll`, `canWrite` are the single source of truth):

| Mode | id | List | Download | Upload |
| :--- | :- | :--- | :------- | :----- |
| Read-Only       | 0 | all | all | none |
| Write/Read-Own  | 1 | own uploads | own uploads | yes |
| Write/Read-All  | 2 | all | all | yes |
| Dropzone        | 3 | none | none | yes |

- **`Service\FolderService`** turns those capabilities into filesystem effects:
  `browse($share, $path)` for recursive, mode-filtered listing (returning a file's
  entry when the path resolves to one), a descendant + ownership-checked child
  resolver for download, and upload storage into a sub-path. Every path is
  resolved strictly inside the share root: `sanitizePath` refuses a `..` segment
  and the resolved node is re-checked with `getRelativePath`, so a crafted path or
  id can never escape the shared folder. Read/Read-Own stays flat (sub-folders are
  not uploads, so they never surface there). Folder shares carry no download cap,
  so nothing is counted on a folder-file download.
- **`Db\AtriumUpload` / `AtriumUploadMapper`** (migration
  `Version000001Date20260701`, table `atrium_uploads`) track who uploaded which
  node under which share and the original upload name. This is what makes
  Write/Read-Own visibility work and lets a recipient's re-upload of their own
  file overwrite it (matched on uploader + original name) instead of duplicating.
  Own-visibility and the displayed name come from this table, never from the
  file name, so a collision-suffixed physical name (`report_1.pdf`, resolved the
  way Nextcloud renames copies) is invisible to the recipient, who always sees
  their original name. An existing file is never overwritten across recipients.

## Sidebar integration (session API + Vue section)

The owner creates and manages external shares from the native Files sharing
sidebar. This surface is session-authenticated (the logged-in user), completely
separate from the signed-token core API above.

- **`Controller\SidebarShareController`**: an `OCSController` (NOT a
  `CoreApiController`, so `CoreAuthMiddleware` ignores it). It runs behind
  Nextcloud's normal login + CSRF gate; `#[NoAdminRequired]` lets any logged-in
  user reach it. Routes are the `ocs` entries in `routes.php`, served under
  `/ocs/v2.php/apps/atrium_secureshare/api/v1/shares`, a URL space distinct from
  the plain `/apps/.../api/v1/*` core-facing routes, so the identical path never
  collides. `GET` lists the caller's shares of a node (active + retention-grace,
  see below), `POST` creates, `DELETE /{id}` revokes. Ownership is enforced by
  resolving the node in the caller's own storage (foreign file → 404, no oracle)
  and, on revoke, by `ShareService::revokeShare`'s owner check (403). Input
  validation mirrors the model: files are read-only (mode 0) and may carry a
  download cap; folders take one of the four modes (0 to 3) and no cap; expiry must
  be in the future. DTOs are camelCase for the frontend; `id` is the numeric id
  (the revoke path param), `status` is the retention state, and `shareUrl` is the
  portal from `Service\PortalConfig` (shared with `MailService`).
- **`Service\ShareService::findByFileForOwner($fileId, $ownerUid, $retentionDays)`**:
  the owner-scoped listing the sidebar reads. Unlike the strictly active-only
  `findByRecipientEmail`/`findActiveByOwner`, it also returns expired/exhausted
  shares still inside the retention grace window, each tagged with a `status`, so
  the owner sees why a share stopped and can reactivate it (see Retention policy).
- **`Listener\LoadSidebarScriptListener`**: on the Files app's
  `LoadAdditionalScriptsEvent` (bound by FQCN; the class ships with Files, not
  OCP) it `Util::addScript`s the built bundle, so the section loads exactly where
  the native sharing UI is.
- **Frontend (`src/`)**: `atrium-sharing.ts` defines a custom element
  (`oca_atrium_secureshare-sharing_section`) and registers it via
  `@nextcloud/sharing`'s `registerSidebarSection` (the new sidebar API takes a
  custom-element tag, decoupling the section from Nextcloud's own Vue runtime).
  `custom-element.ts` mounts the Vue 2.7 app (`AtriumSection.vue`) into its light
  DOM (so NC theming applies) and forwards the reactive `node` property.
  `composables/useSharesAPI.ts` is the data layer (list/create/revoke over the
  OCS API, toasts, clipboard); `components/ShareForm.vue` and
  `components/SharesList.vue` are the create form and active-shares list;
  `permissions.ts` holds the mode constants/labels (folder-mode *enforcement*
  lives elsewhere; here they only drive the picker).

## Admin settings & share policy

The admin configures trust, portal, the email + share policy and the brand
name from **Settings → Administration → Atrium Secureshare**. All configuration
lives in app config; there are no core changes.

- **`Service\AdminConfigService`**: the single source of truth for every admin
  value: typed getters/setters, defaults and validation. It owns the config-key
  constants; `core_public_key` and `portal_url` are the same keys `JWTValidator`
  and `PortalConfig` already read (this service just adds an admin-facing,
  validating front door). `setPublicKey` accepts only an ES256 (P-256) PEM (RSA
  and other curves are rejected) or empty (clears trust); `computeFingerprint`
  returns the `SHA256:base64` shape the core logs so an admin can compare by eye.
  `getAllowedModes` sanitises to the known 0..3 modes and never returns empty.
  `getAll()` (admin-only, includes the key) and `getPolicy()` (public subset, no
  key) are the two serialisers every consumer reads through.
- **`Settings\AdminSection` / `Settings\AdminSettings`**: the `IIconSection` +
  `IDelegatedSettings` pair registered in `info.xml` `<settings>`. `getForm()`
  seeds the current config into the initial page state and returns the `admin`
  template; `getAuthorizedAppConfig()` lists the policy keys a delegated admin may
  change (the trust key stays full-admin only).
- **`Controller\AdminSettingsController`**: the save API, a plain AppFramework
  controller (NOT OCS) at `/apps/atrium_secureshare/admin/*`, every method gated
  by `#[AuthorizedAdminSetting]`. `update` applies only the fields sent (a
  rejected value → 400 with the reason) and returns the refreshed config; the
  trust key is only re-written when it actually changes. There is deliberately no
  core-reachability probe: the plugin never calls the core (recipients reach the
  core directly in their browser), so it needs no network route to it. The trust
  status shown from the installed key's fingerprint is the only signal needed.
- **Server-side policy enforcement.** `SidebarShareController::create` calls
  `enforceSharePolicy` after the model validation: the chosen mode must be in
  `getAllowedModes()`, and when `getMaxShareDurationDays()` is set the share must
  carry an expiry within that many days (a configured maximum makes expiry
  mandatory). This is the only creation path, so the server is the authority; the
  sidebar UI only mirrors the policy. `SidebarShareController::policy`
  (`GET .../api/v1/policy`, `#[NoAdminRequired]`) exposes `getPolicy()` so the
  sidebar can shape the form. The trust key never leaves the admin surface.
- **Email policy.** `MailService::sendInvitation($share, $fileName,
  $userRequestedEmail)` consults `AdminConfigService`: nothing is sent when
  invitations are globally disabled, nor when the owner opted out and opting out
  is allowed; when opt-out is *not* allowed the recipient is notified regardless
  of the owner's choice. `ShareService` therefore always delegates, and the
  mailer owns the decision.
- **Frontend.** A second webpack entry `atrium-admin` (`src/admin/main.ts` →
  `AdminSettings.vue`, data layer `useAdminSettings.ts`) mounts into the
  template's `#atrium-admin-settings` and reads the seeded `adminConfig` initial
  state. The sidebar reads the policy once via `composables/usePolicy.ts` and
  passes it to `ShareForm.vue`, which offers only allowed modes, makes expiry
  mandatory/bounded when capped, and hides the notify toggle unless opting out is
  permitted.

## Retention policy (expired/exhausted shares)

Revoke deletes immediately; expired/exhausted shares survive a grace window for
the owner's benefit, then are purged. This keeps recipient PII from lingering
indefinitely while still letting the owner see why a share stopped.

- **revoke = hard delete.** `ShareService::revokeShare` removes the row
  (`AtriumShareMapper::delete`), so no `recipient_email` outlives the share. The
  schema carries no `revoked_at` column and there is no `isRevoked()`; a
  revoked share is simply "not found" everywhere, including the trust boundary
  (`ShareInfo` does not model a revoked state).
- **Two residual states, derived from existing fields, not schema flags.**
  *expired* = `expires_at < now`; *exhausted* = `download_count >= max_downloads`.
  The exhaustion instant is `last_download_at`, stamped inside the guarded counter
  UPDATE. Since counting stops at the cap, the last counted download IS the
  exhaustion moment (no second query, no TOCTOU). `AtriumShare::getStatus()`/
  `statusReferenceTime()` expose the label and that instant.
- **Grace window = `retention_days`** (global app-config, `AdminConfigService`,
  default 7, `>= 0`). Deadline = terminal instant + `retention_days`. The file
  sidebar (`findByFileForOwner`) lists a non-active share only while
  `now <= deadline`, tagged with its `status`; `retention_days = 0` means no grace
  (dropped immediately, purged next cron run). It is NOT in `getPolicy()`, a
  server/owner concern the recipient never sees.
- **`BackgroundJob\RetentionCleanupJob`** (`TimedJob`, daily, `TIME_INSENSITIVE`,
  registered idempotently via `IJobList` in `Application::boot`) hard-deletes rows
  past their deadline through `AtriumShareMapper::deleteRetiredBefore(cutoff)`
  where `cutoff = now - retention_days`. There is deliberately no lazy-delete on
  access: accessibility ends the instant a share expires/hits its cap (the trust
  boundary checks that live), so the physical purge can wait for the next run.
- **Only the file sidebar shows grace.** The overview (`findActiveByOwner`), the
  native "Shared" indicator (`AtriumShareProvider::getSharesInFolder`, hot path)
  and the recipient-facing `findByRecipientEmail` stay strictly active-only.
- **Edit = reactivation.** Extending `expires_at` or raising `max_downloads` on a
  graced share makes it active again via the ordinary update path (same token,
  same recipient: no delete+recreate; `updateShare` loads by id, no `isActive`
  filter). The grace window is therefore also the reactivation window: once the
  cron purges the row, only a fresh share is possible.

## Files-list "Shared" indicator (native)

An Atrium-shared file shows Nextcloud's OWN native "Shared" indicator in the
Files list: no custom UI, no custom DAV property, no frontend at all. Nextcloud
detects the share itself; we only feed it through the documented share subsystem.

- **`Share\AtriumShareProvider`**: a native `OCP\Share\IShareProvider`, registered
  in `Application::boot()` via the public `IManager::registerShareProvider()`
  (`@since 21`). The core reads it through `Manager::getSharesInFolder()`, which
  iterates ALL providers when a directory listing requests `oc:share-types`; that
  populates the property and the core `sharingStatusAction` renders the indicator.
- **Read-only / indicator-only.** Only `getSharesInFolder()` is on the hot path:
  ONE batched, indexed query per directory (owner-scoped, joined to `filecache` on
  the parent via `AtriumShareMapper::findByParentFolder`), the same
  O(1)-queries-per-listing profile as the core `SharesPlugin`/`sharebymail`; no
  per-file query. Shares report `IShare::TYPE_EMAIL` (an Atrium share IS an
  external email-addressed share): honest, and it keeps us OFF the native sharing
  sidebar, because `Manager::getSharesBy()` routes `TYPE_EMAIL` to the sharebymail
  provider via `getProviderForType()`, never here. So the Atrium sidebar section
  stays the single place to create/manage shares; no duplication.
- **Access stays with the Atrium core**, never Nextcloud: recipients are external
  (never NC users), so the recipient-side lookups return empty / throw, and the
  mutation methods are inert (Nextcloud must never create, alter or expire Atrium
  shares).
- **`<types><filesystem/></types>` in `info.xml` is REQUIRED.** The Files list's
  directory PROPFIND runs over WebDAV (`remote.php`), which only loads apps of type
  `filesystem`/`logging` (`remote.php`: `loadApps(['filesystem','logging'])`).
  Without the type the app never boots on that request, so the provider is never
  registered and the indicator stays empty, even though everything works on normal
  web pages. This was the subtle failure mode; keep the type.

## Shares overview navigation view

A "Shared via {brand}" entry appears under the native **Shares** section in the
Files navigation (left menu), listing every file the current user actively
shares externally via Atrium, one row per file, the sibling of "Shared with
others"/"Shared by link" (which behave the same way: several recipients of one
file render as a single row there too). It uses ONLY the exported
`@nextcloud/files` Navigation API (the same one the native share views register
through) plus a dedicated OCS endpoint; the native "Shared with others" list is
never touched and `oc_share` is never written. Direct entry into the native list
is impossible without an `oc_share` rebuild: `ShareAPIController::getSharesFromNode()`
queries a hardcoded type list, and `ProviderFactory::getProviderForType()` maps
`TYPE_EMAIL` strictly to `sharebymail`. A foreign provider is never consulted
there. A separate view is the sanctioned, non-invasive path.

- **Backend.** `AtriumShareMapper::findByOwner($uid)` (owner-wide, newest first,
  the counterpart to the file-scoped `findByFileId`) →
  `ShareService::findActiveByOwner($uid)` (active-only, the counterpart to
  `findByFileForOwner`) → `SidebarShareController::overview()` (`#[NoAdminRequired]`,
  `GET .../api/v1/overview`). It resolves each share's node via `FileResolver`,
  skips any that no longer resolve (deleted/moved: no oracle, a stale share never
  breaks the listing) and returns one camelCase entry PER share. The OCS layer
  itself never merges; grouping by file happens in the frontend (below). Each
  entry carries the share fields (`permissions` is the sharing **mode** 0..3, not
  an NC bitmask) plus the node fields the frontend needs: `fileId`, `path`
  (relative to the owner's files root), `name`, `isFolder`, `mtime` (unix
  seconds), `size`, `mimetype`.
- **Frontend (`src/shares-view.ts`).** `registerAtriumSharesView()` fetches the
  brand name from the public policy route (default `Atrium`), then registers a
  `View` with `parent: 'shareoverview'`, `order: 7` (last in the section,
  non-invasive), `columns: []` (the native FileList renders it, no rebuilt UI)
  and an inlined SVG icon (string literal, no svg-loader). It is called from
  `atrium-sharing.ts` (the bundle the Files app already loads via
  `LoadSidebarScriptListener`); a rejected registration is caught so the sidebar
  section keeps working. `owner` comes from `@nextcloud/auth` `getCurrentUser()`.
- **Row-actions column ("Shared" icon/label).** Nextcloud's own
  `sharingStatusAction` is a GLOBAL `FileAction` applied to every file list via
  its own per-node `enabled()` check. It renders inline and its `exec()` opens the
  native file-details sidebar's Sharing tab. Its `enabled()` requires
  `node.attributes['share-types']` to be a non-empty array; `entryToNode` sets one
  `ShareType.Email` per share in the file's group. A file with more than one
  active share therefore correctly shows the native "Shared multiple times with
  different people" tooltip. No custom column or component needed.
- **Node identity constraint: why several shares of one file merge into one
  row.** `source` must be byte-identical to the real DAV URL: `Node.basename()`/
  `dirname()` (from `@nextcloud/paths`) are naive string slices on the raw
  `source`, NOT URL-aware, so they do not strip a query string or fragment. The
  legacy files_sharing bridge feeds `node.basename`/`dirname` straight into the
  native sidebar's "people with access" list. A first attempt appended
  `?atriumShareId=<id>` to `source` to keep multiple shares of the same file as
  distinct Files-store rows (the store keys solely by `source`). This corrupted
  the reported name into `report.pdf?atriumShareId=41`, breaking that native
  lookup into a literal nonexistent path for EVERY row, and there is no safe place
  to hide a disambiguator (a suffix breaks `basename`, a prefix breaks `dirname`).
  This is also why native `SharingService.ts` itself `groupBy('source')`-merges
  multiple shares of one file into a single row. The Files store is fundamentally
  per-file, not per-share. `groupByFileId` in `shares-view.ts` does the same
  merge: one row per fileId, the group's first (newest) entry is the row's
  representative for every field except `share-types` (aggregated). The node `id`
  is the real file id, so native file actions apply and clicking a row opens the
  existing Atrium sidebar section, which lists every recipient for the file. Node
  permission is `Permission.READ` on purpose: view/download/details only, no
  rename/move/delete of the underlying file from this overview.
- **Clicking a shared folder row.** Files open their preview via a generic
  default action; folders need their own. Native Nextcloud's
  `files_sharing:open-in-files` does exactly this
  (`OCP.Files.Router.goToRoute` to the main `files` view with `dir` set to the
  folder's real path), but its `enabled()` checks a hardcoded allowlist of native
  share view ids that does not include ours, so it never fired for our rows.
  Fixed by registering an equivalent `atrium_secureshare:open-in-files`
  FileAction scoped to our view id and to folders only; `default:
  DefaultType.HIDDEN` makes it the row's click handler without a redundant menu
  entry. `window.OCP.Files.Router` is an untyped runtime global; the minimal
  ambient signature lives in `src/global.d.ts`.
