<?php

declare(strict_types=1);

namespace JettyCli;

use Composer\InstalledVersions;

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
                        SetupWizard::runOnboarding($global['config'], $global['region'] ?? null);
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
                'update', 'self-update' => $this->cmdSelfUpdate($rest),
                'list' => $this->cmdList($global),
                'delete' => $this->cmdDelete($global, $rest),
                'share', 'http' => $this->cmdShare($global, $rest),
                'onboard' => $this->cmdOnboard($global, $rest),
                'setup' => $this->cmdSetup($global, $rest),
                'logout' => $this->cmdLogout($rest),
                'reset' => $this->cmdReset($rest),
                'config' => $this->cmdConfig($global, $rest),
                'help', '--help', '-h' => $this->cmdHelp(),
                default => throw new \InvalidArgumentException(
                    'Unknown command: '.$command.$this->unknownCommandUpgradeHint($command)."\n".$this->helpText()
                ),
            };
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }
    }

    /**
     * @return array{0: array{api-url: ?string, token: ?string, config: ?string, region: ?string}, 1: list<string>}
     */
    private function parseGlobalFlags(array $argv): array
    {
        $apiUrl = null;
        $token = null;
        $config = null;
        $region = null;
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
            if (str_starts_with($arg, '--region=')) {
                $r = substr($arg, strlen('--region='));
                if (trim($r) !== '') {
                    DefaultBridge::normalizeRegion($r);
                    $region = trim($r);
                }

                continue;
            }
            if ($arg === '--region') {
                $r = $argv[++$i] ?? throw new \InvalidArgumentException('--region requires a value');
                if ($r === '' || str_starts_with($r, '--')) {
                    throw new \InvalidArgumentException('--region requires a value');
                }
                DefaultBridge::normalizeRegion($r);
                $region = $r;

                continue;
            }
            $rest[] = $arg;
        }

        $region = is_string($region) && trim($region) !== '' ? trim($region) : null;

        return [['api-url' => $apiUrl, 'token' => $token, 'config' => $config, 'region' => $region], $rest];
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     */
    private function resolvedConfig(array $global): Config
    {
        return Config::resolve($global['config'], $global['region'] ?? null)->merge($global['api-url'], $global['token']);
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
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
        if (in_array('--machine', $args, true)) {
            $this->stdout(ApiClient::VERSION);

            return 0;
        }

        $this->stdout('jetty '.ApiClient::VERSION);

        $pharPath = \Phar::running(false);
        if ($pharPath !== '' && in_array('--check-update', $args, true)) {
            $repo = $this->releasesRepo();
            $token = $this->githubTokenForReleases();
            $latest = GitHubPharRelease::latest($repo, $token);
            if ($latest !== null) {
                $remoteSemver = GitHubPharRelease::tagToSemver($latest['tag_name']);
                $cmp = version_compare($remoteSemver, ApiClient::VERSION);
                if ($cmp > 0) {
                    $this->stdout('Update available: '.$latest['tag_name'].' (you have '.ApiClient::VERSION.') — run: jetty update');
                } else {
                    $this->stdout('Up to date with latest release '.$latest['tag_name'].'.');
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
        if ($pharPath !== '') {
            return $this->updatePharInPlace($args, $pharPath);
        }

        return $this->updateComposerJettyClient($args);
    }

    /**
     * @param  list<string>  $args
     */
    private function updatePharInPlace(array $args, string $pharPath): int
    {
        $checkOnly = in_array('--check', $args, true);
        $force = in_array('--force', $args, true);

        $localUrl = $this->localPharUpdateUrl();
        if ($localUrl !== null) {
            if ($checkOnly) {
                $this->stdout('Install: PHAR (local dev — JETTY_LOCAL_PHAR_URL)');
                $this->stdout('URL: '.$localUrl);
                $this->stdout('Current: '.ApiClient::VERSION);
                $this->stdout('jetty update re-downloads from this URL every time (ignores GitHub semver). Unset JETTY_LOCAL_PHAR_URL to use GitHub releases again.');

                return 0;
            }

            $this->applyPharDownload($pharPath, $localUrl, null);
            $this->stderr('Updated PHAR from JETTY_LOCAL_PHAR_URL. Run jetty version to confirm.');
            $this->emitPostUpdateConfigTip();

            return 0;
        }

        $repo = $this->releasesRepo();
        $token = $this->githubTokenForReleases();
        $latest = GitHubPharRelease::latest($repo, $token);
        if ($latest === null) {
            throw new \RuntimeException('Could not find a release with jetty-php.phar on '.$repo.'.');
        }

        $remoteSemver = GitHubPharRelease::tagToSemver($latest['tag_name']);
        $cmp = version_compare($remoteSemver, ApiClient::VERSION);

        if ($checkOnly) {
            $this->stdout('Install: PHAR (GitHub)');
            $this->stdout('Current: '.ApiClient::VERSION);
            $this->stdout('Latest release: '.$latest['tag_name'].' — '.$latest['html_url']);
            $this->stdout('Download: '.$latest['browser_download_url']);
            if ($cmp > 0) {
                $this->stdout('A newer version is available. Run: jetty update');
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

        $this->applyPharDownload($pharPath, $latest['browser_download_url'], $token);

        $this->stderr('Updated PHAR to '.$latest['tag_name'].' ('.$remoteSemver.'). Run jetty version to confirm.');
        $this->emitPostUpdateConfigTip();

        return 0;
    }

    private function localPharUpdateUrl(): ?string
    {
        $u = getenv('JETTY_LOCAL_PHAR_URL');
        if (! is_string($u)) {
            return null;
        }
        $u = trim($u);
        if ($u === '') {
            return null;
        }
        if (! str_starts_with($u, 'http://') && ! str_starts_with($u, 'https://')) {
            return null;
        }

        return $u;
    }

    private function applyPharDownload(string $pharPath, string $url, ?string $githubToken): void
    {
        $tmp = $pharPath.'.download.'.uniqid('', true);
        try {
            GitHubPharRelease::downloadFile($url, $tmp, $githubToken);
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
                'Could not replace '.$pharPath.'. Try: mv '.basename($tmp).' '.basename($pharPath).' (from the same directory), or run jetty update from a shell outside the PHAR.'
            );
        }
    }

    private function emitPostUpdateConfigTip(): void
    {
        $this->stdout('Saved config (~/.config/jetty/config.json) is unchanged; run jetty setup only if you need a new Bridge URL or token.');
    }

    /**
     * @param  list<string>  $args
     */
    private function updateComposerJettyClient(array $args): int
    {
        if (! class_exists(InstalledVersions::class)) {
            throw new \RuntimeException(
                'jetty update: Composer metadata not loaded. Use the PHAR install, or run `composer update jetty/client` from your project.'
            );
        }
        if (! InstalledVersions::isInstalled('jetty/client')) {
            throw new \RuntimeException(
                'jetty update: package jetty/client is not installed via Composer. Use the PHAR, or run: composer require jetty/client'
            );
        }

        $root = self::composerProjectRootForJettyClient();
        $composer = self::resolveComposerBinary();

        $checkOnly = in_array('--check', $args, true);
        $force = in_array('--force', $args, true);

        if ($checkOnly) {
            $this->stdout('Install: Composer (project: '.$root.')');
            $this->stdout('Client version in this run: '.ApiClient::VERSION);
            $rootPackage = InstalledVersions::getRootPackage();
            if (($rootPackage['name'] ?? '') === 'jetty/client') {
                self::runComposerInDirectory($root, $composer, ['show', '--self', '--latest', '--no-ansi']);
            } else {
                self::runComposerInDirectory($root, $composer, ['outdated', 'jetty/client', '--direct', '--no-ansi']);
            }

            return 0;
        }

        $cmd = ['update', 'jetty/client', '--no-interaction'];
        if ($force) {
            $cmd[] = '--no-cache';
        }

        $code = self::runComposerInDirectory($root, $composer, $cmd);
        if ($code !== 0) {
            throw new \RuntimeException(
                'composer '.implode(' ', $cmd).' failed (exit '.$code.'). Run it manually from: '.$root
            );
        }

        $this->stdout('Updated jetty/client via Composer in '.$root.'. Run jetty version to confirm.');
        $this->emitPostUpdateConfigTip();

        return 0;
    }

    private static function composerProjectRootForJettyClient(): string
    {
        $raw = InstalledVersions::getInstallPath('jetty/client');
        if (! is_string($raw) || $raw === '') {
            throw new \RuntimeException('Could not resolve jetty/client install path.');
        }
        $path = realpath($raw);
        if ($path === false) {
            throw new \RuntimeException('jetty/client install path is not readable: '.$raw);
        }

        $suffix = DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'jetty'.DIRECTORY_SEPARATOR.'client';
        if (str_ends_with($path, $suffix)) {
            return dirname($path, 3);
        }

        return $path;
    }

    private static function resolveComposerBinary(): string
    {
        $env = getenv('COMPOSER_BINARY');
        if (is_string($env) && $env !== '' && (is_executable($env) || is_executable($env.'.bat'))) {
            return $env;
        }
        if (is_string($env) && $env !== '' && @is_file($env)) {
            return $env;
        }

        $out = shell_exec('command -v composer 2>/dev/null');
        $which = is_string($out) ? trim($out) : '';
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        throw new \RuntimeException(
            'composer was not found (install Composer on PATH or set COMPOSER_BINARY).'
        );
    }

    /**
     * @param  list<string>  $composerArgs  arguments after `composer`
     */
    private static function runComposerInDirectory(string $root, string $composer, array $composerArgs): int
    {
        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = array_merge([$composer], $composerArgs);
        $proc = @proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => STDOUT,
                2 => STDERR,
            ],
            $pipes,
            $root
        );
        if (! is_resource($proc)) {
            throw new \RuntimeException('Could not run composer (proc_open failed).');
        }

        return proc_close($proc);
    }

    private function releasesRepo(): string
    {
        foreach (['JETTY_PHAR_RELEASES_REPO', 'JETTY_CLI_GITHUB_REPO'] as $key) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return ApiClient::DEFAULT_PHAR_RELEASES_REPO;
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

    private function unknownCommandUpgradeHint(string $command): string
    {
        $newerOnly = ['setup', 'onboard', 'logout', 'reset', 'update'];
        if (! in_array($command, $newerOnly, true)) {
            return '';
        }

        return "\n\n"
            .'This build of jetty/client is too old for `jetty '.$command.'`. '
            .'Upgrade: reinstall the PHAR from your Jetty install URL or GitHub Releases, run `jetty self-update` if your PHAR supports it, or `composer update jetty/client`. '
            .'You can configure this version with `jetty config set api-url …` and `jetty config set token …`.';
    }

    private function cmdHelp(): int
    {
        $this->stdout($this->helpText());

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
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
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
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
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdOnboard(array $global, array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException("Usage: jetty onboard\n".$this->helpText());
        }
        try {
            SetupWizard::runOnboarding($global['config'], $global['region'] ?? null);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
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
     * @param  list<string>  $rest
     */
    private function cmdLogout(array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException("Usage: jetty logout\n".$this->helpText());
        }
        Config::clearUserConfigKey('token');
        $this->stdout('Removed saved API token from ~/.config/jetty/config.json.');
        $envTok = getenv('JETTY_TOKEN');
        if (is_string($envTok) && trim($envTok) !== '') {
            $this->stderr('Note: JETTY_TOKEN is still set in your environment; unset it to stop using that token.');
        }

        return 0;
    }

    /**
     * @param  list<string>  $rest
     */
    private function cmdReset(array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException("Usage: jetty reset\n".$this->helpText());
        }
        Config::resetLocalUserConfig();
        $this->stdout('Cleared local Jetty config (Bridge URL, token, subdomain, domain, tunnel_server) and removed ~/.jetty.json if present.');
        $this->stdout('Environment variables (JETTY_TOKEN, JETTY_API_URL, …) are unchanged; unset them if needed.');
        $this->stdout('Project files (./jetty.config.json or JETTY_CONFIG) are not deleted.');

        return 0;
    }

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
            throw new \InvalidArgumentException('Usage: jetty config set <key> <value>  (keys: server, api-url, token, subdomain, domain, tunnel-server)');
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
            foreach (['api_url', 'server', 'token', 'subdomain', 'custom_domain', 'tunnel_server'] as $k) {
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
        $localHost = '127.0.0.1';
        $tunnelServerFlag = null;
        $printUrlOnly = false;
        $skipEdge = false;
        $subdomain = null;

        $positional = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--server=')) {
                $v = trim(substr($arg, strlen('--server=')));
                if ($v === '') {
                    throw new \InvalidArgumentException('--server= requires a tunnel id (e.g. us-west-1)');
                }
                $this->assertTunnelServerLabel($v);
                $tunnelServerFlag = $v;

                continue;
            }
            if (str_starts_with($arg, '--site=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--site=');

                continue;
            }
            if (str_starts_with($arg, '--bind=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--bind=');

                continue;
            }
            if (str_starts_with($arg, '--local=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--local=');

                continue;
            }
            if (str_starts_with($arg, '--local-host=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--local-host=');

                continue;
            }
            if (str_starts_with($arg, '--host=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--host=');

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
                $skipEdge = true;

                continue;
            }
            $positional[] = $arg;
        }

        if (count($positional) > 1) {
            throw new \InvalidArgumentException('Usage: jetty share [port] [--server=us-west-1] [--site=127.0.0.1] [--subdomain=label] [--print-url-only] [--skip-edge] (alias: http)  --skip-edge: register + heartbeats only, no WebSocket agent');
        }

        $explicitPort = null;
        if ($positional !== []) {
            $rawPort = $positional[0];
            if (! is_numeric($rawPort) || (string) (int) $rawPort !== (string) $rawPort) {
                throw new \InvalidArgumentException('port must be a whole number between 1 and 65535');
            }
            $explicitPort = (int) $rawPort;
        }

        [$port, $portHint] = $this->resolveSharePort($localHost, $explicitPort);

        $cfg = $this->resolvedConfig($global);
        $cfg->validate();
        $client = new ApiClient($cfg->apiUrl, $cfg->token);
        if ($subdomain === null || trim((string) $subdomain) === '') {
            $d = trim($cfg->defaultSubdomain);
            if ($d !== '') {
                $subdomain = $d;
            }
        }

        $tunnelServer = $tunnelServerFlag !== null ? $tunnelServerFlag : trim($cfg->defaultTunnelServer);
        if ($tunnelServer === '') {
            $tunnelServer = null;
        } else {
            $this->assertTunnelServerLabel($tunnelServer);
        }

        if ($portHint !== null && ! $printUrlOnly) {
            $this->stderr($portHint);
        }

        $data = $client->createTunnel($localHost, $port, $subdomain, $tunnelServer);

        $publicUrl = (string) ($data['public_url'] ?? '');
        $localTarget = (string) ($data['local_target'] ?? '');
        $id = (int) ($data['id'] ?? 0);
        $status = (string) ($data['status'] ?? '');
        $subdomain = (string) ($data['subdomain'] ?? '');
        $edge = is_array($data['edge'] ?? null) ? $data['edge'] : [];
        $ws = (string) ($edge['websocket_url'] ?? '');
        $srvOut = isset($data['server']) && is_string($data['server']) && $data['server'] !== '' ? $data['server'] : null;
        $agentToken = (string) ($data['agent_token'] ?? '');

        if ($printUrlOnly) {
            $this->stdout($publicUrl);

            return 0;
        }

        $this->stderr('Public URL:  '.$publicUrl);
        $this->stderr('Local:       '.$localTarget);
        if ($srvOut !== null) {
            $this->stderr('Server:      '.$srvOut);
        }
        $this->stderr('Tunnel id:   '.$id);
        $this->stderr('Status:      '.$status);
        if ($ws !== '') {
            $this->stderr('Edge WS:     '.$ws);
        }

        $suffix = $this->tunnelHostSuffix();
        $this->stderr("\nTry HTTP via edge:\n  curl -H \"Host: {$subdomain}.{$suffix}\" http://127.0.0.1:8090/");
        $this->stderr("(adjust :8090 if your ingress listens elsewhere)\n");

        try {
            $client->heartbeat($id);
        } catch (\Throwable $e) {
            $this->stderr('warning: initial heartbeat: '.$e->getMessage());
        }

        $ranEdgeAgent = false;
        if (! $printUrlOnly && ! $skipEdge && $ws !== '' && $agentToken !== '') {
            try {
                EdgeAgent::run(
                    $ws,
                    $id,
                    $agentToken,
                    $localHost,
                    $port,
                    $client,
                    $id,
                    fn (string $m) => $this->stderr($m),
                );
                $ranEdgeAgent = true;
            } catch (\Throwable $e) {
                $this->stderr('edge agent failed: '.$e->getMessage());
                $this->stderr('Continuing with heartbeats only (no HTTP forwarding until you fix edge connectivity).');
            }
        } elseif (! $printUrlOnly && ! $skipEdge && $ws === '') {
            $this->stderr('Note: Bridge returned no edge WebSocket URL (JETTY_EDGE_WS_URL). Heartbeats only.');
        } elseif (! $printUrlOnly && ! $skipEdge && $agentToken === '') {
            $this->stderr('Note: no agent_token in API response — heartbeats only.');
        }

        if ($ranEdgeAgent) {
            // EdgeAgent already ran heartbeats during the session.
        } else {
            $this->stderr("\nSending heartbeats every 25s. Ctrl+C to delete this tunnel and exit.\n");

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
        }

        try {
            $client->deleteTunnel($id);
            $this->stderr("Tunnel {$id} deleted.\n");
        } catch (\Throwable $e) {
            $this->stderr('warning: could not delete tunnel '.$id.': '.$e->getMessage());
        }

        return 0;
    }

    private function parseShareUpstreamValue(string $arg, string $prefix): string
    {
        $v = substr($arg, strlen($prefix));
        if (trim($v) === '') {
            $hint = $prefix === '--host='
                ? 'use --site= for your local hostname or IP (e.g. my-app.test)'
                : $prefix.' requires a hostname or IP';

            throw new \InvalidArgumentException($hint);
        }

        return $v;
    }

    private function assertTunnelServerLabel(string $label): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $label) !== 1) {
            throw new \InvalidArgumentException(
                'Invalid --server value "'.$label.'": use a tunnel id (letters/digits with optional . _ -), e.g. us-west-1.'
            );
        }
    }

    /**
     * @return array{0: int, 1: string|null} port and optional stderr hint when port was inferred
     */
    private function resolveSharePort(string $localHost, ?int $explicit): array
    {
        if ($explicit !== null) {
            if ($explicit < 1 || $explicit > 65535) {
                throw new \InvalidArgumentException('port must be between 1 and 65535');
            }

            return [$explicit, null];
        }

        foreach ([8000, 3000, 5173, 8080, 5000, 4000, 8765, 8888] as $p) {
            if ($this->tcpPortAcceptsConnections($localHost, $p)) {
                return [$p, 'Local port: '.$p.' (auto — first responding port among common dev servers).'];
            }
        }

        return [8000, 'Local port: 8000 (auto — no common dev port responded; pass a port if yours is different).'];
    }

    private function tcpPortAcceptsConnections(string $host, int $port, float $timeoutSeconds = 0.2): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if (\is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
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
    "subdomain": "optional-default-label", "custom_domain": "optional-hostname",
    "tunnel_server": "optional-edge-region-e.g-us-west-1" }

