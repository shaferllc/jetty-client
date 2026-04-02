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
                'global-update' => $this->cmdGlobalUpdate($rest),
                'install-client' => $this->cmdInstallClient($rest),
                'list' => $this->cmdList($global),
                'delete' => $this->cmdDelete($global, $rest),
                'share', 'http' => $this->cmdShare($global, $rest),
                'onboard' => $this->cmdOnboard($global, $rest),
                'setup' => $this->cmdSetup($global, $rest),
                'logout' => $this->cmdLogout($rest),
                'reset' => $this->cmdReset($rest),
                'config' => $this->cmdConfig($global, $rest),
                'help', '--help', '-h' => $this->cmdHelp($rest),
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

        $wantInstall = in_array('--install', $args, true);
        $checkUpdate = in_array('--check-update', $args, true);

        $this->stdout('jetty '.ApiClient::VERSION);

        if ($wantInstall) {
            $this->stdout('');
            $this->stdout($this->versionInstallDetails());
        }

        if ($checkUpdate) {
            if ($wantInstall) {
                $this->stdout('');
            }
            $pharPath = \Phar::running(false);
            if ($pharPath !== '') {
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
            } elseif (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('jetty/client')) {
                return $this->updateComposerJettyClient(['--check']);
            } else {
                $this->stderr('--check-update applies to PHAR installs (GitHub) or Composer installs (Packagist).');
            }
        }

        return 0;
    }

    private function versionInstallDetails(): string
    {
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            $lines = [
                'Install: PHAR',
                '  Path:    '.$pharPath,
                '  Update:  jetty update   (re-downloads from GitHub cli-v* for '.$this->releasesRepo().')',
                '',
                'Config is shared: ~/.config/jetty/config.json applies no matter how you installed the binary.',
            ];
            $multi = $this->detectMultipleJettyBinariesOnPath();
            if ($multi !== null) {
                $lines[] = '';
                $lines[] = 'Multiple `jetty` executables on PATH — you only maintain the one you actually run:';
                foreach ($multi as $p) {
                    $lines[] = '  '.$p;
                }
            }

            return implode("\n", $lines);
        }

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('jetty/client')) {
            try {
                $root = self::composerProjectRootForJettyClient();
            } catch (\Throwable) {
                return 'Install: Composer (jetty/client) — could not resolve project root.';
            }
            $rootPkg = InstalledVersions::getRootPackage()['name'] ?? '';
            if ($rootPkg === 'jetty/client') {
                $kind = 'Composer (this repo / jetty-client dev checkout)';
            } elseif ($this->isComposerGlobalProjectRoot($root)) {
                $kind = 'Composer global (~/.composer or ~/.config/composer)';
            } else {
                $kind = 'Composer project dependency';
            }

            $lines = [
                'Install: '.$kind,
                '  Project root: '.$root,
                '  Update:       jetty update   (runs composer update jetty/client in that directory)',
                '',
                'PHAR releases and Packagist use the same version from one GitHub “Release CLI” workflow —',
                'you do not bump them separately. Pick one binary (PHAR or Composer) for daily use;',
                '`jetty update` only upgrades the install that is running.',
            ];
            $multi = $this->detectMultipleJettyBinariesOnPath();
            if ($multi !== null) {
                $lines[] = '';
                $lines[] = 'Multiple `jetty` executables on PATH:';
                foreach ($multi as $p) {
                    $lines[] = '  '.$p;
                }
            }

            return implode("\n", $lines);
        }

        return 'Install: unknown (not a PHAR and jetty/client not in Composer metadata).';
    }

    private function isComposerGlobalProjectRoot(string $root): bool
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (! is_string($home) || $home === '') {
            return false;
        }
        $norm = str_replace('\\', '/', $root);
        $h = str_replace('\\', '/', $home);

        return str_contains($norm, $h.'/.composer/')
            || str_contains($norm, $h.'/.config/composer/');
    }

    /**
     * @return list<string>|null
     */
    private function detectMultipleJettyBinariesOnPath(): ?array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @exec('where.exe jetty 2>NUL', $out, $code);
        } else {
            $out = [];
            @exec('which -a jetty 2>/dev/null', $out, $code);
        }
        if ($code !== 0 || $out === []) {
            return null;
        }
        $paths = array_values(array_unique(array_map('trim', $out)));
        $paths = array_values(array_filter($paths, fn (string $p) => $p !== ''));

        return count($paths) > 1 ? $paths : null;
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
     * Update Composer global and/or a global PHAR path (not the current project’s vendor install).
     *
     * @param  list<string>  $args
     */
    private function cmdGlobalUpdate(array $args): int
    {
        $onlyComposer = false;
        $onlyPhar = false;
        $pass = [];
        foreach ($args as $a) {
            if ($a === '--composer') {
                $onlyComposer = true;

                continue;
            }
            if ($a === '--phar') {
                $onlyPhar = true;

                continue;
            }
            if (in_array($a, ['--check', '--force'], true)) {
                $pass[] = $a;

                continue;
            }
            throw new \InvalidArgumentException(
                'Usage: jetty global-update [--composer] [--phar] [--check] [--force]'."\n"
                .'  Default: update Composer global jetty/client (if installed) and/or PHAR at JETTY_PHAR_PATH or ~/.local/bin/jetty (if present).'."\n"
                .'  --composer  Only run composer global update jetty/client'."\n"
                .'  --phar      Only update the global PHAR file'."\n"
                .'  --check     Show outdated / dry-run (same as jetty update --check)'."\n"
                .'  --force     Pass --no-cache to Composer; re-download PHAR even if semver matches'
            );
        }

        if ($onlyComposer && $onlyPhar) {
            throw new \InvalidArgumentException('Use only one of --composer or --phar, or omit both to update every global target that exists.');
        }

        $wantComposer = ! $onlyPhar;
        $wantPhar = ! $onlyComposer;

        $hasComposer = $this->isGlobalComposerJettyInstalled();
        $pharPath = $this->resolveGlobalPharPathForUpdate();

        if ($wantComposer && ! $hasComposer) {
            if ($onlyComposer) {
                throw new \RuntimeException(
                    'jetty/client is not installed globally. Run: composer global require jetty/client'
                );
            }
            $this->stdout('Skipping Composer global: jetty/client is not installed (composer global show jetty/client).');
        }

        if ($wantPhar && $pharPath === null) {
            if ($onlyPhar) {
                throw new \RuntimeException(
                    'No global PHAR found. Set JETTY_PHAR_PATH=/path/to/jetty.phar or install to ~/.local/bin/jetty'
                );
            }
            $this->stdout('Skipping PHAR: no file at JETTY_PHAR_PATH or ~/.local/bin/jetty.');
        }

        $didSomething = false;
        $exit = 0;

        if ($wantComposer && $hasComposer) {
            $r = $this->updateComposerGlobalJettyClient($pass);
            if ($r !== 0) {
                $exit = $r;
            }
            $didSomething = true;
        }

        if ($wantPhar && $pharPath !== null) {
            if ($didSomething) {
                $this->stdout('');
            }
            $this->stdout('Global PHAR target: '.$pharPath);
            $this->updatePharInPlace($pass, $pharPath);
            $didSomething = true;
        }

        if (! $didSomething) {
            throw new \RuntimeException(
                'Nothing to update: install Composer global jetty/client and/or a PHAR at ~/.local/bin/jetty (see jetty help).'
            );
        }

        return $exit;
    }

    /**
     * @param  list<string>  $args  --check, --force
     */
    private function updateComposerGlobalJettyClient(array $args): int
    {
        $composer = self::resolveComposerBinary();
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (! is_string($home) || $home === '') {
            throw new \RuntimeException('Cannot resolve home directory for composer global.');
        }

        $checkOnly = in_array('--check', $args, true);
        $force = in_array('--force', $args, true);

        if ($checkOnly) {
            $this->stdout('Install: Composer global');
            $code = self::runComposerInDirectory($home, $composer, ['global', 'show', 'jetty/client', '--no-ansi']);
            if ($code !== 0) {
                $this->stdout('jetty/client is not installed globally. Run: composer global require jetty/client');

                return 1;
            }
            self::runComposerInDirectory($home, $composer, ['global', 'outdated', 'jetty/client', '--direct', '--no-ansi']);

            return 0;
        }

        $cmd = ['global', 'update', 'jetty/client', '--no-interaction'];
        if ($force) {
            $cmd[] = '--no-cache';
        }

        $code = self::runComposerInDirectory($home, $composer, $cmd);
        if ($code !== 0) {
            throw new \RuntimeException(
                'composer '.implode(' ', $cmd).' failed (exit '.$code.'). Try: composer global update jetty/client'
            );
        }

        $this->stdout('Updated jetty/client via Composer global. Run jetty version to confirm (use the global vendor/bin/jetty on PATH).');
        $this->emitPostUpdateConfigTip();

        return 0;
    }

    private function isGlobalComposerJettyInstalled(): bool
    {
        try {
            $composer = self::resolveComposerBinary();
        } catch (\Throwable) {
            return false;
        }
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (! is_string($home) || $home === '') {
            return false;
        }

        return self::runComposerInDirectory($home, $composer, ['global', 'show', 'jetty/client', '--no-ansi']) === 0;
    }

    private function resolveGlobalPharPathForUpdate(): ?string
    {
        $env = getenv('JETTY_PHAR_PATH');
        if (is_string($env) && $env !== '' && is_file($env)) {
            return $env;
        }
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (is_string($home) && $home !== '') {
            $p = $home.\DIRECTORY_SEPARATOR.'.local'.\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'jetty';
            if (is_file($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Add jetty/client to the Composer project in the current working directory (e.g. from a global `jetty` install).
     *
     * @param  list<string>  $args
     */
    private function cmdInstallClient(array $args): int
    {
        if ($args !== []) {
            throw new \InvalidArgumentException('Usage: jetty install-client');
        }
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Could not determine the current working directory.');
        }
        $composerJson = $cwd.\DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($composerJson)) {
            throw new \RuntimeException(
                'No composer.json in '.$cwd.'. `cd` to your app root first, or run: composer require jetty/client'
            );
        }

        $composer = self::resolveComposerBinary();
        $code = self::runComposerInDirectory($cwd, $composer, ['require', 'jetty/client', '--no-interaction']);
        if ($code !== 0) {
            throw new \RuntimeException(
                'composer require jetty/client failed (exit '.$code.'). Run it manually from: '.$cwd
            );
        }

        $bin = $cwd.\DIRECTORY_SEPARATOR.'vendor'.\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'jetty';
        $hint = is_file($bin)
            ? 'Project binary: '.$bin
            : 'Use '.$cwd.\DIRECTORY_SEPARATOR.'vendor'.\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'jetty (add vendor/bin to PATH).';

        $this->stdout('Installed jetty/client into '.$cwd.'. '.$hint);

        return 0;
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

    /**
     * @param  list<string>  $rest
     */
    private function cmdHelp(array $rest): int
    {
        $advanced = in_array('--advanced', $rest, true) || in_array('-a', $rest, true);
        $unknown = array_values(array_filter($rest, fn (string $x) => ! in_array($x, ['--advanced', '-a'], true)));
        if ($unknown !== []) {
            throw new \InvalidArgumentException("Usage: jetty help [--advanced|-a]\n".$this->helpText());
        }
        $this->stdout($this->helpText());
        if ($advanced) {
            $this->stdout($this->helpTextAdvanced());
        } else {
            $this->stdout("\nAdvanced (config file & environment variables): jetty help --advanced\n");
        }

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
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->stdout($this->shareUsageHelp());

            return 0;
        }

        $localHost = '127.0.0.1';
        $upstreamExplicit = false;
        $tunnelServerFlag = null;
        $printUrlOnly = false;
        $skipEdge = false;
        $subdomain = null;
        $shareVerbose = false;
        $noDetect = false;
        $serveDocroot = null;

        $positional = [];
        foreach ($args as $arg) {
            if ($arg === '--verbose' || $arg === '-v' || $arg === '--errors') {
                $shareVerbose = true;

                continue;
            }
            if ($arg === '--no-detect') {
                $noDetect = true;

                continue;
            }
            if ($arg === '--serve') {
                $serveDocroot = $this->shareDefaultServeDocroot();

                continue;
            }
            if (str_starts_with($arg, '--serve=')) {
                $p = substr($arg, strlen('--serve='));
                $serveDocroot = $p === '' ? $this->shareDefaultServeDocroot() : $this->shareResolveServeDocroot($p);

                continue;
            }
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
                $upstreamExplicit = true;

                continue;
            }
            if (str_starts_with($arg, '--bind=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--bind=');
                $upstreamExplicit = true;

                continue;
            }
            if (str_starts_with($arg, '--local=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--local=');
                $upstreamExplicit = true;

                continue;
            }
            if (str_starts_with($arg, '--local-host=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--local-host=');
                $upstreamExplicit = true;

                continue;
            }
            if (str_starts_with($arg, '--host=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--host=');
                $upstreamExplicit = true;

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
            if (str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException('Unknown option: '.$arg."\n".$this->shareUsageSummary());
            }
            $positional[] = $arg;
        }

        if (count($positional) > 1) {
            throw new \InvalidArgumentException("Too many arguments.\n".$this->shareUsageHelp());
        }

        $explicitPort = null;
        if ($positional !== []) {
            $rawPort = $positional[0];
            if (! is_numeric($rawPort) || (string) (int) $rawPort !== (string) $rawPort) {
                throw new \InvalidArgumentException(
                    'Invalid port: expected 1–65535, or omit port to auto-detect a local dev server.'."\n".$this->shareUsageHelp()
                );
            }
            $explicitPort = (int) $rawPort;
        }

        if ($printUrlOnly && $serveDocroot !== null) {
            throw new \InvalidArgumentException('--serve cannot be combined with --print-url-only.');
        }

        $builtInServerProc = null;
        $pendingServe = null;
        $port = 8000;
        $portHint = null;

        if ($serveDocroot !== null) {
            $listenPort = $explicitPort ?? $this->shareFindFreeTcpPort();
            if ($listenPort < 1 || $listenPort > 65535) {
                throw new \InvalidArgumentException('Invalid port for --serve.');
            }
            $pendingServe = ['docroot' => $serveDocroot, 'port' => $listenPort];
            $localHost = '127.0.0.1';
            $port = $listenPort;
            $portHint = 'PHP built-in server → http://127.0.0.1:'.$listenPort.' (root: '.$serveDocroot.')';
            $upstreamExplicit = true;
        } else {
            $detected = null;
            if (! $noDetect && ! $upstreamExplicit && $explicitPort === null && getenv('JETTY_SHARE_NO_DETECT') !== '1') {
                $cwd = getcwd();
                if ($cwd !== false) {
                    $detected = LocalDevDetector::detect($cwd);
                }
            }
            if ($detected !== null) {
                $localHost = $detected['host'];
                $port = $detected['port'];
                $portHint = $detected['hint'];
            } else {
                [$port, $portHint] = $this->resolveSharePort($localHost, $explicitPort);
            }
        }

        $cfg = $this->resolvedConfig($global);
        $cfg->validate();
        $client = new ApiClient($cfg->apiUrl, $cfg->token);

        if ($pendingServe !== null) {
            try {
                $builtInServerProc = $this->shareStartPhpBuiltInServer($pendingServe['docroot'], $pendingServe['port']);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to start PHP built-in server: '.$e->getMessage());
            }
        }
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

        if ($shareVerbose) {
            $this->shareVerboseLog(true, 'API base: '.$client->apiBaseUrl());
            $this->shareVerboseLog(true, 'share target: '.$localHost.':'.$port.'; subdomain='.($subdomain ?? '').'; tunnel_server='.($tunnelServer ?? 'null'));
        }

        if ($portHint !== null && ! $printUrlOnly) {
            $this->stderr($portHint);
        }

        if ($shareVerbose) {
            $this->shareVerboseLog(true, 'POST /api/tunnels body: '.json_encode([
                'local_host' => $localHost,
                'local_port' => $port,
                'subdomain' => $subdomain,
                'server' => $tunnelServer,
            ], JSON_THROW_ON_ERROR));
        }

        try {
            $data = $client->createTunnel($localHost, $port, $subdomain, $tunnelServer);

            if ($shareVerbose) {
                $this->shareVerboseLog(true, 'createTunnel response (redacted): '.json_encode($this->shareRedactTunnelResponseForLog($data), JSON_THROW_ON_ERROR));
            }

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
                if ($shareVerbose) {
                    $this->shareVerboseLog(true, 'initial heartbeat OK for tunnel id '.$id);
                }
            } catch (\Throwable $e) {
                $this->stderr('warning: initial heartbeat: '.$e->getMessage());
                if ($shareVerbose) {
                    $this->shareVerboseLog(true, 'initial heartbeat exception: '.$e->getMessage());
                }
            }

            $edgeOutcome = null;
            if (! $printUrlOnly && ! $skipEdge && $ws !== '' && $agentToken !== '') {
                try {
                    $edgeOutcome = EdgeAgent::run(
                        $ws,
                        $id,
                        $agentToken,
                        $localHost,
                        $port,
                        $client,
                        $id,
                        fn (string $m) => $this->stderr($m),
                        $shareVerbose,
                    );
                } catch (\Throwable $e) {
                    $this->stderr('edge agent failed: '.$e->getMessage());
                    if ($shareVerbose) {
                        $this->stderr('[jetty:share] '.get_class($e).' in '.$e->getFile().':'.$e->getLine());
                        $this->stderr('[jetty:share] '.$e->getTraceAsString());
                    }
                    $this->stderr('Continuing with heartbeats only (no HTTP forwarding until you fix edge connectivity).');
                    $edgeOutcome = EdgeAgentResult::FailedEarly;
                }
            } elseif (! $printUrlOnly && ! $skipEdge && $ws === '') {
                $this->stderr('Note: Bridge returned no edge WebSocket URL (JETTY_EDGE_WS_URL). Heartbeats only.');
            } elseif (! $printUrlOnly && ! $skipEdge && $agentToken === '') {
                $this->stderr('Note: no agent_token in API response — heartbeats only.');
            }

            $needsHeartbeatLoop = true;
            if ($edgeOutcome === EdgeAgentResult::Finished) {
                $needsHeartbeatLoop = false;
                if ($shareVerbose) {
                    $this->shareVerboseLog(true, 'edge agent finished; skipping heartbeat fallback');
                }
            } elseif ($edgeOutcome === EdgeAgentResult::FailedEarly) {
                $this->stderr('');
                $this->stderr('Edge WebSocket agent did not stay up; falling back to heartbeats only (tunnel stays registered).');
                $this->stderr('Fix edge connectivity, or use --skip-edge for registration + heartbeats without the agent. Ctrl+C to exit and delete the tunnel.');
                $needsHeartbeatLoop = true;
            }

            if ($needsHeartbeatLoop) {
                $this->runShareHeartbeatLoop($client, $id, $shareVerbose);
            }

            try {
                if ($shareVerbose) {
                    $this->shareVerboseLog(true, 'DELETE /api/tunnels/'.$id);
                }
                $client->deleteTunnel($id);
                $this->stderr("Tunnel {$id} deleted.\n");
            } catch (\Throwable $e) {
                $this->stderr('warning: could not delete tunnel '.$id.': '.$e->getMessage());
                if ($shareVerbose) {
                    $this->stderr('[jetty:share] delete exception: '.$e->getTraceAsString());
                }
            }

            return 0;
        } finally {
            $this->shareStopBuiltInServer($builtInServerProc);
        }
    }

    private function shareDefaultServeDocroot(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException('Could not determine working directory for --serve.');
        }
        $pub = $cwd.\DIRECTORY_SEPARATOR.'public';
        if (is_dir($pub)) {
            return $pub;
        }

        return $cwd;
    }

    private function shareResolveServeDocroot(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->shareDefaultServeDocroot();
        }
        $real = realpath($path);

        return ($real !== false && is_dir($real)) ? $real : throw new \InvalidArgumentException('Not a directory: '.$path);
    }

    private function shareFindFreeTcpPort(): int
    {
        $s = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($s === false) {
            return 8899;
        }
        $name = stream_socket_get_name($s, false);
        fclose($s);
        if (is_string($name) && preg_match('/:(\d+)$/', $name, $m)) {
            return (int) $m[1];
        }

        return 8899;
    }

    /**
     * @return resource|object
     */
    private function shareStartPhpBuiltInServer(string $docroot, int $port)
    {
        $docroot = realpath($docroot) ?: $docroot;
        if (! is_dir($docroot)) {
            throw new \InvalidArgumentException('Document root is not a directory: '.$docroot);
        }

        $php = \PHP_BINARY;
        $cmd = [$php, '-S', '127.0.0.1:'.$port, '-t', $docroot];
        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = @proc_open(
            $cmd,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => \STDERR,
            ],
            $pipes,
            $docroot
        );
        if (! is_resource($proc)) {
            throw new \RuntimeException('proc_open failed for PHP built-in server');
        }
        usleep(200_000);
        if (! $this->tcpPortAcceptsConnections('127.0.0.1', $port)) {
            proc_close($proc);
            throw new \RuntimeException('PHP built-in server did not listen on 127.0.0.1:'.$port);
        }

        return $proc;
    }

    /**
     * @param  resource|object|null  $proc
     */
    private function shareStopBuiltInServer(mixed $proc): void
    {
        if ($proc === null) {
            return;
        }
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
            $this->stderr('Stopped PHP built-in server.');
        }
    }

    private function shareVerboseLog(bool $verbose, string $message): void
    {
        if ($verbose) {
            $this->stderr('[jetty:share] '.$message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function shareRedactTunnelResponseForLog(array $data): array
    {
        $out = $data;
        if (isset($out['agent_token']) && is_string($out['agent_token'])) {
            $out['agent_token'] = '(length '.strlen($out['agent_token']).')';
        }

        return $out;
    }

    private function runShareHeartbeatLoop(ApiClient $client, int $tunnelId, bool $verbose): void
    {
        $this->stderr("\nSending heartbeats every 25s. Ctrl+C to delete this tunnel and exit.\n");
        if ($verbose) {
            $this->shareVerboseLog(true, 'heartbeat loop start (tunnel id '.$tunnelId.')');
        }

        $stop = false;
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            $handler = function () use (&$stop, $verbose): void {
                $stop = true;
                if ($verbose) {
                    fwrite(\STDERR, "[jetty:share] caught signal; stopping heartbeat loop\n");
                }
            };
            \pcntl_signal(\SIGINT, $handler);
            \pcntl_signal(\SIGTERM, $handler);
        } else {
            $this->stderr("\n(ext-pcntl not loaded — Ctrl+C handling may vary by platform.)\n");
        }

        $nextBeat = time() + 25;
        $beatNum = 0;
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
                    $client->heartbeat($tunnelId);
                    $beatNum++;
                    if ($verbose) {
                        $this->shareVerboseLog(true, 'heartbeat #'.$beatNum.' OK');
                    }
                } catch (\Throwable $e) {
                    $this->stderr('heartbeat failed: '.$e->getMessage());
                    if ($verbose) {
                        $this->shareVerboseLog(true, 'heartbeat exception: '.get_class($e).' '.$e->getMessage());
                    }
                }
                $nextBeat = $now + 25;
            }
            usleep(200_000);
        }
        if ($verbose) {
            $this->shareVerboseLog(true, 'heartbeat loop end');
        }
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

    private function shareUsageSummary(): string
    {
        return 'Usage: jetty share [port] [--host=127.0.0.1] [--server=us-west-1] [--site=HOST] [--subdomain=label] [--print-url-only] [--skip-edge] [--serve[=DIR]] [--no-detect] [--verbose|-v|--errors] (alias: http)';
    }

    private function shareUsageHelp(): string
    {
        return $this->shareUsageSummary().<<<'TXT'


  port  Optional. If omitted: auto-detect upstream from cwd (Laravel APP_URL, Herd/Valet links, DDEV, Docker Compose, Vite/Next/etc., or .env PORT), else scan common dev ports on 127.0.0.1.
  --host= / --site= / --bind= / --local= / --local-host=  Upstream hostname or IP (default 127.0.0.1).
  --serve[=DIR]  Start PHP’s built-in server (default docroot: ./public if present, else cwd) and tunnel to it; optional port as first arg.
  --no-detect    Skip local-dev auto-detection (use plain 127.0.0.1 + port scan). Env: JETTY_SHARE_NO_DETECT=1
  --skip-edge  Register + heartbeats only; no WebSocket forwarding agent.
  --verbose / -v / --errors  Log connection steps, heartbeats, and edge WebSocket frames (stderr).

TXT;
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

        return 'tunnel.usejetty.online';
    }

    private function helpText(): string
    {
        return <<<'TXT'
Jetty PHP client (Composer package jetty/client) — tunnel API helper.

Common commands:
  jetty                      First-run: runs setup when no token is configured (same as onboard)
  jetty onboard [--region=eu]   First-run: browser login; auto-picks server (default Bridge https://usejetty.online)
  jetty setup                Change settings (menu; same as jetty config wizard)
  jetty config set server|api-url|token|subdomain|domain|tunnel-server <value>
  jetty config get [key]
  jetty config clear <key|all>
  jetty config wizard        Alias for jetty setup
  jetty logout               Remove saved API token only (same as: jetty config clear token)
  jetty reset                Clear all local user settings (~/.config/jetty/config.json + ~/.jetty.json)

Global flags:
  --config=PATH   Use a JSON config file (paths & schema: jetty help --advanced)
  --api-url=URL   Override API base URL
  --token=TOKEN   Override token
  --region=CODE   Regional Bridge (https://{region}.usejetty.online); default https://usejetty.online

Commands:
  jetty version [--machine] [--install] [--check-update]
    --machine: semver only (scripts)  --install: how this binary was installed + update hint
    --check-update: PHAR→GitHub; Composer→Packagist (same as jetty update --check)
  jetty update [--check] [--force]   Updates this binary’s install (PHAR file or project/global Composer)
  jetty global-update [--composer|--phar] [--check] [--force]
    Update Composer global jetty/client and/or PHAR at JETTY_PHAR_PATH or ~/.local/bin/jetty (default: both if present)
  jetty install-client       composer require jetty/client in the current project (useful with a global jetty on PATH)
  jetty onboard              (see also: plain `jetty` when no token)
  jetty setup
  jetty help [--advanced|-a]   This help; --advanced lists config file & environment variables
  jetty logout
  jetty reset
  jetty list
  jetty delete <id>
  jetty config set|get|clear|wizard ...
  jetty share [port] [--host=127.0.0.1] [--server=us-west-1] [--site=HOST] [--subdomain=label] [--print-url-only] [--skip-edge] [--serve[=DIR]] [--no-detect] [--verbose|-v|--errors]
    (alias: http)  Auto-detect local dev upstream from cwd (see jetty help --advanced), or --serve for a static PHP server
    --server= tunnel/edge id; default from config.  --site= / --host= upstream host (default 127.0.0.1)

Install: composer require jetty/client  (or: composer global require jetty/client — put Composer’s global vendor/bin on PATH)
  Same config (~/.config/jetty/config.json) for PHAR and Composer. Releases: one “Release CLI” workflow ships the PHAR on GitHub and the same version to Packagist — bump once, not twice.
  Day to day: pick one binary (PHAR or Composer); jetty update only upgrades the copy you run (see jetty version --install).
  jetty global-update updates Composer global jetty/client and/or a PHAR at ~/.local/bin/jetty (or JETTY_PHAR_PATH) — from a project vendor/bin/jetty too.

TXT;
    }

    private function helpTextAdvanced(): string
    {
        return <<<'TXT'

── Config file (JSON, recommended) ──
  First file wins:
    --config=PATH, JETTY_CONFIG, ~/.config/jetty/config.json, ~/.jetty.json, ./jetty.config.json

  {
    "api_url": "https://your-jetty.example",
    "token": "your-personal-access-token",
    "subdomain": "optional-default-label",
    "custom_domain": "optional-hostname",
    "tunnel_server": "optional-edge-region-e.g-us-west-1"
  }

  Values in the file override JETTY_* env for keys that are set. CLI flags override everything.

── Advanced: environment variables (optional) ──
  JETTY_API_URL              Base URL for API calls (highest precedence)
  JETTY_REGION               Same as --region= (regional Bridge host)
  JETTY_BRIDGE_URL           Override Bridge root when JETTY_API_URL unset
  JETTY_ONBOARD_BRIDGE_URL   Same, for onboarding only
  JETTY_ALLOW_LOCAL_BRIDGE=1 Allow localhost/127.0.0.1 in saved api_url and bootstrap candidates (self-hosted dev)
  JETTY_CLI_LOCAL_URL        Optional extra bootstrap URL
  JETTY_CLI_DEV_URL          Optional extra bootstrap URL
  JETTY_CLI_BOOTSTRAP_FALLBACKS   Comma-separated extra Bridge roots to try
  JETTY_TOKEN                Personal access token from the dashboard
  JETTY_TUNNEL_SERVER        Default tunnel/edge id for jetty share (e.g. us-west-1)
  JETTY_SHARE_NO_DETECT=1    jetty share: skip local-dev auto upstream detection
  JETTY_SHARE_UPSTREAM=URL   jetty share: force upstream (e.g. http://127.0.0.1:8080) when tools like PHP Monitor don’t write project files

  jetty share detection (port omitted, detection on): tries in order — JETTY_SHARE_UPSTREAM; Laravel APP_URL;
    Bedrock APP_URL; herd/valet links; DDEV; Lando; Symfony .symfony.local.yaml; Laravel Sail + Docker Compose
    published ports; wp-env; Craft nitro.yaml; Vite / Nuxt / Astro / SvelteKit; Next / Remix / Gatsby;
    devcontainer forwardPorts; Caddyfile; .env PORT / APP_PORT / VITE_PORT / FORWARD_HTTP_PORT; package.json
    heuristics (Vite, Next, Strapi, Directus, …); MAMP htdocs path; PhpStorm .idea/php.xml. Then 127.0.0.1 port scan.
  JETTY_LOCAL_PHAR_URL       If set (https?…), PHAR jetty update downloads from this URL every time; unset for GitHub
  JETTY_PHAR_PATH            Explicit path to a global PHAR for jetty global-update --phar (default: ~/.local/bin/jetty)
  JETTY_PHAR_RELEASES_REPO   Override GitHub repo for PHAR releases
  JETTY_CLI_GITHUB_REPO      owner/repo or URL for PHAR / installer resolution
  JETTY_PHAR_GITHUB_TOKEN    Private repos / rate limits

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
