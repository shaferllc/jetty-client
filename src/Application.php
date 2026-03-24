<?php

declare(strict_types=1);

namespace JettyCli;

final class Application
{
    public function run(array $argv): int
    {
        array_shift($argv);

        try {
            [$global, $rest] = $this->parseGlobalFlags($argv);

            if ($rest === []) {
                $cfg = $this->resolvedConfig($global);
                if (trim($cfg->token) === '') {
                    try {
                        SetupWizard::runOnboarding($global['config']);
                    } catch (\Throwable $e) {
                        $this->stderr($e->getMessage());

                        return 1;
                    }

                    return 0;
                }
                $this->stderr($this->helpText());

                return 1;
            }

            $command = array_shift($rest);

            return match ($command) {
                'version', '--version', '-V' => $this->cmdVersion($rest),
                'self-update' => $this->cmdSelfUpdate($rest),
                'list' => $this->cmdList($global),
                'delete' => $this->cmdDelete($global, $rest),
                'share', 'http' => $this->cmdShare($global, $rest),
                'onboard' => $this->cmdOnboard($global, $rest),
                'setup' => $this->cmdSetup($global, $rest),
                'config' => $this->cmdConfig($global, $rest),
                'help', '--help', '-h' => $this->cmdHelp(),
                default => throw new \InvalidArgumentException('Unknown command: '.$command."\n".$this->helpText()),
            };
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }
    }

    /**
     * @return array{0: array{api-url: ?string, token: ?string, config: ?string}, 1: list<string>}
     */
    private function parseGlobalFlags(array $argv): array
    {
        $apiUrl = null;
        $token = null;
        $config = null;
        $rest = [];
        $n = count($argv);

        for ($i = 0; $i < $n; $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--config=')) {
                $config = substr($arg, strlen('--config='));
                if ($config === '') {
                    throw new \InvalidArgumentException('--config= requires a path');
                }

                continue;
            }
            if ($arg === '--config') {
                $config = $argv[++$i] ?? throw new \InvalidArgumentException('--config requires a path');
                if ($config === '' || str_starts_with($config, '--')) {
                    throw new \InvalidArgumentException('--config requires a path');
                }

                continue;
            }
            if (str_starts_with($arg, '--api-url=')) {
                $apiUrl = substr($arg, strlen('--api-url='));

                continue;
            }
            if ($arg === '--api-url') {
                $apiUrl = $argv[++$i] ?? throw new \InvalidArgumentException('--api-url requires a value');
                if ($apiUrl === '' || str_starts_with($apiUrl, '--')) {
                    throw new \InvalidArgumentException('--api-url requires a value');
                }

                continue;
            }
            if (str_starts_with($arg, '--token=')) {
                $token = substr($arg, strlen('--token='));

                continue;
            }
            if ($arg === '--token') {
                $token = $argv[++$i] ?? throw new \InvalidArgumentException('--token requires a value');
                if ($token === '' || str_starts_with($token, '--')) {
                    throw new \InvalidArgumentException('--token requires a value');
                }

                continue;
            }
            $rest[] = $arg;
        }

