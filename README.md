# DevArt WordPress Cloudflare

Professional Cloudflare cache purge package for WordPress 7+, designed for news
sites, editorial publishers, and high-traffic WordPress installs behind
Cloudflare full-page cache.

![WordPress](https://img.shields.io/badge/WordPress-7%2B-blue)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-green)
![Release](https://img.shields.io/badge/Version-1.0.0-orange)
![License](https://img.shields.io/badge/License-GPLv3-red)

---

## Overview

DevArt WordPress Cloudflare is a modern WordPress-native plugin that purges
Cloudflare cache when content changes, on a schedule, or on demand from the
administrator.

It is built for environments where editors must not wait on Cloudflare API
latency, where retries must survive failed requests, and where WP-Cron behind
Cloudflare needs clear operational guidance.

DevArt WordPress Cloudflare supports:

- Encrypted API token storage (sodium) or `DEVART_CF_API_TOKEN` in `wp-config.php`
- Manual purge (everything or single URL) with capability and nonce checks
- Automatic purge on publish / unpublish / trash / slug / category / term /
  comment changes
- Durable MySQL purge queue with claim, retry, dirty re-enqueue, and dropped URLs
- Scheduled WP-Cron purge (URL list or everything)
- Site Health test + Info (no secrets)
- WP-CLI: `wp devart-cf status|purge|retry-dropped`
- UTC-day counters on the Status panel

The installable outer package is `pkg_devart_wpcloudflare`. The declared plugin
member is `devart-wpcloudflare`.

---

## Version 1.0.0

DevArt WordPress Cloudflare 1.0.0 is the first public stable release for
WordPress 7+ (PHP 8.3+).

### Version 1.0.0 Highlights

- Settings → DevArt Cloudflare: Zone ID, token (encrypted or wp-config), auto-purge,
  extra URLs, scheduled purge
- Durable queue table `{prefix}devart_cf_queue` with atomic claims and Retry-After
- Dropped URL recovery (“Retry dropped URLs”) with a fresh attempt cycle
- Site Health flags stalled / overdue workers and unreadable queue status
- Author: Kostas Stathopoulos - DevArt

---

## Package Contents

The installable package includes:

- `devart-wpcloudflare` — WordPress plugin (settings, purge, queue, Site Health, CLI)

Always install or update using the full outer package
`pkg_devart_wpcloudflare_vX.Y.Z.zip`, then install the inner plugin ZIP if your
workflow extracts members manually. Prefer uploading the inner
`devart-wpcloudflare.zip` via Plugins → Add New → Upload Plugin, or WP-CLI.

---

## Features

### Credentials and security

- API token encrypted at rest with sodium secretbox (`enc:v2:`)
- Optional `DEVART_CF_API_TOKEN` (token not stored in the database)
- Remove stored token; warning when a stored blob cannot be decrypted
- Zone ID: exactly 32 hexadecimal characters
- Privileged actions require `manage_options` and nonces
- Same-site host filtering for purge URLs (`devart_wpcloudflare_allowed_hosts`)

### Manual purge

- Test connection (real homepage purge)
- Purge everything (confirmation required)
- Purge a single absolute URL

### Auto-purge on content change

Related URL set for published public content:

- permalink (including pre-update public URL when slug/category/author change)
- home / posts page / feed
- public taxonomy archives (with hierarchical ancestors)
- author archive / post type archive
- configurable extra URLs

Comments: only the article permalink is purged.

Changes are recorded on content hooks and flushed once on `shutdown` so editors
are not blocked by Cloudflare latency.

### Durable purge queue

- Custom table with atomic `UPDATE … LIMIT` claims
- Per-URL attempts and `next_attempt_at` (Retry-After / exponential backoff, up to 8 attempts)
- Dirty flag when a URL is re-edited while a purge is in flight
- Dropped URLs stored in-table with stable drop time and admin/CLI retry
- If stats cannot be read, a retry worker is still scheduled conservatively
- Fallback one-shot purge if the queue table cannot be created

### Scheduled purge

- WP-Cron hook for URL list or everything
- Recurrences: 15 min / 30 min / hourly / 2 hours / twice daily / daily
- Guidance for system cron + `DISABLE_WP_CRON` behind Cloudflare

### Operations

- Status panel: last result, active/historical failure, pending retry, dropped URLs, counters
- Site Health direct test + debug Info fields (no secrets)
- WP-CLI: `wp devart-cf status`, `wp devart-cf purge`, `wp devart-cf retry-dropped`

---

## Requirements

- WordPress 7.0 or newer
- PHP 8.3 or newer
- MySQL or MariaDB supported by WordPress
- Cloudflare zone with Cache Purge permission for the API token
- PHP sodium extension (for encrypted token storage)

Not supported:

- WordPress 5 / 6 compatibility targets
- PHP 8.2 and earlier

---

## Installation

1. Download the latest package:

   `pkg_devart_wpcloudflare_v1.0.0.zip`

2. Extract the outer package and upload the inner plugin ZIP
   `devart-wpcloudflare.zip` via:

   `Plugins → Add New → Upload Plugin`

   Or with WP-CLI:

   ```shell
   wp plugin install /path/to/devart-wpcloudflare.zip --activate
   ```

3. Open:

   `Settings → DevArt Cloudflare`

4. Enter Zone ID and API token (or define `DEVART_CF_API_TOKEN` in `wp-config.php`).

5. Use **Test connection**, then enable auto-purge and/or scheduled purge as needed.

6. Behind Cloudflare, configure a Cache Bypass rule for `wp-admin` (and preferably
   `wp-login.php`), and prefer system cron with `DISABLE_WP_CRON`.

---

## Updating

WordPress does not use a Joomla-style `update.xml` for this package yet. Install
updates by uploading the new plugin ZIP over the existing plugin (or WP-CLI
`plugin install --force`).

Before updating a production website:

- Create a complete backup
- Test on staging when possible
- Confirm Settings → DevArt Cloudflare and Site Health after update
- Confirm queue table and encrypted token still work (no deactivate required for
  normal updates)

---

## Download

Latest release:

`pkg_devart_wpcloudflare_v1.0.0.zip`

GitHub releases:

https://github.com/devartgr/wordpress-devart-wpcloudflare/releases

Direct download:

https://github.com/devartgr/wordpress-devart-wpcloudflare/releases/download/v1.0.0/pkg_devart_wpcloudflare_v1.0.0.zip

SHA-256:

`e134ab2111016457e55e4ce29507ff5cede3387ce7bb50a30ebfbcbbf8fd85c6`

---

## Cloudflare and cron notes

- Purge success means Cloudflare accepted the request; edge eviction is eventually consistent.
- WP-Cron is traffic-driven. Behind Cloudflare full-page cache, use a system cron
  (for example `wp cron event run --due-now`) and set `DISABLE_WP_CRON` in
  `wp-config.php`.
- Prefer scheduled **URL list** (home and main sections) over **everything** on
  news sites to avoid origin stampedes.
- Archive pagination beyond page 1 stays until TTL unless purged manually.

---

## WP-CLI

```shell
wp devart-cf status
wp devart-cf purge --url=https://example.com/path/
wp devart-cf purge --everything
wp devart-cf retry-dropped
```

---

## Repository layout

- `source/package/package.json` — package identity and member inventory
- `source/plugins/devart-wpcloudflare/` — plugin source (single source of truth)
- `docs/` — architecture and user guides
- `CHANGELOG.md` — public product history

Installable packages are published under [GitHub Releases](https://github.com/devartgr/wordpress-devart-wpcloudflare/releases).

---

## Documentation

- `docs/architecture.md` — product architecture
- `docs/current-state.md` — implemented features and limitations
- `docs/vps-getting-started.md` — first install on a server
- `docs/local-smoke.md` — local install and HTTP smoke
- `docs/vps-demo-content.md` — demo content for staging tests
- `CHANGELOG.md` — public changelog

---

## Security

- Capability checks (`manage_options`) and nonces on privileged actions
- Input sanitization and escaped admin output
- Secrets never written to Site Health Info or CLI status beyond presence flags
- Do not commit API tokens, Zone secrets, or `wp-config` credentials

---

## Support

Project repository:

https://github.com/devartgr/wordpress-devart-wpcloudflare

Before reporting an issue, include:

- WordPress version
- PHP version
- Database type and version
- Plugin version
- Cache / CDN stack (Cloudflare plan, page rules, system cron or not)
- Relevant error message (no tokens)
- Steps to reproduce

Do not include passwords, API tokens, or other secrets.

---

## Author

**Kostas Stathopoulos — DevArt**

GitHub: https://github.com/devartgr?tab=repositories

Repository: https://github.com/devartgr/wordpress-devart-wpcloudflare

---

## Disclaimer / Limitation of Liability

See `DISCLAIMER.md`.

This software is provided "as is", without warranty of any kind. DevArt shall not
be held liable for any damages, data loss, downtime, security issues, or other
problems resulting from the use or misuse of this software. Always test on a
staging environment and maintain proper backups before production use.

---

## License

GNU General Public License version 3 or later.

See the included license information in the plugin header for details.
