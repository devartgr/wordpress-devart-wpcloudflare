# Changelog

## Template 2.0.0 — 2026-10-19

- Converted the repository template from Joomla packages to WordPress plugin packages.
- Replaced Joomla manifests with `source/package/package.json` + `source/plugins/`.
- Rewrote `scripts/validate.php` and `scripts/build.php` for WordPress plugin headers and ZIP packaging.
- Updated docs and QA checklist for WordPress 7+.
- Documented local smoke steps without storing admin credentials.

## Template 1.1.0 — 2026-08-04

- Restored generic documentation placeholders for new product bootstrap.
- Added Joomla production-series and workspace awareness to `scripts/validate.php`.
- Added PHP minimum, compatibility targets, and extension checks to validation.
- Added stronger package and extension naming validation.
- Made `scripts/build.php` validation-first with a source integrity guard.
- Preserved the original simple template architecture.

Product change history for bootstrapped repositories begins below this template
section.

## 1.0.0 — 2026-10-23

First public release (Kostas Stathopoulos - DevArt).

- Cloudflare cache purge for WordPress 7+ / PHP 8.3+: Settings → DevArt Cloudflare.
- API token encrypted at rest (sodium `enc:v2:`) or `DEVART_CF_API_TOKEN` in wp-config;
  Zone ID validation; optional remove-token; Test connection (homepage purge).
- Manual purge: everything (confirmed) and single URL; same-site host filtering
  (`devart_wpcloudflare_allowed_hosts`).
- Auto-purge on content change: permalink, home, archives, terms, author, feed,
  extra URLs; comments purge the article URL only; batched on shutdown.
- Durable MySQL purge queue (`devart_cf_queue`): atomic claim, retry with
  Retry-After / exponential backoff, dirty re-enqueue, dropped URLs with retry,
  conservative schedule when queue status cannot be read.
- Scheduled WP-Cron purge: URL list or everything (15 min / 30 min / hourly /
  2 hours / twice daily / daily); system-cron guidance behind Cloudflare.
- Site Health test + Info (no secrets); WP-CLI `wp devart-cf status|purge|retry-dropped`;
  UTC-day counters on Status.
- Author: Kostas Stathopoulos - DevArt —
  https://github.com/devartgr?tab=repositories

Public releases are published on GitHub Releases with package ZIP and SHA-256.