        return [['api-url' => $apiUrl, 'token' => $token, 'config' => $config], $rest];
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string}  $global
     */
    private function resolvedConfig(array $global): Config
    {
        return Config::resolve($global['config'])->merge($global['api-url'], $global['token']);
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string}  $global
     */
    private function client(array $global): ApiClient
    {
        $cfg = $this->resolvedConfig($global);
        $cfg->validate();

        return new ApiClient($cfg->apiUrl, $cfg->token);
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdVersion(array $args): int
    {
        $this->stdout('jetty '.ApiClient::VERSION);

        $pharPath = \Phar::running(false);
        if ($pharPath !== '' && in_array('--check-update', $args, true)) {
            $repo = $this->releasesRepo();
            $token = $this->githubTokenForReleases();
            if ($repo !== null) {
                $latest = GitHubPharRelease::latest($repo, $token);
                if ($latest !== null) {
                    $remoteSemver = GitHubPharRelease::tagToSemver($latest['tag_name']);
                    $cmp = version_compare($remoteSemver, ApiClient::VERSION);
                    if ($cmp > 0) {
                        $this->stdout('Update available: '.$latest['tag_name'].' (you have '.ApiClient::VERSION.') — run: jetty self-update');
                    } else {
                        $this->stdout('Up to date with latest release '.$latest['tag_name'].'.');
                    }
                }
            }
        }

        return 0;
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdSelfUpdate(array $args): int
    {
        $pharPath = \Phar::running(false);
        if ($pharPath === '') {
            throw new \InvalidArgumentException('self-update only works when jetty is run as a PHAR file.');
        }

        $checkOnly = in_array('--check', $args, true);
        $force = in_array('--force', $args, true);

        $repo = $this->releasesRepo();
        if ($repo === null) {
            throw new \InvalidArgumentException(
                'Set JETTY_PHAR_RELEASES_REPO or JETTY_CLI_GITHUB_REPO to owner/repo (GitHub releases with jetty-php.phar).'
            );
        }

        $token = $this->githubTokenForReleases();
        $latest = GitHubPharRelease::latest($repo, $token);
        if ($latest === null) {
            throw new \RuntimeException('Could not find a release with jetty-php.phar on '.$repo.'.');
        }

        $remoteSemver = GitHubPharRelease::tagToSemver($latest['tag_name']);
        $cmp = version_compare($remoteSemver, ApiClient::VERSION);

        if ($checkOnly) {
            $this->stdout('Current: '.ApiClient::VERSION);
            $this->stdout('Latest release: '.$latest['tag_name'].' — '.$latest['html_url']);
            $this->stdout('Download: '.$latest['browser_download_url']);
            if ($cmp > 0) {
                $this->stdout('A newer version is available. Run: jetty self-update');
            } elseif ($cmp < 0) {
                $this->stdout('This PHAR is newer than the latest GitHub release (unusual). Use --force to reinstall.');
            } else {
                $this->stdout('Semver matches the latest release.');
            }

            return 0;
        }

        if ($cmp <= 0 && ! $force) {
            $this->stdout('Already at or past latest release '.$latest['tag_name'].'. Use --force to re-download.');

            return 0;
        }

        $tmp = $pharPath.'.download.'.uniqid('', true);
        try {
            GitHubPharRelease::downloadFile($latest['browser_download_url'], $tmp, $token);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        if (! is_file($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);
            throw new \RuntimeException('Downloaded file looks invalid (too small).');
        }

        @chmod($tmp, 0755);
        if (! @rename($tmp, $pharPath)) {
            @unlink($tmp);
            throw new \RuntimeException(
                'Could not replace '.$pharPath.'. Try: mv '.basename($tmp).' '.basename($pharPath).' (from the same directory), or run self-update from a shell outside the PHAR.'
            );
        }

        $this->stderr('Updated to '.$latest['tag_name'].' ('.$remoteSemver.'). Run jetty version to confirm.');

        return 0;
    }

    private function releasesRepo(): ?string
    {
        foreach (['JETTY_PHAR_RELEASES_REPO', 'JETTY_CLI_GITHUB_REPO'] as $key) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    private function githubTokenForReleases(): ?string
    {
        foreach (['JETTY_PHAR_GITHUB_TOKEN', 'JETTY_CLI_GITHUB_TOKEN', 'GITHUB_TOKEN'] as $key) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    private function cmdHelp(): int
    {
        $this->stdout($this->helpText());

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string}  $global
     */
    private function cmdList(array $global): int
    {
        $client = $this->client($global);
        $tunnels = $client->listTunnels();

        if ($tunnels === []) {
            $this->stdout('No tunnels.');

            return 0;
        }

        foreach ($tunnels as $t) {
            $id = $t['id'] ?? '';
            $status = $t['status'] ?? '';
            $public = $t['public_url'] ?? '';
            $local = $t['local_target'] ?? '';
            $this->stdout("{$id}  {$status}  {$public}  {$local}");
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string}  $global
     * @param  list<string>  $args
     */
    private function cmdDelete(array $global, array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Usage: jetty delete <tunnel-id>');
        }
        $id = (int) $args[0];
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid tunnel id');
        }

        $this->client($global)->deleteTunnel($id);
        $this->stderr("Deleted tunnel {$id}");

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdOnboard(array $global, array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException("Usage: jetty onboard\n".$this->helpText());
        }
        try {
            SetupWizard::runOnboarding($global['config']);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdSetup(array $global, array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException("Usage: jetty setup\n".$this->helpText());
        }
        try {
            SetupWizard::runSetupMenu($global['config']);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string}  $global
     * @param  list<string>  $args
     */
    private function cmdConfig(array $global, array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException("Usage: jetty config set|get|clear|wizard ...\n".$this->helpText());
        }
        $sub = array_shift($args);

        return match ($sub) {
            'set' => $this->cmdConfigSet($args),
            'get' => $this->cmdConfigGet($args),
            'clear' => $this->cmdConfigClear($args),
            'wizard' => $this->cmdSetup($global, []),
            default => throw new \InvalidArgumentException('Unknown config subcommand: '.$sub."\n".$this->helpText()),
        };
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigSet(array $args): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException('Usage: jetty config set <key> <value>  (keys: server, api-url, token, subdomain, domain)');
        }
        $key = $args[0];
        $value = $args[1];
        $jsonKey = Config::normalizeConfigCliKey($key);
        Config::writeUserConfigMerged([$jsonKey => trim($value)]);
        $path = Config::userConfigPath() ?? '(unknown)';
        $this->stdout("Wrote {$jsonKey} to {$path}");

        return 0;
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigGet(array $args): int
    {
        $m = Config::readUserConfigMap();
        if ($args === []) {
            if ($m === []) {
                $this->stdout('(no keys in user config file)');

                return 0;
            }
            foreach (['api_url', 'server', 'token', 'subdomain', 'custom_domain'] as $k) {
                $this->printConfigLine($m, $k);
            }

            return 0;
        }
        $jsonKey = Config::normalizeConfigCliKey($args[0]);
        $this->printConfigLine($m, $jsonKey);

        return 0;
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigClear(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Usage: jetty config clear <key|all>');
        }
        $a = strtolower(trim($args[0]));
        if ($a === 'all') {
            Config::clearAllUserConfig();
            $this->stdout('Cleared user config keys.');

            return 0;
        }
        $jsonKey = Config::normalizeConfigCliKey($a);
        Config::clearUserConfigKey($jsonKey);
        $this->stdout("Removed {$jsonKey}");

        return 0;
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function printConfigLine(array $m, string $jsonKey): void
    {
        if (! array_key_exists($jsonKey, $m) || $m[$jsonKey] === null || $m[$jsonKey] === '') {
            $this->stdout("{$jsonKey}=");

            return;
        }
        $s = trim((string) $m[$jsonKey]);
        if ($jsonKey === 'token' && $s !== '') {
            $s = strlen($s) <= 8 ? '****' : substr($s, 0, 4).'…'.substr($s, -4);
        }
        $this->stdout("{$jsonKey}={$s}");
    }

    private function cmdShare(array $global, array $args): int
    {
        $host = '127.0.0.1';
        $printUrlOnly = false;
        $subdomain = null;

        $positional = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--host=')) {
                $host = substr($arg, strlen('--host='));

                continue;
            }
            if (str_starts_with($arg, '--subdomain=')) {
                $subdomain = substr($arg, strlen('--subdomain='));
                if ($subdomain === '') {
                    throw new \InvalidArgumentException('--subdomain= requires a value');
                }

                continue;
            }
            if ($arg === '--print-url-only') {
                $printUrlOnly = true;

                continue;
            }
            if ($arg === '--skip-edge') {
                continue;
            }
            $positional[] = $arg;
        }

        if ($positional === []) {
            throw new \InvalidArgumentException('Usage: jetty share <port> [--host=127.0.0.1] [--subdomain=label] [--print-url-only] [--skip-edge] (alias: http)');
        }

        $port = (int) $positional[0];
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('port must be between 1 and 65535');
        }

        $cfg = $this->resolvedConfig($global);
        $cfg->validate();
        $client = new ApiClient($cfg->apiUrl, $cfg->token);
        if ($subdomain === null || trim((string) $subdomain) === '') {
            $d = trim($cfg->defaultSubdomain);
            if ($d !== '') {
                $subdomain = $d;
            }
        }

        $data = $client->createTunnel($host, $port, $subdomain);

        $publicUrl = (string) ($data['public_url'] ?? '');
        $localTarget = (string) ($data['local_target'] ?? '');
        $id = (int) ($data['id'] ?? 0);
        $status = (string) ($data['status'] ?? '');
        $subdomain = (string) ($data['subdomain'] ?? '');
        $edge = is_array($data['edge'] ?? null) ? $data['edge'] : [];
        $ws = (string) ($edge['websocket_url'] ?? '');

        if ($printUrlOnly) {
            $this->stdout($publicUrl);

            return 0;
        }

        $this->stderr('Public URL:  '.$publicUrl);
        $this->stderr('Local:       '.$localTarget);
        $this->stderr('Tunnel id:   '.$id);
        $this->stderr('Status:      '.$status);
        if ($ws !== '') {
            $this->stderr('Edge WS:     '.$ws);
        }

        $suffix = $this->tunnelHostSuffix();
        $this->stderr("\nTry HTTP via edge:\n  curl -H \"Host: {$subdomain}.{$suffix}\" http://127.0.0.1:8090/");
        $this->stderr("(adjust :8090 if your jetty-edge listens elsewhere)\n");
        $this->stderr('Note: PHP client does not run the edge WebSocket agent. For live forwarding use the Jetty Go CLI.');

        try {
            $client->heartbeat($id);
        } catch (\Throwable $e) {
            $this->stderr('warning: initial heartbeat: '.$e->getMessage());
        }

        $stop = false;
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            $handler = function () use (&$stop): void {
                $stop = true;
            };
            \pcntl_signal(\SIGINT, $handler);
            \pcntl_signal(\SIGTERM, $handler);
        } else {
            $this->stderr("\n(ext-pcntl not loaded — Ctrl+C handling may vary by platform.)\n");
        }

        $this->stderr("\nSending heartbeats every 25s. Ctrl+C to delete this tunnel and exit.\n");

        $nextBeat = time() + 25;
        while (! $stop) {
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
            if ($stop) {
                break;
            }
            $now = time();
            if ($now >= $nextBeat) {
                try {
                    $client->heartbeat($id);
                } catch (\Throwable $e) {
                    $this->stderr('heartbeat failed: '.$e->getMessage());
                }
                $nextBeat = $now + 25;
            }
            usleep(200_000);
        }

        try {
            $client->deleteTunnel($id);
            $this->stderr("Tunnel {$id} deleted.\n");
        } catch (\Throwable $e) {
            $this->stderr('warning: could not delete tunnel '.$id.': '.$e->getMessage());
        }

        return 0;
    }

    private function tunnelHostSuffix(): string
    {
        $v = getenv('JETTY_PUBLIC_TUNNEL_HOST');
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $v = getenv('JETTY_TUNNEL_HOST');
        if (is_string($v) && $v !== '') {
            return $v;
        }

        return 'tunnel.jetty.test';
    }

    private function helpText(): string
    {
        return <<<'TXT'
Jetty PHP client (Composer package jetty/client) — tunnel API helper.

Config file (recommended): JSON. First file wins:
  --config=PATH, JETTY_CONFIG, ~/.config/jetty/config.json, ~/.jetty.json, ./jetty.config.json

  { "api_url": "https://your-jetty.example", "token": "your-personal-access-token",
    "subdomain": "optional-default-label", "custom_domain": "optional-hostname" }

Values in the file override JETTY_* env for keys that are set. CLI flags override everything.

User config file (~/.config/jetty/config.json):
  jetty                      First-run: runs setup when no token is configured (same as onboard)
  jetty onboard              First-run: Bridge URL, pick server, paste token
  jetty setup                Change settings (menu; same as jetty config wizard)
  jetty config set server|api-url|token|subdomain|domain <value>
  jetty config get [key]
  jetty config clear <key|all>
  jetty config wizard        Alias for jetty setup

Environment (optional fallback):
  JETTY_API_URL   Base URL (default http://127.0.0.1:8000)
  JETTY_TOKEN     Personal access token from the dashboard

Global flags:
  --config=PATH   Use this JSON file (see above)
  --api-url=URL   Override api URL
  --token=TOKEN   Override token

Commands:
  jetty version [--check-update]
  jetty self-update [--check] [--force]
  jetty onboard              (see also: plain `jetty` when no token)
  jetty setup
  jetty list
  jetty delete <id>
  jetty config set|get|clear|wizard ...
  jetty share <port> [--host=127.0.0.1] [--subdomain=label] [--print-url-only] [--skip-edge]
    (alias: http)

PHAR updates: set JETTY_PHAR_RELEASES_REPO or JETTY_CLI_GITHUB_REPO=owner/repo (cli-v* releases with jetty-php.phar).
  self-update --check   show latest release without installing
  Optional token: JETTY_PHAR_GITHUB_TOKEN (private repos / rate limits)

Install: composer require jetty/client  (binary: vendor/bin/jetty)

TXT;
    }

    private function stdout(string $s): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }

    private function stderr(string $s): void
    {
        fwrite(\STDERR, $s.\PHP_EOL);
    }
}
