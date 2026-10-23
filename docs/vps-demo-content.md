# VPS demo content (before a large news site)

Use a small staging WordPress behind the same Cloudflare stack you will use in
production (or a dedicated staging subdomain on the same zone). Goal: exercise
URL changes, queue/retry, and cron — not volume.

Never put API tokens or admin passwords in this file, chat, or git.

You can create demo posts on an empty WordPress **before or after** installing
the plugin. Use the browser steps in **section A** if you are not used to WP-CLI.

---

## A. Create demo content in wp-admin (empty site, no WP-CLI)

Log in: `https://YOUR-DOMAIN/wp-admin/`

Greek admin labels (if the dashboard is in Greek): Άρθρα, Κατηγορίες, Σελίδες,
Ρυθμίσεις → Μόνιμοι σύνδεσμοι / Ανάγνωση.

### A1. Permalinks (so URLs look like a news site)

1. Left menu → **Settings → Permalinks** (Ρυθμίσεις → Μόνιμοι σύνδεσμοι)
2. Select **Custom Structure** and type exactly:

   `/%category%/%postname%/`

3. Click **Save Changes**

### A2. Categories

1. **Posts → Categories** (Άρθρα → Κατηγορίες)
2. Create:
   - Name: `Demo News` → Add Category
   - Name: `Demo Politics` → under **Parent** choose `Demo News` → Add
   - Name: `Demo Sports` → Add (no parent)

### A3. Posts (articles)

1. **Posts → Add New** (Άρθρα → Προσθήκη)
2. Title: `Demo article 1`
3. Type one short paragraph in the editor
4. Right sidebar → **Categories** → tick **Demo Politics**
5. Click **Publish**
6. Repeat for more posts, e.g. `Demo article 2` … `8`  
   (some in Politics, some in Sports)

To find them later: **Posts → All Posts**.

### A4. One page (optional)

1. **Pages → Add New** (Σελίδες → Προσθήκη)
2. Title: `Demo About` → short text → **Publish**

### A5. One comment (optional but useful later)

1. Open `Demo article 1` on the public site
2. Leave a comment
3. In admin → **Comments** → **Approve** if it is held for moderation

### A6. Homepage shows the post list

1. **Settings → Reading** (Ρυθμίσεις → Ανάγνωση)
2. Homepage displays → **Your latest posts**
3. Save

### A7. Check the public site

Open in the browser:

- Homepage: `https://YOUR-DOMAIN/`
- A category: `https://YOUR-DOMAIN/category/demo-politics/`
- A post: usually  
  `https://YOUR-DOMAIN/category/demo-news/demo-article-1/`  
  (depends on category + post slug — copy the link from All Posts → View)

If you get 404: open **Settings → Permalinks** and click **Save** again.

When this content exists, install/configure the Cloudflare plugin and run the
scenario table in section 4 (edit slug, change category, trash, etc.).

---

## 0. Preconditions (plugin + Cloudflare, for purge tests)

- [ ] Plugin `1.0.0` installed and active (`wp plugin get devart-wpcloudflare --field=version`)
- [ ] Zone ID + token configured (or `DEVART_CF_API_TOKEN` in wp-config)
- [ ] **Test connection** succeeds once
- [ ] Auto-purge on save enabled
- [ ] Scheduled purge: URL list with home + one category archive (not “everything”)
- [ ] Cloudflare Cache Bypass for `wp-admin` / `wp-login.php`
- [ ] System cron + `DISABLE_WP_CRON` (recommended behind Cloudflare)

Replace `--path=/var/www/html` with your VPS WordPress path.

## 1. Permalinks (news-like)

```shell
wp rewrite structure '/%category%/%postname%/' --path=/var/www/html
wp rewrite flush --path=/var/www/html
```

## 2. Seed taxonomy + posts (WP-CLI)

