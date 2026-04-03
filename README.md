# jetty-client

Composer package **`jetty/client`**: PHP build of the **`jetty`** CLI for the [Jetty](https://jetty.dev) tunnel API (`/api/tunnels`).

**Scope:** `list`, `delete`, `share` (alias `http`) — register tunnels, connect the **edge WebSocket agent** (same protocol as the historical Go binary), forward HTTP to your local port, and send heartbeats. Use **`--skip-edge`** for registration + heartbeats only (no forwarding).

**`jetty share` (no port):** walks up from the current directory and tries many local-dev signals in order: **`JETTY_SHARE_UPSTREAM`** (optional override; applied even if that port is not listening yet), Laravel **`.env` `APP_URL`**, Bedrock **`APP_URL`**, **`herd links`** / **`valet links`**, **DDEV** / **Lando**, **Symfony** `.symfony.local.yaml`, **Laravel Sail** / **Docker Compose** published ports, **wp-env** / **Craft Nitro**, **Vite** / **Nuxt** / **Astro** / **SvelteKit** configs, **Next.js** / **Remix** / **Gatsby** defaults, **devcontainer** `forwardPorts`, **Caddyfile**, generic **`.env` `PORT`**, **package.json** heuristics (Strapi, Directus, etc.), **MAMP** path under `htdocs`, **PhpStorm** `.idea/php.xml`. Otherwise scans common ports on `127.0.0.1`. Use **`--no-detect`** or **`JETTY_SHARE_NO_DETECT=1`** to skip detection. **`--serve`** / **`--serve=path`** starts **`php -S`** (default docroot `./public` or `.`) and tunnels to it for quick static sites.

## Run from source (local testing, no publish)

From this directory after dependencies are installed, the launcher resolves `vendor/autoload.php` in the package root—no Packagist release required.

```bash
cd jetty-client
composer install
php bin/jetty version
# or: ./vendor/bin/jetty version   (Composer symlink to bin/jetty)
# or: composer run jetty -- version
```

Pass CLI args after `--`:

```bash
composer run jetty -- share 8000 --verbose
php bin/jetty share 8000 --no-js-rewrite
```

**Use this checkout inside another app** (path repository) so `jetty` runs your working tree:

```json
"repositories": [
    { "type": "path", "url": "../jetty-client", "options": { "symlink": true } }
],
"require": {
    "jetty/client": "@dev"
}
```

Then `composer update jetty/client` and use `vendor/bin/jetty` from that app.

## Install

```bash
composer require jetty/client
```

The binary is **`vendor/bin/jetty`** (add `vendor/bin` to your PATH, or call it by full path).

```bash
vendor/bin/jetty version
```

### Global install

```bash
composer global require jetty/client
# Put Composer’s global vendor/bin on your PATH (e.g. ~/.composer/vendor/bin or ~/.config/composer/vendor/bin)
jetty version
```

The `bin/jetty` launcher resolves **`vendor/autoload.php`** from the Composer project root, so the same package works as a **project dependency** or a **global** install.

### Add the client to a project (from a global `jetty`)

If you already have `jetty` on your PATH from `composer global require`, `cd` to an app that has a `composer.json` and run:

```bash
jetty install-client
```

That runs `composer require jetty/client` in the current directory so you get **`vendor/bin/jetty`** there too (optional; you can keep using the global binary).

### Updating: PHAR, Packagist, and “three installs”

You do **not** need to track three separate release processes:

- **Maintainers:** In this monorepo, **Actions → Release CLI** builds **`jetty-php.phar`**, attaches it to a **`cli-v*`** GitHub Release, and (when configured) **git subtree push** + tag **`vX.Y.Z`** to the **`jetty-client`** mirror so **Packagist** gets the same **`jetty/client`** version. Bump **`ApiClient::VERSION`** and **`composer.json`** `version` in `jetty-client/` together when you cut a release.
- **Users:** Use **one** primary binary — PHAR in `~/.local/bin`, **or** Composer global, **or** `vendor/bin/jetty` in a project. Your config file is shared (`~/.config/jetty/config.json`). **`jetty update`** only updates **whichever install is running** (PHAR → GitHub release asset; Composer → `composer update jetty/client` in that project root).
- **Inspect:** `jetty version --install` shows how this binary was installed and what `jetty update` will do. `jetty version --check-update` checks GitHub (PHAR) or Packagist via Composer (project/global).

## Configuration

Prefer a **JSON config file** so you do not need shell exports. See `composer show jetty/client` or run `vendor/bin/jetty help` for paths (`~/.config/jetty/config.json`, `./jetty.config.json`, etc.).

```json
{
  "api_url": "https://your-jetty.example",
  "token": "your-personal-access-token"
}
```

Optional fallbacks: `JETTY_API_URL`, `JETTY_SERVER`, `JETTY_TOKEN`, or flags `--api-url` / `--token`.

### Telegram notifications (optional)

Set **`JETTY_TELEGRAM_BOT_TOKEN`** (from [@BotFather](https://t.me/BotFather)) and **`JETTY_TELEGRAM_CHAT_ID`** (your user id, group id, or channel id) to receive HTML alerts when:

- A tunnel is registered (`jetty share` / `http`, including `--print-url-only`)
- The edge WebSocket agent fails early (connectivity issues)
- The share session ends (tunnel left registered, or deleted if **`--delete-on-exit`** / **`JETTY_SHARE_DELETE_ON_EXIT=1`**), or delete fails
- `createTunnel` or another fatal error aborts the share

Disable without removing credentials: **`JETTY_TELEGRAM_ENABLED=0`**.

On the **Jetty Bridge** (this app’s Laravel API), the same env vars apply; set **`JETTY_TELEGRAM_BRIDGE=true`** to also notify on **`POST /api/tunnels`** (created) and **`DELETE /api/tunnels/{id}`** (deleted). Bridge notifications stay off by default so shared staging apps do not ping your bot until you opt in.

### Tunnel URL rewriting (any framework)

The edge WebSocket agent rewrites responses so the **browser stays on `https://{label}.tunnels…`** instead of jumping to a local canonical URL (`APP_URL`, `.test`, `localhost`, etc.). This applies to **any** HTTP stack (Laravel, Rails, Django, Next, Vite, static sites)—not only Laravel.

**HTTP headers:** **`Location`**, **`X-Inertia-Location`**, and **`Refresh`** (`meta` refresh–style `url=`) are rewritten when the target host is in the lookup set:

- The **upstream** host you’re sharing (e.g. **`127.0.0.1`** or **`myapp.test`**),
- **`APP_URL`** from a walked-up **`.env`** and/or the **`APP_URL`** environment variable,
- Extra hosts from **`JETTY_SHARE_REWRITE_HOSTS`** (comma-separated), e.g. **`myapp.test,www.myapp.test`**.

Opt out of header rewriting: **`JETTY_SHARE_NO_LOCATION_REWRITE=1`**.

**Response bodies (default on):** HTML **`href` / `src` / `action` / `srcset` / `meta refresh`**, **`url()`** inside CSS (inline **`style`** and **`<style>`**), and **quoted absolute URLs** in inline **`<script>`** and standalone **`application/javascript`** responses. Standalone JSON and binary content are not rewritten.

- Disable all body rewriting: **`JETTY_SHARE_NO_BODY_REWRITE=1`** or **`JETTY_SHARE_BODY_REWRITE=0`**.
- Disable only JS string rewriting: **`JETTY_SHARE_NO_JS_REWRITE=1`** (CSS + HTML attributes still run when body rewrite is on).
- Disable only CSS **`url()`** rewriting: **`JETTY_SHARE_NO_CSS_REWRITE=1`**.
- Max body size to process (bytes): **`JETTY_SHARE_BODY_REWRITE_MAX_BYTES`** (default **4194304**).

**CLI (same run, overrides env):** **`jetty share --no-body-rewrite`**, **`--no-js-rewrite`**, **`--no-css-rewrite`**.

**Edge WebSocket drops (“HTTP forwarding paused”):** Heartbeats use the REST API; the **agent** uses a separate **`wss://…/agent`** connection. If that socket goes idle, some proxies close it (~60s). The CLI sends a **WebSocket ping every 25s** to keep `/agent` alive; disable only for debugging with **`JETTY_SHARE_NO_WS_PING=1`**. If disconnects persist, raise **`proxy_read_timeout`** on nginx for the tunnel host (see Bridge edge deployment docs). Run **`jetty share` again** to reconnect the agent.

Dynamic JS (concatenated URLs) may still escape; list every host your app emits in **`JETTY_SHARE_REWRITE_HOSTS`**. Optional app-side tweaks (e.g. Laravel **`URL::forceRootUrl`**, Rails **`default_url_options`**, Next **`assetPrefix`**) can complement the agent but are not required.

### Long idle (heartbeat session)

While **`jetty share`** is running the heartbeat loop, if there is **no HTTP traffic** through the tunnel for **`JETTY_SHARE_IDLE_PROMPT_MINUTES`** (default **120**), the CLI prints a prompt. You then have **`JETTY_SHARE_IDLE_GRACE_MINUTES`** (default **60**) to type **`keep`** (or **`y`**) and Enter, or to hit the public URL again. If there is still no traffic and no confirmation, the tunnel is **removed via the API** so abandoned sessions do not linger. Set **`JETTY_SHARE_IDLE_DISABLE=1`** to turn this off, or **`JETTY_SHARE_IDLE_PROMPT_MINUTES=0`**.

## Split repository

This directory is intended to be the root of a **standalone Git repository** (e.g. `github.com/yourorg/jetty-client`) so you can version and tag releases independently of the main Jetty app. In the main Jetty monorepo it lives at `jetty-client/` for co-development; publish by pushing this tree to `jetty-client` and submitting **`jetty/client`** to [Packagist](https://packagist.org).

## PHAR

**Hosted installer (Jetty web app):** if your deployment serves `https://your-app/install/jetty.sh`, you can install the latest release PHAR as **`jetty`** in `~/.local/bin` with:

```bash
curl -fsSL "https://your-app/install/jetty.sh" | bash
exec "$SHELL" -l
```

(`install/jetty-php.sh` is an alias of the same script.)

Then `jetty config set server …`, `jetty config set token …`, `jetty share 8000`.

**Build locally:**

```bash
git clone https://github.com/yourorg/jetty-client.git
cd jetty-client
composer install
composer run build-phar
php dist/jetty-php.phar version
```

Prebuilt **`jetty-php.phar`** (Box output filename) is attached to **`cli-v*`** releases on the main Jetty app repository (GitHub Actions “Release CLI”). Download from **Releases**, not from the web app’s `public/` tree.

### Update the CLI (`jetty update`)

**PHAR install:** `jetty update` downloads **`jetty-php.phar`** from the latest matching GitHub Release (same as before). **`jetty self-update`** is an alias.

**Composer install:** `jetty update` runs **`composer update jetty/client --no-interaction`** in the **app** that contains `vendor/jetty/client` (resolved by walking up from **current working directory** so path-repository / symlink installs update **your** `composer.lock`, not the package source tree). Requires **`composer`** on `PATH` or **`COMPOSER_BINARY`**. **`--check`** runs `composer outdated jetty/client` (or `composer show --self --latest` when you are developing this repo as the root package). **`--force`** adds **`--no-cache`** and **`--with-all-dependencies`** to Composer (refreshes transitive deps, e.g. amphp). Run **`jetty update` from the app root** (or a subdirectory) so cwd can reach the project with `vendor/jetty/client`.

**Update hint in the console:** After other successful commands (not `jetty update` / `version` / `help`), the CLI may print **`jetty: update available (cli-v…) — run: jetty update`** when GitHub has a newer release than your binary. Checks are **cached** (~24h) in **`~/.config/jetty/update-notice.json`**; a new release is announced right away; the same release is reminded at most once per 24h. Disable with **`JETTY_SKIP_UPDATE_NOTICE=1`**. Skipped when **`JETTY_LOCAL_PHAR_URL`** is set (PHAR dev installs).

**Update global installs from a project copy:** **`jetty global-update`** runs **`composer global update jetty/client`** when `jetty/client` is installed globally, and/or refreshes a PHAR at **`~/.local/bin/jetty`** or **`JETTY_PHAR_PATH`**. Use **`--composer`** or **`--phar`** to update only one. Same **`--check`** / **`--force`** as `jetty update`.

**Default GitHub repo (PHAR path):** **`shaferllc/jetty`** (`ApiClient::DEFAULT_PHAR_RELEASES_REPO`). **Forks / private:** **`JETTY_PHAR_RELEASES_REPO`** or **`JETTY_CLI_GITHUB_REPO`**. Optional **`JETTY_PHAR_GITHUB_TOKEN`** for private repos or rate limits.

**Local Jetty app (e.g. `jetty.test`):** set **`JETTY_LOCAL_PHAR_URL`** to your **`/install/jetty-local.phar`** URL (same as the curl installer). Then **`jetty update`** always re-downloads that PHAR—no GitHub semver gate—so each new build is picked up. Unset the variable to use GitHub releases again. Your **`~/.config/jetty/config.json`** is not touched by update; you only need **`jetty setup`** again if you want to change Bridge URL or token.

```bash
# PHAR — only if releases live somewhere other than shaferllc/jetty:
export JETTY_CLI_GITHUB_REPO=your-org/jetty
jetty version --check-update   # PHAR: GitHub; Composer: outdated / show --self (same as jetty update --check)
jetty update --check           # PHAR: compare to GitHub; Composer: outdated / show --self
jetty update                   # PHAR: replace file; Composer: composer update jetty/client
jetty update --force           # PHAR: re-download; Composer: composer update --no-cache --with-all-dependencies
```

Bump **`ApiClient::VERSION`** in `src/ApiClient.php` when you tag a release so the PHAR update path can compare versions (release tags use `cli-v1.2.3` → compared as `1.2.3`).

## Requirements

- PHP 8.2+
- Extensions: `curl`, `json`, `dom` (HTML body rewriting), `openssl` (for `wss://` edge URLs), `zlib` (for PHAR)
- Optional: `pcntl` for reliable Ctrl+C during `jetty share`
- Dependencies include **amphp/websocket-client** (Composer) for the tunnel agent

## Tests (package developers)

```bash
cd jetty-client && composer install && composer test
```

## Replacing `jetty/php-cli`

This package **`replace`s** the legacy name `jetty/php-cli`. Prefer `composer require jetty/client`.

## License

MIT
