# Nextcloud plugin for Atrium Secureshare

Share files with people **outside** your organisation directly from Nextcloud,
without giving them Nextcloud accounts and without needing to expose Nextcloud to
the public internet.

This app integrates **Atrium Secureshare** into the native Nextcloud Files
sharing sidebar. An internal user creates an external, identity-bound share
addressed to a recipient's email. The external recipient then authenticates
against your identity provider and reaches the file through the Atrium gateway,
never through Nextcloud directly.

> ## ⚠️ Requires Atrium Core
>
> This plugin is only the **Nextcloud side** of Atrium. On its own it does
> nothing useful. It needs the **Atrium Core** gateway running as a **separate
> deployment**. The core is the internet-facing component that authenticates
> recipients (OIDC and MFA), enforces the Terms of Service, and streams the
> files. This plugin holds the shares and serves file contents to the core over a
> signed, internal trust channel.
>
> **Atrium Core is at https://github.com/atrium-secureshare/atrium-core**
>
> Install and configure the core first, then exchange the trust key (see below).

## Why Atrium instead of guest accounts

- **No accounts for external people.** Recipients are never provisioned as
  Nextcloud users or guests. There is nothing to create, license, or
  deprovision, and no stale objects cluttering the user directory.
- **Nextcloud need not face the internet.** Only Atrium Core sits in the DMZ.
  Nextcloud can stay in the trusted internal network behind the firewall, so its
  attack surface need not be exposed to the public.
- **No impersonation.** Because guests are never entries in the Nextcloud user
  directory, they cannot spoof internal employees in a global address book.
- **No per-guest licensing impact.** External recipients never count toward
  Nextcloud user limits.
- **Identity-bound, not anonymous links.** Every share is addressed to a specific
  person, who must authenticate with MFA and accept your Terms of Service before
  any file is served.

Compared to Nextcloud's own *Guests* app:

| Aspect | Nextcloud Guests app | Atrium |
| :--- | :--- | :--- |
| Nextcloud internet exposure | Required. Nextcloud must face the internet. | Not required. Nextcloud stays in the internal LAN. |
| Licensing | Guests count toward user limits. | No licensing impact on Nextcloud. |
| Impersonation risk | High. Guests can spoof internal names. | None. Recipients are fully sandboxed. |
| Directory pollution | The user directory fills with stale guest objects. | Clean. Nextcloud is unaware of recipients. |
| Backends | Nextcloud only. | Storage-agnostic via modular plugins. |

## White-label

Atrium ships neutral and is rebranded entirely **at runtime**, with no fork, no
rebuild and no code changes:

- The **portal your recipients see** (served by Atrium Core) carries your
  organisation's **name, sub-label, accent colour, light or dark theme and
  logos**. Logos are mounted files, swapped without a restart.
- Inside **Nextcloud**, the Atrium sharing section and the "Shared via …"
  navigation entry show **your configured brand name**.

So external recipients and your internal users see your institution's identity,
never the word "Atrium". The shipped build carries only neutral Atrium defaults,
and the operator applies the brand (see the core's configuration).

## What this app does

- Adds an **Atrium section to the native Files sharing sidebar** where an owner
  creates and manages external shares. A share can be a file or a folder, with an
  expiry and, for files, a maximum-download cap.
- **Folder shares support four modes:** `Read-Only`, `Write / Read-Own` (upload
  and see only your own uploads), `Write / Read-All`, and `Write-Only`
  (dropzone).
- Shows Nextcloud's **own native "Shared" indicator** on Atrium-shared files, and
  adds a **"Shared via Atrium"** entry under the Files *Shares* navigation. There
  is no parallel UI, and it uses only documented Nextcloud extension points.
- Sends **email invitations** that link to the Atrium portal, never a direct file
  link. The recipient authenticates at the portal.
- Provides an **admin settings page** (trust key, portal URL, share and email
  policy, interim brand name) and a **retention policy** for expired or revoked
  shares.

Access control, authentication and streaming are **not** done here. They are the
core's job. This app persists shares, enforces the core's signed-token trust
boundary, and serves file contents to the core.

## Requirements

- **Nextcloud 34** (the tested target).
- A running **[Atrium Core](https://github.com/atrium-secureshare/atrium-core)**
  reachable by your external recipients.

## Installation

Install **Atrium Secureshare** from the **Nextcloud App Store**: in Nextcloud go
to *Apps*, search for "Atrium Secureshare" and enable it. This is the supported
path and keeps the app up to date.

Release archives are also published on
[GitHub Releases](https://github.com/atrium-secureshare/atrium-plugin-nextcloud/releases)
for manual installation: unpack the archive into your Nextcloud apps path and
enable the app.

```bash
tar -xzf atrium_secureshare-<version>.tar.gz -C /path/to/nextcloud/custom_apps
occ app:enable atrium_secureshare
```

Either way, one piece of post-install configuration is required: the trust key
from your Atrium Core.

### Trust key exchange (one-time)

The core signs every request to this app with a private key, and the app verifies
it with the matching **public** key. After installing, add the core's public key
in the admin settings page, under **Settings → Administration → Atrium
Secureshare**. The core logs its public key at startup, ready to paste.

If you prefer the command line, the same key can be set with `occ`:

```bash
occ config:app:set atrium_secureshare core_public_key --value "$(cat provider-signing.pub)"
```

Until the key is set, `GET /api/v1/health` answers
`403 {"error":"trust_not_configured"}` and the core reports itself degraded.

## Documentation

- [docs/internals.md](docs/internals.md) covers the plugin internals, from the
  trust boundary and share persistence to folder modes, the native "Shared"
  indicator, the shares overview, admin policy and the retention policy.
- [AGENTS.md](AGENTS.md) covers the layout and the build, test and release
  workflow for contributors and coding agents.

## License

Copyright (C) 2026 Kanton Bern, licensed under AGPL-3.0-or-later.
See [LICENSE](LICENSE) for the full text.
