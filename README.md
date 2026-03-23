# jetty-client

Composer package **`jetty/client`**: PHP CLI for the [Jetty](https://jetty.dev) tunnel API (`/api/tunnels`).

**Scope:** `list`, `delete`, `share` (alias `http`) — register tunnels and send heartbeats. This client does **not** run the edge WebSocket agent; use the Jetty Go CLI for that.

## Install

```bash
composer require jetty/client
```

The binary is installed to **`vendor/bin/jetty-php`** (add `vendor/bin` to your PATH, or call it by full path).

```bash
vendor/bin/jetty-php version
```

### Global install

```bash
composer global require jetty/client
# Ensure Composer’s global bin directory is on your PATH
jetty-php version
```

## Configuration

Prefer a **JSON config file** so you do not need shell exports. See `composer show jetty/client` or run `vendor/bin/jetty-php help` for paths (`~/.config/jetty/config.json`, `./jetty.config.json`, etc.).

```json
{
  "api_url": "https://your-jetty.example",
  "token": "your-personal-access-token"
}
```

Optional fallbacks: `JETTY_API_URL`, `JETTY_TOKEN`, or flags `--api-url` / `--token`.

## Split repository

This directory is intended to be the root of a **standalone Git repository** (e.g. `github.com/yourorg/jetty-client`) so you can version and tag releases independently of the main Jetty app. In the main Jetty monorepo it lives at `jetty-client/` for co-development; publish by pushing this tree to `jetty-client` and submitting **`jetty/client`** to [Packagist](https://packagist.org).

## PHAR

```bash
git clone https://github.com/yourorg/jetty-client.git
cd jetty-client
composer install
composer run build-phar
php dist/jetty-php.phar version
```

Prebuilt **`jetty-php.phar`** may also be attached to **`cli-v*`** releases on the main Jetty app repository (see that repo’s GitHub Actions).

## Requirements

- PHP 8.2+
- Extensions: `curl`, `json`, `zlib` (for PHAR)
- Optional: `pcntl` for reliable Ctrl+C during `jetty-php share`

## Replacing `jetty/php-cli`

This package **`replace`s** the legacy name `jetty/php-cli`. Prefer `composer require jetty/client`.

## License

MIT