```shell
PATH_WP=/var/www/html

# Categories (hierarchical)
PARENT=$(wp term create category "Demo News" --porcelain --path="$PATH_WP")
CHILD=$(wp term create category "Demo Politics" --parent="$PARENT" --porcelain --path="$PATH_WP")
OTHER=$(wp term create category "Demo Sports" --porcelain --path="$PATH_WP")

# Homepage / blog (if needed)
wp option update show_on_front posts --path="$PATH_WP"

# Posts
for i in 1 2 3 4 5 6 7 8; do
  wp post create \
    --post_type=post \
    --post_status=publish \
    --post_title="Demo article $i" \
    --post_name="demo-article-$i" \
    --post_content="Demo body for purge tests. Article $i." \
    --porcelain --path="$PATH_WP"
done

# Assign categories (first posts → politics, last → sports)
# Adjust IDs from: wp post list --post_type=post --format=ids --path=$PATH_WP
IDS=$(wp post list --post_type=post --format=ids --path="$PATH_WP")
set -- $IDS
wp post term set "$1" category "$CHILD" --path="$PATH_WP"
wp post term set "$2" category "$CHILD" --path="$PATH_WP"
wp post term set "$3" category "$CHILD" --path="$PATH_WP"
wp post term set "$4" category "$OTHER" --path="$PATH_WP"
wp post term set "$5" category "$OTHER" --path="$PATH_WP"

# One page
wp post create \
  --post_type=page \
  --post_status=publish \
  --post_title="Demo About" \
  --post_name="demo-about" \
  --post_content="Demo page." \
  --path="$PATH_WP"

# One approved comment (for comment-purge path)
POST_ID=$(wp post list --name=demo-article-1 --field=ID --path="$PATH_WP")
wp comment create \
  --comment_post_ID="$POST_ID" \
  --comment_content="Demo comment for purge." \
  --comment_author="Demo" \
  --comment_author_email="demo@example.com" \
  --comment_approved=1 \
  --path="$PATH_WP"
```

Optional: create a second author and reassign a post later to hit author-archive purge.

```shell
wp user create demoreporter demoreporter@example.com --role=author --user_pass='(set locally)' --path="$PATH_WP"
```

## 3. Scheduled URL list (settings UI or options)

In **Settings → DevArt Cloudflare**, schedule mode = URL list, e.g.:

```text
https://YOUR-DOMAIN/
https://YOUR-DOMAIN/category/demo-news/
https://YOUR-DOMAIN/category/demo-politics/
```

Confirm with:

```shell
wp cron event list --path=/var/www/html | grep -E 'devart_wpcloudflare|devart-cf' || true
wp cron event list --path=/var/www/html
```

## 4. Scenario checklist (run in order)

Record Status / Cloudflare before and after each step when useful.

| # | Action | What to verify |
|---|--------|----------------|
| 1 | Open an article URL once (warm Cloudflare cache) | Cache hit headers as expected for your setup |
| 2 | Edit title/body of a published post, save | Status shows success or pending retry; article URL purged |
| 3 | Change slug of a published post | Old and new pretty URLs requested (not only `?p=`) |
| 4 | Move post from Demo Politics → Demo Sports | Old + new category archives + permalinks |
| 5 | Publish → Draft → Publish again | Unpublish + republish paths |
| 6 | Trash a published post | Old permalink + term archives |
| 7 | Approve / edit an approved comment | Only article permalink purged |
| 8 | Publish 3 posts quickly | Shutdown batching; Status counters increase |
| 9 | Temporary bad token → save a post → restore token | Pending retries; after give-up: Dropped URLs; Retry dropped works with fresh attempts |
| 10 | Wait for scheduled purge (or `wp cron event run --due-now`) | Scheduled URLs purged; next-run still sane |
| 11 | Site Health → DevArt Cloudflare | `good` when idle; `recommended`/`critical` only when expected |
| 12 | CLI | `wp devart-cf status` shows queue/credentials without secrets |

Useful commands:

```shell
wp devart-cf status --path=/var/www/html
wp cron event run --due-now --path=/var/www/html
```

## 5. Pass criteria for “ready for large site”

- [ ] No fatal / critical errors on frontend or admin during the scenarios
- [ ] Auto-purge does not block the editor on API failure (work goes to queue)
- [ ] Dropped + Retry dropped behaves correctly after a forced outage
- [ ] Scheduled list runs with system cron
- [ ] Site Health matches reality (no false “healthy empty queue”)
- [ ] Cloudflare origin/cache behaviour looks sane on purged URLs

When this staging pass is clean, move the **same** `1.0.0` ZIP to the large news site.
