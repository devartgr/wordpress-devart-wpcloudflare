# Local WordPress smoke checks

Use any local WordPress 7+ site (PHP 8.3+) as a smoke target. Paths and URLs
below are placeholders — replace them with your own environment.

## Target (example)

| Item | Example |
|------|---------|
| Path | `/path/to/wordpress` |
| URL | `https://your-local-site.test` |
| Admin | `https://your-local-site.test/wp-admin/` |

## Credentials

- Never put WordPress admin passwords in chat, git, or repository docs.
- Prefer WP-CLI for install/activate when available.

## Install the plugin

1. Download the latest outer package from
   [GitHub Releases](https://github.com/devartgr/wordpress-devart-wpcloudflare/releases).
2. Extract it and install the inner `devart-wpcloudflare.zip`:

```shell
wp plugin install /absolute/path/to/devart-wpcloudflare.zip --activate --path=/path/to/wordpress
```

Or: WordPress admin → Plugins → Add New → Upload Plugin → Activate.

3. Confirm the installed plugin version matches the release under test.

## HTTP smoke

```shell
curl -k -L -sS -o /tmp/wp-fe.html -w '%{http_code}\n' https://your-local-site.test/
curl -k -L -sS -o /tmp/wp-admin.html -w '%{http_code}\n' https://your-local-site.test/wp-admin/
```

PASS only when:

- HTTP status is 2xx or 3xx
- Response body does not contain `Fatal error`
- Response body does not contain `There has been a critical error`

Notes:

- `/wp-admin/` may redirect to the login form when unauthenticated. That still
  counts as admin smoke PASS if there is no fatal/critical error.
- Authenticated admin checks require credentials kept outside the repository.