Values in the file override JETTY_* env for keys that are set. CLI flags override everything.

User config file (~/.config/jetty/config.json):
  jetty                      First-run: runs setup when no token is configured (same as onboard)
  jetty onboard [--region=eu]   First-run: browser login; auto-picks server (default Bridge https://usejetty.online)
  jetty setup                Change settings (menu; same as jetty config wizard)
  jetty config set server|api-url|token|subdomain|domain|tunnel-server <value>
  jetty config get [key]
  jetty config clear <key|all>
  jetty config wizard        Alias for jetty setup
  jetty logout               Remove saved API token only (same as: jetty config clear token)
  jetty reset                Clear all local user settings (~/.config/jetty/config.json + ~/.jetty.json)

Environment (optional fallback):
  JETTY_API_URL   Base URL for API calls (highest precedence)
  JETTY_REGION, --region=   Regional Bridge host: https://{region}.usejetty.online (default: https://usejetty.online)
  JETTY_BRIDGE_URL, JETTY_ONBOARD_BRIDGE_URL   Override Bridge root when JETTY_API_URL unset
  JETTY_ALLOW_LOCAL_BRIDGE=1   Allow localhost/127.0.0.1 in saved api_url and bootstrap candidates (self-hosted dev)
  JETTY_CLI_LOCAL_URL, JETTY_CLI_DEV_URL       Optional extra bootstrap URLs
  JETTY_CLI_BOOTSTRAP_FALLBACKS                Comma-separated extra Bridge roots to try
  JETTY_TOKEN     Personal access token from the dashboard
  JETTY_TUNNEL_SERVER   Default tunnel/edge id for jetty share (e.g. us-west-1)
  JETTY_LOCAL_PHAR_URL   If set (https?…), PHAR jetty update downloads from this URL every time (local Jetty app / dev); unset to use GitHub again

Global flags:
  --config=PATH   Use this JSON file (see above)
  --api-url=URL   Override api URL
  --token=TOKEN   Override token

Commands:
  jetty version [--machine] [--check-update]   --machine: print semver only (for scripts)
  jetty update [--check] [--force]   update this CLI: PHAR → latest GitHub release; Composer → composer update jetty/client (alias: self-update)
  jetty onboard              (see also: plain `jetty` when no token)
  jetty setup
  jetty logout
  jetty reset
  jetty list
  jetty delete <id>
  jetty config set|get|clear|wizard ...
  jetty share [port] [--server=us-west-1] [--site=127.0.0.1] [--subdomain=label] [--print-url-only] [--skip-edge]
    (alias: http)  --server= tunnel/edge id (letters, digits, . _ -); default from tunnel_server in config or JETTY_TUNNEL_SERVER
    --site= local upstream hostname or IP (default 127.0.0.1); aliases --bind=, --local=, --local-host=; --host= deprecated
    port optional: first open port among 8000,3000,5173,… or defaults to 8000

PHAR path: default repo GitHub shaferllc/jetty (cli-v* + jetty-php.phar). Override with JETTY_PHAR_RELEASES_REPO or JETTY_CLI_GITHUB_REPO=owner/repo for forks.
  With JETTY_LOCAL_PHAR_URL, update always re-fetches that URL (no semver skip). Same env as curl install from a local Jetty app.
Composer path: runs composer in the project that owns jetty/client (needs composer on PATH or COMPOSER_BINARY).
  update --check   PHAR: GitHub compare, or local URL info if JETTY_LOCAL_PHAR_URL; Composer: composer outdated jetty/client
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
