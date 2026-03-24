# jetty-client

Composer package **`jetty/client`**: PHP build of the **`jetty`** CLI for the [Jetty](https://jetty.dev) tunnel API (`/api/tunnels`).

**Scope:** `list`, `delete`, `share` (alias `http`) — register tunnels and send heartbeats. This client does **not** run the edge WebSocket agent; use the Jetty Go binary for that.

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
# Ensure Composer’s global bin directory is on your PATH
jetty version
```

## Configuration

Prefer a **JSON config file** so you do not need shell exports. See `composer show jetty/client` or run `vendor/bin/jetty help` for paths (`~/.config/jetty/config.json`, `./jetty.config.json`, etc.).

```json
{
  "api_url": "https://your-jetty.example",
  "token": "your-personal-access-token"
}
```

Optional fallbacks: `JETTY_API_URL`, `JETTY_SERVER`, `JETTY_TOKEN`, or flags `--api-url` / `--token`.

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

### Update a PHAR in place

**Default:** `jetty self-update` uses GitHub **`shaferllc/jetty`** (same as `ApiClient::DEFAULT_PHAR_RELEASES_REPO`). No env vars required for the upstream project.

**Forks / private releases:** set **`JETTY_PHAR_RELEASES_REPO`** or **`JETTY_CLI_GITHUB_REPO`** to `owner/repo`. Optional: **`JETTY_PHAR_GITHUB_TOKEN`** for private repos or rate limits.

```bash
# only if your releases live somewhere other than shaferllc/jetty:
export JETTY_CLI_GITHUB_REPO=your-org/jetty
jetty version --check-update   # optional: query GitHub for newer cli-v* release
jetty self-update --check      # show latest asset URL without installing
jetty self-update              # download jetty-php.phar from latest release (semver > built-in VERSION)
jetty self-update --force      # re-download even if semver matches
```

Bump **`ApiClient::VERSION`** in `src/ApiClient.php` when you tag a release so `self-update` can compare versions sensibly (release tags use `cli-v1.2.3` → compared as `1.2.3`).

## Requirements

- PHP 8.2+
- Extensions: `curl`, `json`, `zlib` (for PHAR)
- Optional: `pcntl` for reliable Ctrl+C during `jetty share`

## Replacing `jetty/php-cli`

This package **`replace`s** the legacy name `jetty/php-cli`. Prefer `composer require jetty/client`.

## License

MIT
