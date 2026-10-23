# VPS getting started (WordPress + DevArt Cloudflare)

For operators who have not used WordPress day-to-day recently. Follow in order.
Do not put API tokens or passwords in git or chat.

Related: `docs/vps-demo-content.md` (demo posts after the plugin works).

---

## What you need before anything else

1. **A working WordPress site on the VPS**  
   Browser opens the homepage and `/wp-admin/` with an administrator account.
2. **A Cloudflare zone** for that domain (orange-cloud DNS is fine).
3. **A Cloudflare API token** with **Zone → Cache Purge** for that zone only  
   (and the **Zone ID** from the Cloudflare dashboard Overview).
4. The public package:  
   https://github.com/devartgr/wordpress-devart-wpcloudflare/releases/tag/v1.0.0  
   File: `pkg_devart_wpcloudflare_v1.0.0.zip`

If WordPress is not installed yet, install WordPress first (panel installer,
or download wordpress.org and point the vhost document root at it). This guide
starts **after** WordPress already loads.

---

## Important: what file you upload

The GitHub asset is an **outer package**:

```text
pkg_devart_wpcloudflare_v1.0.0.zip
├── package.json
└── devart-wpcloudflare.zip    ← THIS is what WordPress installs
```

**WordPress Plugins → Upload expects the inner plugin ZIP**, not always the outer
one. Safest path:

1. Download `pkg_devart_wpcloudflare_v1.0.0.zip` to your laptop.
2. Unzip it once on the laptop.
3. Upload **`devart-wpcloudflare.zip`** into WordPress.

(Do not unzip the inner ZIP into `wp-content/plugins` by hand unless you know
the folder must be named `devart-wpcloudflare` with `devart-wpcloudflare.php`
inside.)

---

## Step A — Install the plugin (browser, recommended)

1. Log in to WordPress admin: `https://YOUR-DOMAIN/wp-admin/`
2. Go to **Plugins → Add New Plugin → Upload Plugin**
3. Choose **`devart-wpcloudflare.zip`** (the inner file)
4. Click **Install Now**, then **Activate**
5. Confirm under **Plugins** that **DevArt WordPress Cloudflare** is Active
   and version **1.0.0**

### Alternative: WP-CLI on the server

```shell
# After you scp the inner ZIP to the server:
wp plugin install /path/to/devart-wpcloudflare.zip --activate --path=/path/to/wordpress
wp plugin get devart-wpcloudflare --field=version --path=/path/to/wordpress
```

---

## Step B — Cloudflare token and Zone ID

### Zone ID

Cloudflare dashboard → your domain → **Overview** (right column) → **Zone ID**.
Copy the 32-character hex string.

### API token

Cloudflare → **My Profile → API Tokens → Create Token**

- Use a custom token
- Permission: **Zone → Cache Purge → Purge**
- Zone Resources: **Include → Specific zone → your domain**
- Create and copy the token once (Cloudflare will not show it again)

Optional (more secure for production): put the token in `wp-config.php` instead
of the database:

```php
define('DEVART_CF_API_TOKEN', 'paste-token-here');
```

Place it **above** the line that says “That’s all, stop editing!”.  
If this constant is set, the plugin uses it and does not store the token in the DB.

---

## Step C — Plugin settings

1. Admin menu: **Settings → DevArt Cloudflare**
2. Paste **Zone ID**
3. Paste **API Token** (unless you used `DEVART_CF_API_TOKEN`)
4. Enable **Auto purge on save** (for content tests)
5. Optionally add Extra auto-purge URLs (one absolute URL per line, same site host)
6. Click **Save settings**
7. Click **Test connection**  
   This sends a real purge of the homepage. Success means Cache Purge works.

If Test connection fails: wrong Zone ID, wrong token permissions, or the site
hostname is not on that Cloudflare zone.

---

## Step D — Cloudflare Cache Bypass for wp-admin

Without this, the admin UI can be cached or odd behind Cloudflare.

In Cloudflare → **Rules → Cache Rules** (or Page Rules on older setups):

- If URI Path starts with `/wp-admin` → **Bypass cache**
- Preferably also `/wp-login.php` → **Bypass cache**

Keep the public site cached as you like.

---

## Step E — Cron behind Cloudflare (important)

WordPress “WP-Cron” normally runs when someone visits the site. Behind
Cloudflare cache, visits may never hit PHP, so scheduled purge and queue
retries stall.

On the VPS, prefer:

1. In `wp-config.php` (above “stop editing”):

```php
define('DISABLE_WP_CRON', true);
```

2. System crontab (every minute), as the web user:

```cron
* * * * * wp cron event run --due-now --path=/path/to/wordpress >/dev/null 2>&1
```

Confirm:

```shell
wp cron event list --path=/path/to/wordpress
```

---

## Step F — Scheduled purge (optional but good for demos)

**Settings → DevArt Cloudflare**:

- Enable scheduled purge
- Mode: **URL list** (prefer this over “Everything” on news sites)
- Example list (use your real HTTPS URLs):

```text
https://YOUR-DOMAIN/
https://YOUR-DOMAIN/category/demo-news/
```

- Recurrence: e.g. every 30 minutes  
- Save settings  
- Confirm an event appears in `wp cron event list`

---

## Step G — Smoke checks

1. Open `https://YOUR-DOMAIN/` — page loads, no “critical error”
2. Open `https://YOUR-DOMAIN/wp-admin/` — admin loads
3. **Settings → DevArt Cloudflare** — Status panel visible
4. **Tools → Site Health → Status** — DevArt Cloudflare test present
5. Optional CLI:

```shell
wp devart-cf status --path=/path/to/wordpress
```

(No tokens should appear in the output.)

---

## Step H — Demo content and purge scenarios

Only after Test connection works, follow:

`docs/vps-demo-content.md`

That file creates categories/posts and lists edit/slug/category/trash/comment
tests so you can see purges and the queue without a large news site.

---

## Where files live on the server (orientation)

Typical layout:

```text
/path/to/wordpress/
├── wp-admin/
├── wp-includes/
├── wp-config.php          ← token constant / DISABLE_WP_CRON go here
└── wp-content/
    └── plugins/
        └── devart-wpcloudflare/   ← installed plugin (do not edit by hand)
```

Develop and rebuild packages only in the **Git repository** on your Mac. On the
VPS you only **install the ZIP**. Never treat the live plugin folder as the
source of truth.

---

## Update later

1. Download the new outer package from GitHub Releases  
2. Extract → upload new `devart-wpcloudflare.zip` (Plugins → Upload, or WP-CLI
   `plugin install … --force --activate`)  
3. Confirm version in Plugins and Settings  
4. Run Test connection again  

There is no Joomla-style `update.xml` for this product yet; updates are manual
ZIP (or WP-CLI) from GitHub Releases.

---

## Quick “what do I upload first?” answer

| Order | What | Where |
|------|------|--------|
| 1 | Inner `devart-wpcloudflare.zip` | WordPress **Plugins → Upload Plugin** |
| 2 | Zone ID + API token | **Settings → DevArt Cloudflare** (or token in `wp-config.php`) |
| 3 | Cache Bypass rules | Cloudflare dashboard |
| 4 | `DISABLE_WP_CRON` + system cron | `wp-config.php` + server crontab |
| 5 | Demo posts | WP-CLI / admin, see `docs/vps-demo-content.md` |

You do **not** upload the whole Git repository to the VPS for normal use.
