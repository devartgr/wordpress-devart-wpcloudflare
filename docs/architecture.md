# DevArt WordPress Cloudflare — Architecture

## Repository Layout

`source/` is the only development source:

- `source/package/` — outer package manifest (`package.json`) and optional package metadata.
- `source/plugins/` — WordPress plugin sources (one directory per plugin slug).

Installable packages are published on GitHub Releases.

## Package Composition

Package: `pkg_devart_wpcloudflare`

Declared plugins:

- `devart-wpcloudflare` (`devart-wpcloudflare.php` → `devart-wpcloudflare.zip`)

The package manifest is the source of truth for the package id, version,
creation date, platform minimums, and member inventory.

The build script derives the outer package filename from `package_id`:

`pkg_devart_wpcloudflare_v1.0.0.zip`

Each member is packaged as an inner WordPress plugin ZIP that can be installed
via Plugins → Add New → Upload Plugin or WP-CLI.

## Product Architecture

Single WordPress plugin that talks to the Cloudflare Cache Purge API
(`POST /zones/{zone_id}/purge_cache`) using an API token and Zone ID.

Runtime layers:

1. **Options** — settings in `devart_wpcloudflare_settings` (autoload off):
   zone, auto-purge, schedule, extra URLs. Request-scoped memoization of `get()`.
2. **Secret** — sodium secretbox (`enc:v2:`, key from `wp_salt('auth')`) for the
   API token at rest, or `DEVART_CF_API_TOKEN` (not stored). Legacy `enc:` blobs
   are unreadable by design.
3. **Results** — last result / last error / active failure options (no secrets).
4. **Url_Filter** — parse/normalize URL lists and same-site host filtering
   (`devart_wpcloudflare_allowed_hosts`).
5. **URL builder** — builds the related URL set for a post (permalink, home,
   posts page, feed, public taxonomies with ancestors, author, CPT archive,
   extra URLs). Page 1 only; no unbounded pagination crawl.
6. **Cloudflare API client** — `wp_remote_request` for purge-everything and
   purge-by-URL (chunked at 100 URLs). Success requires HTTP 2xx and JSON
   `success`. Failed URL purges include the unsent URL list and parse
   `Retry-After` when present.
7. **Purge service** — host validation via Url_Filter, orchestration, Results
   storage, and `devart_wpcloudflare_purge_result` action. Test connection =
   homepage purge.
8. **Purge queue** — custom table `{$wpdb->prefix}devart_cf_queue` behind
   `Queue_Store` / `Queue_Table_Store`. Atomic `UPDATE … LIMIT` claims (stale
   reclaim included), per-URL attempts/`next_attempt_at`, dirty flag for
   re-enqueue while claimed (Retry-After floor preserved), atomic `dropped`
   column (drop time in `claimed_at`; re-activate resets attempts), cron that
   never postpones earlier work (Retry-After up to 1h, up to 8 attempts).
   Schema ensure verifies required columns (`devart_wpcloudflare_db_version` = 2).
   `stats()` reports `ok: false` on read failure (not treated as empty); the worker
   is still scheduled conservatively. If the table cannot be created, auto-purge
   falls back to one-shot send.
9. **Admin UI** — Settings → DevArt Cloudflare (`manage_options`), status /
   settings / manual purge panels, conflict notice, `admin-post` handlers.
   Scheduler resync runs explicitly after `Options::save()`.
10. **Auto purge** — content hooks only record post IDs / old URLs; one
   `shutdown` flush resolves URLs, applies `devart_wpcloudflare_urls_to_purge`,
   persists to the queue, then claims a send batch. Covers publish, unpublish,
   trash, force-delete, slug/category/author changes, terms, and comments
   (permalink only).
11. **Scheduler** — WP-Cron hook `devart_wpcloudflare_scheduled_purge` for URL
   list or everything. Custom recurrences 15 min / 30 min / 2 hours registered
   always via `cron_schedules`. Resync preserves next-run when recurrence is
   unchanged.
12. **Site Health / CLI / Stats** — Tools → Site Health test + Info fields (no
   secrets); flags overdue workers; `wp devart-cf status|purge|retry-dropped`;
   UTC-day purge counters.

Out of scope: analytics, firewall/rules management, prefix/tag purge API modes,
and multisite network UI.

## Source of Truth

- Develop only in `source/`.
- Treat generated archives as disposable output.
- Keep every plugin header `Version` identical to the package version.
- Keep `creationDate` in `YYYY-MM-DD` format.
- Keep `php_minimum` identical to the DevArt PHP minimum (`8.3.0`).
- Keep `wordpress_minimum` at WordPress 7.0.0 or newer as declared for the product.

## Releases

- Public installables are the outer package ZIP on GitHub Releases (SHA-256 in
  the release notes).
- Develop only in `source/`; do not treat installed plugin copies as source.
