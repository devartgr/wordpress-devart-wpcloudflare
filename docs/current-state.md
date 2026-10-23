# DevArt WordPress Cloudflare — Current State

## Development Source

Current package version:

`1.0.0`

Package:

`pkg_devart_wpcloudflare`

Plugins:

- `devart-wpcloudflare`

## Implemented Features

- Settings screen under Settings → DevArt Cloudflare
- Cloudflare API token: sodium-encrypted at rest, or `DEVART_CF_API_TOKEN` in wp-config
- Remove stored token; warning when salts make a stored token unreadable
- Zone ID (32 hex, invalid input keeps previous value) + Test connection (homepage purge)
- Manual purge everything (confirmed) and purge by single URL
- Host validation for purge URLs (`home_url` / `site_url`, filterable)
- Auto purge on content change for published public post types:
  permalink (pre-update public URL + author archive), home, posts page, feed,
  public taxonomies (with ancestors), author archive, post type archive,
  plus optional extra URLs; comments purge only the article permalink
- Durable purge queue in custom table `devart_cf_queue`: atomic claim, batch_id,
  per-URL attempts / next_attempt_at, dirty re-enqueue, Retry-After / exponential
  backoff (up to 8 attempts / 1h), dropped rows with stable drop time + fresh
  retry cycle on re-activate
- Term removals/additions capture real permalink once per post/request
- Purge on unpublish, trash, force-delete, slug/category/author changes, term changes
- Scheduled WP-Cron purge: URL list or everything
  (15 min / 30 min / hourly / 2 hours / twice daily / daily)
- Status panel (last result, active/historical failure, next run, pending retry,
  token source, queue-table availability)
- Informational conflict notice for other active Cloudflare/page-cache plugins
- Site Health test + Info debug section (no secrets)
- WP-CLI: `wp devart-cf status|purge|retry-dropped`
- UTC-day counters: purged URLs, failures, max pending depth
- Uninstall cleanup of options, queue table, stats, and cron events

## Known Limitations

- No Cloudflare analytics, rules, or firewall management
- No prefix/tag purge API modes (only URL list + everything)
- No multisite network UI
- Archive purge is page 1 only (`/page/2/` and later stay until TTL)
- Sidebars showing “latest posts” on other articles stay cached until TTL or
  manual purge / Cache-Control strategy at the origin
- Theme, menu, and widget changes need a manual or scheduled purge
- Upgrade from options-queue builds discards any URLs still pending at upgrade time
- Dropped list stores up to 2000 recoverable URLs; lifetime count may be higher
- Scheduled URL mode requires at least one valid absolute URL
- Purge success means Cloudflare accepted the request, not that edge eviction is complete
- Local / VPS verification of live Cloudflare credentials is operator-owned
- WordPress admin behind Cloudflare requires a Cache Bypass rule for `wp-admin`
- WP-Cron is traffic-driven; behind Cloudflare prefer system cron + `DISABLE_WP_CRON`

## Repository notes

- Package identity: `source/package/package.json`.
- Requires PHP `8.3.0` and WordPress 7+.
- Install from GitHub Releases; local smoke: `docs/local-smoke.md`.
