<?php

declare(strict_types=1);

namespace JettyCli;

use Composer\InstalledVersions;

final class Application
{
    private ?CliUi $cliUi = null;

    private ?ShareTrafficView $trafficView = null;

    private function ui(): CliUi
    {
        return $this->cliUi ??= CliUi::forStdio();
    }

    public function run(array $argv): int
    {
        array_shift($argv);

        try {
            [$global, $rest] = $this->parseGlobalFlags($argv);

            if ($rest === []) {
                $cfg = $this->resolvedConfig($global);
                if (trim($cfg->token) === '') {
                    try {
                        SetupWizard::runOnboarding(
                            $global['config'],
                            $global['region'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        $this->ui()->errorLine($e->getMessage());

                        return 1;
                    }

                    return 0;
                }
                $this->printStyledMainHelp();
                $this->ui()->mutedLine(
                    'Tip: run `jetty help` for the full command reference, or `jetty help --advanced` for env vars.',
                );

                return 1;
            }

            $command = array_shift($rest);

            $code = match ($command) {
                'version', '--version', '-V' => $this->cmdVersion($rest),
                'update',
                'self-update',
                'global-update' => $this->cmdSelfUpdate($rest),
                'install-client' => $this->cmdInstallClient($rest),
                'list' => $this->cmdList($global, $rest),
                'replay' => $this->cmdReplay($global, $rest),
                'domains' => $this->cmdDomains($global, $rest),
                'delete' => $this->cmdDelete($global, $rest),
                'share', 'http' => $this->cmdShare($global, $rest),
                'login' => $this->cmdLogin($global, $rest),
                'onboard' => $this->cmdOnboard($global, $rest),
                'setup' => $this->cmdSetup($global, $rest),
                'logout' => $this->cmdLogout($rest),
                'reset' => $this->cmdReset($rest),
                'config' => $this->cmdConfig($global, $rest),
                'doctor' => $this->cmdDoctor(),
                'help', '--help', '-h' => $this->cmdHelp($rest),
                default => throw new \InvalidArgumentException(
                    'Unknown command: '.
                        $command.
                        $this->unknownCommandUpgradeHint($command).
                        "\n".
                        $this->helpText(),
                ),
            };
            $this->maybePrintUpdateNotice($command, $code);

            return $code;
        } catch (\Throwable $e) {
            $this->ui()->errorLine($e->getMessage());

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
                    throw new \InvalidArgumentException(
                        '--config= requires a path',
                    );
                }

                continue;
            }
            if ($arg === '--config') {
                $config =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--config requires a path',
                    );
                if ($config === '' || str_starts_with($config, '--')) {
                    throw new \InvalidArgumentException(
                        '--config requires a path',
                    );
                }

                continue;
            }
            if (str_starts_with($arg, '--api-url=')) {
                $apiUrl = substr($arg, strlen('--api-url='));

                continue;
            }
            if ($arg === '--api-url') {
                $apiUrl =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--api-url requires a value',
                    );
                if ($apiUrl === '' || str_starts_with($apiUrl, '--')) {
                    throw new \InvalidArgumentException(
                        '--api-url requires a value',
                    );
                }

                continue;
            }
            if (str_starts_with($arg, '--token=')) {
                $token = substr($arg, strlen('--token='));

                continue;
            }
            if ($arg === '--token') {
                $token =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--token requires a value',
                    );
                if ($token === '' || str_starts_with($token, '--')) {
                    throw new \InvalidArgumentException(
                        '--token requires a value',
                    );
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
                $r =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--region requires a value',
                    );
                if ($r === '' || str_starts_with($r, '--')) {
                    throw new \InvalidArgumentException(
                        '--region requires a value',
                    );
                }
                DefaultBridge::normalizeRegion($r);
                $region = $r;

                continue;
            }
            $rest[] = $arg;
        }

        $region =
            is_string($region) && trim($region) !== '' ? trim($region) : null;

        return [
            [
                'api-url' => $apiUrl,
                'token' => $token,
                'config' => $config,
                'region' => $region,
            ],
            $rest,
        ];
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     */
    private function resolvedConfig(array $global): Config
    {
        return Config::resolve(
            $global['config'],
            $global['region'] ?? null,
        )->merge($global['api-url'], $global['token']);
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

        $u = $this->ui();
        $u->banner('v'.ApiClient::VERSION);
        $u->out($u->bold('jetty/client').' '.$u->cyan(ApiClient::VERSION));

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
                    $remoteSemver = GitHubPharRelease::tagToSemver(
                        $latest['tag_name'],
                    );
                    $cmp = version_compare($remoteSemver, ApiClient::VERSION);
                    if ($cmp > 0) {
                        $this->ui()->warnLine(
                            'Update available: '.
                                $latest['tag_name'].
                                ' (you have '.
                                ApiClient::VERSION.
                                ') — run: '.
                                $this->ui()->cmd('jetty update'),
                        );
                    } else {
                        $this->ui()->successLine(
                            'Up to date with latest release '.
                                $latest['tag_name'].
                                '.',
                        );
                    }
                }
            } elseif (
                class_exists(InstalledVersions::class) &&
                InstalledVersions::isInstalled('jetty/client')
            ) {
                return $this->updateComposerJettyClient(['--check']);
            } else {
                $this->ui()->warnLine(
                    '--check-update applies to PHAR installs (GitHub) or Composer installs (Packagist).',
                );
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
                '  Update:  jetty update   (re-downloads from GitHub cli-v* for '.
                $this->releasesRepo().
                ')',
                '',
                'Config is shared: ~/.config/jetty/config.json applies no matter how you installed the binary.',
            ];
            $multi = $this->detectMultipleJettyBinariesOnPath();
            if ($multi !== null) {
                $lines[] = '';
                $lines[] =
                    'Multiple `jetty` executables on PATH — you only maintain the one you actually run:';
                foreach ($multi as $p) {
                    $lines[] = '  '.$p;
                }
            }

            return implode("\n", $lines);
        }

        if (
            class_exists(InstalledVersions::class) &&
            InstalledVersions::isInstalled('jetty/client')
        ) {
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

        return str_contains($norm, $h.'/.composer/') ||
            str_contains($norm, $h.'/.config/composer/');
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
            throw new \RuntimeException(
                'Could not determine the current working directory.',
            );
        }
        $composerJson = $cwd.\DIRECTORY_SEPARATOR.'composer.json';
        if (! is_file($composerJson)) {
            throw new \RuntimeException(
                'No composer.json in '.
                    $cwd.
                    '. `cd` to your app root first, or run: composer require jetty/client',
            );
        }

        $composer = self::resolveComposerBinary();
        $code = self::runComposerInDirectory($cwd, $composer, [
            'require',
            'jetty/client',
            '--no-interaction',
        ]);
        if ($code !== 0) {
            throw new \RuntimeException(
                'composer require jetty/client failed (exit '.
                    $code.
                    '). Run it manually from: '.
                    $cwd,
            );
        }

        $bin =
            $cwd.
            \DIRECTORY_SEPARATOR.
            'vendor'.
            \DIRECTORY_SEPARATOR.
            'bin'.
            \DIRECTORY_SEPARATOR.
            'jetty';
        $hint = is_file($bin)
            ? 'Project binary: '.$bin
            : 'Use '.
                $cwd.
                \DIRECTORY_SEPARATOR.
                'vendor'.
                \DIRECTORY_SEPARATOR.
                'bin'.
                \DIRECTORY_SEPARATOR.
                'jetty (add vendor/bin to PATH).';

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
                $this->stdout(
                    'Install: PHAR (local dev — JETTY_LOCAL_PHAR_URL)',
                );
                $this->stdout('URL: '.$localUrl);
                $this->stdout('Current: '.ApiClient::VERSION);
                $this->stdout(
                    'jetty update re-downloads from this URL every time (ignores GitHub semver). Unset JETTY_LOCAL_PHAR_URL to use GitHub releases again.',
                );

                return 0;
            }

            if (! $this->applyPharDownload($pharPath, $localUrl, null)) {
                $this->stdout(
                    'You\'re already on the latest build from JETTY_LOCAL_PHAR_URL (downloaded file matches your current PHAR).',
                );

                return 0;
            }
            $this->stderr(
                'Updated PHAR from JETTY_LOCAL_PHAR_URL. Run jetty version to confirm.',
            );
            $this->emitPostUpdateConfigTip();

            return 0;
        }

        $repo = $this->releasesRepo();
        $token = $this->githubTokenForReleases();
        $latest = GitHubPharRelease::latest($repo, $token);
        if ($latest === null) {
            throw new \RuntimeException(
                'Could not find a release with jetty-php.phar on '.
                    $repo.
                    '.',
            );
        }

        $remoteSemver = GitHubPharRelease::tagToSemver($latest['tag_name']);
        $cmp = version_compare($remoteSemver, ApiClient::VERSION);

        if ($checkOnly) {
            $this->stdout('Install: PHAR (GitHub)');
            $this->stdout('Current: '.ApiClient::VERSION);
            $this->stdout(
                'Latest release: '.
                    $latest['tag_name'].
                    ' — '.
                    $latest['html_url'],
            );
            $this->stdout('Download: '.$latest['browser_download_url']);
            if ($cmp > 0) {
                $this->stdout(
                    'A newer version is available. Run: jetty update',
                );
            } elseif ($cmp < 0) {
                $this->stdout(
                    'This PHAR is newer than the latest GitHub release (unusual). Use --force to reinstall.',
                );
            } else {
                $this->stdout('Semver matches the latest release.');
            }

            return 0;
        }

        if ($cmp < 0 && ! $force) {
            $this->stdout(
                'This PHAR ('.
                    ApiClient::VERSION.
                    ') is newer than the latest GitHub release '.
                    $latest['tag_name'].
                    '. No update needed. Use --force to reinstall from GitHub.',
            );

            return 0;
        }

        if ($cmp === 0 && ! $force) {
            $this->stdout(
                'You\'re already on the latest version ('.
                    $latest['tag_name'].
                    ' — '.
                    $remoteSemver.
                    '). No update needed. Use --force to re-download the PHAR.',
            );

            return 0;
        }

        if (
            ! $this->applyPharDownload(
                $pharPath,
                $latest['browser_download_url'],
                $token,
            )
        ) {
            $this->stdout(
                'You\'re already on the latest version (downloaded file matches your current PHAR).',
            );

            return 0;
        }

        $this->stderr(
            'Updated PHAR to '.
                $latest['tag_name'].
                ' ('.
                $remoteSemver.
                '). Run jetty version to confirm.',
        );
        $this->emitPostUpdateConfigTip();

        return 0;
    }

    private function localPharUpdateUrl(): ?string
    {
        $u = UpdateConfig::localPharUrl();
        if ($u === null) {
            return null;
        }
        if (
            ! str_starts_with($u, 'http://') &&
            ! str_starts_with($u, 'https://')
        ) {
            return null;
        }

        return $u;
    }

    /**
     * @return bool true if the on-disk PHAR was replaced; false if the download was byte-identical to the existing file
     */
    private function applyPharDownload(
        string $pharPath,
        string $url,
        ?string $githubToken,
    ): bool {
        $tmp = $pharPath.'.download.'.uniqid('', true);
        try {
            GitHubPharRelease::downloadFile($url, $tmp, $githubToken);
        } catch (\Throwable $e) {
            @unlink($tmp);
            throw $e;
        }

        if (! is_file($tmp) || filesize($tmp) < 1024) {
            @unlink($tmp);
            throw new \RuntimeException(
                'Downloaded file looks invalid (too small).',
            );
        }

        if (is_file($pharPath) && filesize($tmp) === filesize($pharPath)) {
            $hNew = @hash_file('sha256', $tmp);
            $hOld = @hash_file('sha256', $pharPath);
            if (
                $hNew !== false &&
                $hOld !== false &&
                hash_equals($hNew, $hOld)
            ) {
                @unlink($tmp);

                return false;
            }
        }

        @chmod($tmp, 0755);
        if (! @rename($tmp, $pharPath)) {
            @unlink($tmp);
            throw new \RuntimeException(
                'Could not replace '.
                    $pharPath.
                    '. Try: mv '.
                    basename($tmp).
                    ' '.
                    basename($pharPath).
                    ' (from the same directory), or run jetty update from a shell outside the PHAR.',
            );
        }

        return true;
    }

    private function emitPostUpdateConfigTip(): void
    {
        $this->stdout(
            'Saved config (~/.config/jetty/config.json) is unchanged; run jetty setup only if you need a new Bridge URL or token.',
        );
    }

    /**
     * @param  list<string>  $args
     */
    private function updateComposerJettyClient(array $args): int
    {
        if (! class_exists(InstalledVersions::class)) {
            throw new \RuntimeException(
                'jetty update: Composer metadata not loaded. Use the PHAR install, or run `composer update jetty/client` from your project.',
            );
        }
        if (! InstalledVersions::isInstalled('jetty/client')) {
            throw new \RuntimeException(
                'jetty update: package jetty/client is not installed via Composer. Use the PHAR, or run: composer require jetty/client',
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
                self::runComposerInDirectory($root, $composer, [
                    'show',
                    '--self',
                    '--latest',
                    '--no-ansi',
                ]);
            } else {
                self::runComposerInDirectory($root, $composer, [
                    'outdated',
                    'jetty/client',
                    '--direct',
                    '--no-ansi',
                ]);
            }

            return 0;
        }

        if (
            ! $force &&
            ($rootPackage = InstalledVersions::getRootPackage()) &&
            ($rootPackage['name'] ?? '') !== 'jetty/client'
        ) {
            $outdatedJson = self::composerCaptureStdout($root, $composer, [
                'outdated',
                'jetty/client',
                '--direct',
                '--format=json',
            ]);
            if (is_string($outdatedJson)) {
                $outdatedJson = trim($outdatedJson);
                $decoded = json_decode($outdatedJson, true);
                if (is_array($decoded) && $decoded === []) {
                    $pretty = InstalledVersions::getPrettyVersion(
                        'jetty/client',
                    );
                    $this->stdout(
                        'You\'re already on the latest jetty/client for this project ('.
                            $pretty.
                            '). No update needed. Use --force to run composer update with --no-cache.',
                    );

                    return 0;
                }
            }
        }

        $cmd = ['update', 'jetty/client', '--no-interaction'];
        if ($force) {
            $cmd[] = '--no-cache';
            $cmd[] = '--with-all-dependencies';
        }

        $code = self::runComposerInDirectory($root, $composer, $cmd);
        if ($code !== 0) {
            throw new \RuntimeException(
                'composer '.
                    implode(' ', $cmd).
                    ' failed (exit '.
                    $code.
                    '). Run it manually from: '.
                    $root,
            );
        }

        $this->stdout(
            'Updated jetty/client via Composer in '.
                $root.
                '. Run jetty version to confirm.',
        );
        $this->emitPostUpdateConfigTip();

        return 0;
    }

    /**
     * @param  list<string>  $composerArgs
     */
    private static function composerCaptureStdout(
        string $root,
        string $composer,
        array $composerArgs,
    ): ?string {
        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $command = array_merge([$composer], $composerArgs);
        $proc = @proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (! is_resource($proc)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);
        if ($exitCode !== 0) {
            return null;
        }

        return is_string($out) ? $out : null;
    }

    /**
     * Composer project root that owns {@see InstalledVersions} for jetty/client.
     *
     * Do not call realpath() on the package path before checking for vendor/jetty/client:
     * path-repository and symlink installs resolve to the package source dir, so dirname(..,3)
     * would point at the wrong tree and `composer update jetty/client` would not update the app lock.
     */
    private static function composerProjectRootForJettyClient(): string
    {
        $raw = InstalledVersions::getInstallPath('jetty/client');
        if (! is_string($raw) || $raw === '') {
            throw new \RuntimeException(
                'Could not resolve jetty/client install path.',
            );
        }

        $fromCwd = self::composerProjectRootFromCwd($raw);
        if ($fromCwd !== null) {
            return $fromCwd;
        }

        $norm = str_replace('\\', '/', $raw);
        if (str_ends_with($norm, '/vendor/jetty/client')) {
            $root = dirname($raw, 3);
            $resolved = realpath($root);

            return $resolved !== false ? $resolved : $root;
        }

        $resolved = realpath($raw);
        if ($resolved === false) {
            throw new \RuntimeException(
                'jetty/client install path is not readable: '.$raw,
            );
        }

        $normResolved = str_replace('\\', '/', $resolved);
        if (str_ends_with($normResolved, '/vendor/jetty/client')) {
            $root = dirname($resolved, 3);
            $rootReal = realpath($root);

            return $rootReal !== false ? $rootReal : $root;
        }

        $dir = $resolved;
        for ($i = 0; $i < 24; $i++) {
            if (is_file($dir.\DIRECTORY_SEPARATOR.'composer.json')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        throw new \RuntimeException(
            'Could not resolve Composer project root for jetty/client (install path: '.
                $raw.
                ').',
        );
    }

    /**
     * Prefer the consumer project (directory containing vendor/jetty/client) by walking up from cwd.
     *
     * When jetty/client is installed via a path repository, {@see InstalledVersions::getInstallPath()}
     * often returns the source tree (e.g. …/jetty-client), so Composer would run in the package repo
     * instead of the app (e.g. beacon) that depends on it — the app lock would not update.
     */
    private static function composerProjectRootFromCwd(
        string $installPath,
    ): ?string {
        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }
        $resolvedInstall = realpath($installPath);
        if ($resolvedInstall === false) {
            return null;
        }
        $dir = $cwd;
        for ($i = 0; $i < 32; $i++) {
            $vendorJetty =
                $dir.
                \DIRECTORY_SEPARATOR.
                'vendor'.
                \DIRECTORY_SEPARATOR.
                'jetty'.
                \DIRECTORY_SEPARATOR.
                'client';
            if (is_dir($vendorJetty)) {
                $rj = realpath($vendorJetty);
                if ($rj !== false && $rj === $resolvedInstall) {
                    $root = realpath($dir);

                    return $root !== false ? $root : $dir;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    private static function resolveComposerBinary(): string
    {
        $env = getenv('COMPOSER_BINARY');
        if (
            is_string($env) &&
            $env !== '' &&
            (is_executable($env) || is_executable($env.'.bat'))
        ) {
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
            'composer was not found (install Composer on PATH or set COMPOSER_BINARY).',
        );
    }

    /**
     * @param  list<string>  $composerArgs  arguments after `composer`
     */
    private static function runComposerInDirectory(
        string $root,
        string $composer,
        array $composerArgs,
    ): int {
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
            $root,
        );
        if (! is_resource($proc)) {
            throw new \RuntimeException(
                'Could not run composer (proc_open failed).',
            );
        }

        return proc_close($proc);
    }

    private function releasesRepo(): string
    {
        foreach (
            ['JETTY_PHAR_RELEASES_REPO', 'JETTY_CLI_GITHUB_REPO'] as $key
        ) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return ApiClient::DEFAULT_PHAR_RELEASES_REPO;
    }

    private function githubTokenForReleases(): ?string
    {
        foreach (
            [
                'JETTY_PHAR_GITHUB_TOKEN',
                'JETTY_CLI_GITHUB_TOKEN',
                'GITHUB_TOKEN',
            ] as $key
        ) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    private function unknownCommandUpgradeHint(string $command): string
    {
        $newerOnly = ['setup', 'onboard', 'login', 'logout', 'reset', 'update'];
        if (! in_array($command, $newerOnly, true)) {
            return '';
        }

        return "\n\n".
            'This build of jetty/client is too old for `jetty '.
            $command.
            '`. '.
            'Upgrade: reinstall the PHAR from your Jetty install URL or GitHub Releases, run `jetty self-update` if your PHAR supports it, or `composer update jetty/client`. '.
            'You can configure this version with `jetty config set api-url …` and `jetty config set token …`.';
    }

    /**
     * @param  list<string>  $rest
     */
    private function cmdHelp(array $rest): int
    {
        $advanced =
            in_array('--advanced', $rest, true) || in_array('-a', $rest, true);
        $unknown = array_values(
            array_filter(
                $rest,
                fn (string $x) => ! in_array($x, ['--advanced', '-a'], true),
            ),
        );
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty help [--advanced|-a]\n".$this->helpText(),
            );
        }
        $this->printStyledMainHelp();
        $this->ui()->out('');
        $this->ui()->mutedLine(
            'Share options: '.$this->ui()->cmd('jetty share --help'),
        );
        if ($advanced) {
            $this->ui()->out('');
            $this->printAdvancedHelpStyled();
        } else {
            $this->ui()->out('');
            $this->ui()->infoLine(
                'Advanced (config & env): '.
                    $this->ui()->cmd('jetty help --advanced'),
            );
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     */
    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdList(array $global, array $rest = []): int
    {
        if (in_array('--help', $rest, true) || in_array('-h', $rest, true)) {
            $this->stdout("Usage: jetty list [--long|-l]\n");

            return 0;
        }
        $long = in_array('--long', $rest, true) || in_array('-l', $rest, true);
        $unknown = array_values(
            array_filter(
                $rest,
                fn (string $x) => ! in_array($x, ['--long', '-l'], true),
            ),
        );
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty list [--long|-l]\n",
            );
        }

        $client = $this->client($global);
        $tunnels = $client->listTunnels();

        $u = $this->ui();
        if ($tunnels === []) {
            $u->mutedLine('No tunnels for this account yet.');
            $u->infoLine('Create one with '.$u->cmd('jetty share'));

            return 0;
        }

        $u->section('Your tunnels');
        foreach ($tunnels as $t) {
            $id = (string) ($t['id'] ?? '');
            $status = (string) ($t['status'] ?? '');
            $public = (string) ($t['public_url'] ?? '');
            $local = (string) ($t['local_target'] ?? '');
            $st = $this->formatTunnelStatusLabel($status);
            if ($long) {
                $note =
                    isset($t['note']) &&
                    is_string($t['note']) &&
                    trim($t['note']) !== ''
                        ? trim($t['note'])
                        : '';
                $suffix = $note !== '' ? '  '.$u->dim('note: '.$note) : '';
                $u->out($u->bold($id).'  '.$st.'  '.$u->cyan($public));
                $u->mutedLine('    → '.$local.$suffix);

                // Display routing rules if present
                $routingRules =
                    isset($t['routing_rules']) && is_array($t['routing_rules'])
                        ? $t['routing_rules']
                        : [];
                if ($routingRules !== []) {
                    $u->mutedLine('    Routes:');
                    $ruleNum = 0;
                    foreach ($routingRules as $rule) {
                        if (! is_array($rule)) {
                            continue;
                        }
                        $enabled = $rule['enabled'] ?? true;
                        if (! $enabled) {
                            continue;
                        }
                        $ruleNum++;
                        $matchType = (string) ($rule['match_type'] ?? '');
                        $localHost = (string) ($rule['local_host'] ?? '');
                        $localPort = (int) ($rule['local_port'] ?? 0);
                        $upstream = $localHost.':'.$localPort;

                        if ($matchType === 'path_prefix') {
                            $pathPrefix =
                                (string) ($rule['path_prefix'] ?? '/');
                            $pattern =
                                $pathPrefix === '/' ? '/*' : $pathPrefix.'/*';
                            $u->mutedLine(
                                '      '.
                                    $ruleNum.
                                    '. '.
                                    $pattern.
                                    ' → '.
                                    $upstream.
                                    ' (path_prefix)',
                            );
                        } elseif ($matchType === 'header') {
                            $headerName = (string) ($rule['header_name'] ?? '');
                            $headerValue =
                                (string) ($rule['header_value'] ?? '');
                            $u->mutedLine(
                                '      '.
                                    $ruleNum.
                                    '. '.
                                    $headerName.
                                    ': '.
                                    $headerValue.
                                    ' → '.
                                    $upstream.
                                    ' (header)',
                            );
                        }
                    }
                    // Show fallback (tunnel's primary upstream)
                    $u->mutedLine('      (fallback) → '.$local);
                }
            } else {
                $u->out(
                    $u->bold($id).
                        '  '.
                        $st.
                        '  '.
                        $u->cyan($public).
                        '  '.
                        $u->dim($local),
                );
            }
        }
        $u->out('');

        return 0;
    }

    private function formatTunnelStatusLabel(string $status): string
    {
        $u = $this->ui();
        $s = strtolower(trim($status));
        if ($s === '' || $s === 'unknown') {
            return $u->dim($status !== '' ? $status : '—');
        }
        if (
            str_contains($s, 'active') ||
            str_contains($s, 'run') ||
            $s === 'up' ||
            $s === 'online'
        ) {
            return $u->green($status);
        }
        if (
            str_contains($s, 'idle') ||
            str_contains($s, 'pause') ||
            str_contains($s, 'sleep')
        ) {
            return $u->yellow($status);
        }
        if (
            str_contains($s, 'error') ||
            str_contains($s, 'fail') ||
            str_contains($s, 'down') ||
            str_contains($s, 'off')
        ) {
            return $u->red($status);
        }

        return $u->dim($status);
    }

    private function printEdgeAgentStderr(string $m): void
    {
        $u = $this->ui();
        if (str_starts_with($m, 'Edge agent connected')) {
            $u->successLine('Connected — forwarding HTTP to local upstream.');
            $this->printTrafficViewHint();

            return;
        }
        if (str_starts_with($m, 'Edge agent reconnected')) {
            $u->successLine('Reconnected — forwarding HTTP to local upstream.');

            return;
        }
        if (str_starts_with($m, 'edge: reconnecting')) {
            $u->err('  '.$u->yellow('↻ '.trim($m)));

            return;
        }
        if (str_starts_with($m, 'edge: WebSocket dropped')) {
            $u->err('  '.$u->dim($m));

            return;
        }
        // Traffic log lines: "[category]  METHOD /path STATUS SIZE ELAPSED"
        if (
            preg_match(
                "/^\[(\w+)]\s+(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\S+)\s+(\d{3})\s/",
                $m,
                $match,
            )
        ) {
            $category = $match[1];
            $status = (int) $match[4];

            $tv = $this->shareTrafficView();
            $tv->record($category);

            if (! $tv->shouldShow($category)) {
                return;
            }

            // Strip the category prefix for display, add the category icon
            $display =
                '  '.
                $tv->categoryTag($category).
                ' '.
                substr(
                    $m,
                    strlen($match[0]) -
                        strlen(
                            $match[2].' '.$match[3].' '.$match[4].' ',
                        ),
                );
            // Rebuild: "  ● GET  /path 200 12.3kB 45ms"
            $line = preg_replace(
                "/^\[\w+]\s+/",
                '  '.$tv->categoryTag($category).' ',
                $m,
            );

            if ($status >= 500) {
                $u->err($u->red($line));
            } elseif ($status >= 400) {
                $u->err($u->yellow($line));
            } elseif ($status >= 300) {
                $u->err($u->cyan($line));
            } elseif ($category === 'asset') {
                $u->err($u->dim($line));
            } else {
                $u->err($line);
            }

            return;
        }
        $u->err($m);
    }

    private function shareTrafficView(): ShareTrafficView
    {
        return $this->trafficView ??= new ShareTrafficView;
    }

    private function printTrafficViewHint(): void
    {
        $u = $this->ui();
        $u->err('');
        $u->err(
            $u->dim('  Views: [a]ll  [p]ages  [s]tatic  [j]son/api  [e]rrors'),
        );
    }

    /**
     * Check stdin for view-switching keystrokes (non-blocking).
     * Called from the share idle loop.
     */
    private function shareCheckViewSwitch(): void
    {
        if ($this->trafficView === null) {
            return;
        }
        if (! \is_resource(STDIN)) {
            return;
        }

        stream_set_blocking(STDIN, false);
        $chunk = @fread(STDIN, 64);
        stream_set_blocking(STDIN, true);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $u = $this->ui();
        $tv = $this->shareTrafficView();

        for ($i = 0, $len = strlen($chunk); $i < $len; $i++) {
            $key = $chunk[$i];
            if ($key === "\n" || $key === "\r") {
                continue;
            }
            if ($tv->handleKey($key)) {
                $u->err('');
                $u->err($tv->statusLine($u));
                $u->err('');
            }
        }
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdReplay(array $global, array $rest): int
    {
        if (
            $rest === [] ||
            in_array('--help', $rest, true) ||
            in_array('-h', $rest, true)
        ) {
            $this->stdout("Usage: jetty replay <sample-id>\n");
            $this->stdout(
                "Replays a stored request sample against the tunnel's local_host:local_port.\n",
            );
            $this->stdout(
                "GET and HEAD only unless JETTY_REPLAY_ALLOW_UNSAFE=1.\n",
            );

            return 0;
        }

        $id = (int) ($rest[0] ?? 0);
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid sample id');
        }

        $client = $this->client($global);
        $data = $client->getRequestSample($id);
        $method = strtoupper((string) ($data['method'] ?? 'GET'));
        $unsafe = ReplayConfig::allowUnsafe();
        if ($method !== 'GET' && $method !== 'HEAD' && ! $unsafe) {
            $this->stderr(
                'Replay only allows GET/HEAD by default. Set JETTY_REPLAY_ALLOW_UNSAFE=1 to replay other methods.',
            );

            return 1;
        }

        $tunnel = $data['tunnel'] ?? null;
        if (! is_array($tunnel)) {
            throw new \RuntimeException('API response missing tunnel');
        }

        $host = (string) ($tunnel['local_host'] ?? '127.0.0.1');
        $port = (int) ($tunnel['local_port'] ?? 80);
        $path = (string) ($data['path'] ?? '/');
        if ($path === '' || ($path[0] ?? '') !== '/') {
            $path = '/'.ltrim($path, '/');
        }

        $query = $data['query'] ?? null;
        $query = is_string($query) && $query !== '' ? $query : null;

        $localUrl = 'http://'.$host.':'.$port.$path;
        if ($query !== null) {
            $localUrl .= '?'.$query;
        }

        $ch = curl_init($localUrl);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($method === 'GET' || $method === 'HEAD') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $out = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($out === false) {
            throw new \RuntimeException('replay failed: '.$err);
        }

        $this->stdout((string) $out);
        $this->ui()->mutedLine('HTTP '.$code);

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $args
     */
    private function cmdDomains(array $global, array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->stdout("Usage: jetty domains [--json]\n");
            $this->stdout(
                "List subdomain labels reserved for your team (public URL preview).\n",
            );

            return 0;
        }
        $jsonOut =
            in_array('--json', $args, true) ||
            in_array('--machine', $args, true);
        $unknown = array_values(
            array_filter(
                $args,
                fn (string $x) => ! in_array($x, ['--json', '--machine'], true),
            ),
        );
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty domains [--json]\n",
            );
        }

        $client = $this->client($global);
        $payload = $client->listReservedSubdomains();
        $rows = $payload['data'];
        $meta = $payload['meta'] ?? [];
        $suffix =
            is_array($meta) &&
            isset($meta['tunnel_host_suffix']) &&
            is_string($meta['tunnel_host_suffix'])
                ? $meta['tunnel_host_suffix']
                : '';

        if ($jsonOut) {
            $this->stdout(
                json_encode(
                    ['data' => $rows, 'meta' => $meta],
                    JSON_THROW_ON_ERROR,
                ),
            );

            return 0;
        }

        $u = $this->ui();
        if ($rows === []) {
            $u->mutedLine('No reserved subdomains for this team.');
            $u->infoLine('Add labels in the Jetty app (Domains).');
            if ($suffix !== '') {
                $u->out(
                    '  '.$u->dim('Suffix').'  '.$u->cyan('*.'.$suffix),
                );
            }

            return 0;
        }

        $u->section('Reserved subdomain labels');
        if ($suffix !== '') {
            $u->out(
                '  '.
                    $u->dim('Public URLs look like').
                    '  '.
                    $u->cyan('label.'.$suffix),
            );
        }
        $u->out('');
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            $hint = (string) ($row['full_host_hint'] ?? '');
            if ($hint !== '') {
                $u->out(
                    '  '.$u->bold($u->green($slug)).'  '.$u->dim($hint),
                );
            } else {
                $u->out('  '.$u->bold($u->green($slug)));
            }
        }
        $u->out('');
        $u->infoLine(
            'Use '.
                $u->cmd('jetty share --subdomain=LABEL').
                ' or set subdomain in jetty.config.json',
        );

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $args
     */
    private function cmdDelete(array $global, array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException(
                'Usage: jetty delete <tunnel-id>',
            );
        }
        $id = trim($args[0]);
        if ($id === '') {
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
    private function cmdLogin(array $global, array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty login\n".$this->helpText(),
            );
        }
        try {
            SetupWizard::runLogin($global['config'], $global['region'] ?? null);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    private function cmdOnboard(array $global, array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty onboard\n".$this->helpText(),
            );
        }
        try {
            SetupWizard::runOnboarding(
                $global['config'],
                $global['region'] ?? null,
            );
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
            throw new \InvalidArgumentException(
                "Usage: jetty setup\n".$this->helpText(),
            );
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
            throw new \InvalidArgumentException(
                "Usage: jetty logout\n".$this->helpText(),
            );
        }
        Config::clearUserConfigKey('token');
        $this->stdout(
            'Removed saved API token from ~/.config/jetty/config.json.',
        );
        $envTok = getenv('JETTY_TOKEN');
        if (is_string($envTok) && trim($envTok) !== '') {
            $this->stderr(
                'Note: JETTY_TOKEN is still set in your environment; unset it to stop using that token.',
            );
        }

        return 0;
    }

    /**
     * @param  list<string>  $rest
     */
    private function cmdReset(array $rest): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty reset\n".$this->helpText(),
            );
        }
        Config::resetLocalUserConfig();
        $this->stdout(
            'Cleared local Jetty config (Bridge URL, token, subdomain, domain, tunnel_server) and removed ~/.jetty.json if present.',
        );
        $this->stdout(
            'Environment variables (JETTY_TOKEN, JETTY_API_URL, …) are unchanged; unset them if needed.',
        );
        $this->stdout(
            'Project files (./jetty.config.json or JETTY_CONFIG) are not deleted.',
        );

        return 0;
    }

    private function cmdConfig(array $global, array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException(
                "Usage: jetty config set|get|clear|wizard ...\n".
                    $this->helpText(),
            );
        }
        $sub = array_shift($args);

        return match ($sub) {
            'set' => $this->cmdConfigSet($args),
            'get' => $this->cmdConfigGet($args),
            'clear' => $this->cmdConfigClear($args),
            'wizard' => $this->cmdSetup($global, []),
            default => throw new \InvalidArgumentException(
                'Unknown config subcommand: '.$sub."\n".$this->helpText(),
            ),
        };
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigSet(array $args): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException(
                'Usage: jetty config set <key> <value>  (keys: server, api-url, token, subdomain, domain, tunnel-server)',
            );
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
            foreach (
                [
                    'api_url',
                    'server',
                    'token',
                    'subdomain',
                    'custom_domain',
                    'tunnel_server',
                ] as $k
            ) {
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
            throw new \InvalidArgumentException(
                'Usage: jetty config clear <key|all>',
            );
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
        if (
            ! array_key_exists($jsonKey, $m) ||
            $m[$jsonKey] === null ||
            $m[$jsonKey] === ''
        ) {
            $this->stdout("{$jsonKey}=");

            return;
        }
        $s = trim((string) $m[$jsonKey]);
        if ($jsonKey === 'token' && $s !== '') {
            $s =
                strlen($s) <= 8
                    ? '****'
                    : substr($s, 0, 4).'…'.substr($s, -4);
        }
        $this->stdout("{$jsonKey}={$s}");
    }

    // ── jetty doctor ────────────────────────────────────────────────

    private function cmdDoctor(): int
    {
        $u = $this->ui();
        $u->banner('doctor');

        $current = ApiClient::VERSION;
        $thisPath = $this->resolveRunningBinaryPath();

        // ── 1. Find all jetty installs ──
        $u->section('Installed copies');

        $installs = $this->findAllJettyInstalls();

        if ($installs === []) {
            $u->out('  No installs found (unexpected).');

            return 1;
        }

        $primary = null;
        $duplicates = [];

        foreach ($installs as $install) {
            $isCurrent = $install['path'] === $thisPath;
            $marker = $isCurrent ? $u->green(' ← active') : '';

            $versionStr =
                $install['version'] !== ''
                    ? 'v'.$install['version']
                    : $u->dim('unknown');
            $typeStr = $u->dim('('.$install['type'].')');

            $u->out('  '.$install['path']);
            $u->out('    '.$versionStr.'  '.$typeStr.$marker);
            $u->out('');

            if ($isCurrent) {
                $primary = $install;
            } else {
                $duplicates[] = $install;
            }
        }

        if (count($installs) === 1) {
            $u->successLine('Single install — no duplicates.');
        } else {
            $u->warnLine(
                count($installs).
                    ' installs found. Multiple copies can cause version mismatches.',
            );
        }

        // ── 2. Version check ──
        $u->out('');
        $u->section('Version');

        $latestTag = null;
        try {
            $repo = $this->releasesRepo();
            $token = $this->githubTokenForReleases();
            $latest = GitHubPharRelease::latest($repo, $token);
            if ($latest !== null) {
                $latestTag = $latest['tag_name'];
                $latestSemver = GitHubPharRelease::tagToSemver($latestTag);
                $cmp = version_compare($latestSemver, $current);

                $u->out('  '.$u->dim('Current:  ').'v'.$current);
                $u->out('  '.$u->dim('Latest:   ').'v'.$latestSemver);

                if ($cmp > 0) {
                    $u->out('');
                    $u->warnLine(
                        'Update available — run: '.$u->cmd('jetty update'),
                    );
                } else {
                    $u->out('');
                    $u->successLine('Up to date.');
                }
            }
        } catch (\Throwable) {
            $u->out('  '.$u->dim('Current:  ').'v'.$current);
            $u->out(
                '  '.
                    $u->dim('Latest:   ').
                    $u->dim('could not check (network error)'),
            );
        }

        // ── 3. Offer to clean other installs ──
        if ($duplicates !== []) {
            $u->out('');
            $u->section('Cleanup');

            // Number each non-active install for selection
            $numbered = array_values($duplicates);
            foreach ($numbered as $i => $dup) {
                $num = $i + 1;
                $vLabel =
                    $dup['version'] !== ''
                        ? 'v'.$dup['version']
                        : 'unknown version';
                $typeLabel = $dup['type'];
                $note = '';
                if ($typeLabel === 'project-wrapper') {
                    $note = $u->dim(' (dev wrapper)');
                } elseif ($typeLabel === 'composer-project') {
                    $note = $u->dim(' (project dependency)');
                }
                $u->out('  '.$u->bold('['.$num.']').' '.$dup['path']);
                $u->out(
                    '      '.$vLabel.'  '.$u->dim($typeLabel).$note,
                );
            }
            $u->out('');
            $u->errRaw(
                '  Remove which? Enter numbers (e.g. 1,3), "all", or "none": ',
            );
            $answer = strtolower(trim((string) fgets(\STDIN)));

            $toRemove = [];
            if ($answer === 'all') {
                $toRemove = $numbered;
            } elseif ($answer !== '' && $answer !== 'none' && $answer !== 'n') {
                foreach (explode(',', $answer) as $part) {
                    $idx = (int) trim($part) - 1;
                    if (isset($numbered[$idx])) {
                        $toRemove[] = $numbered[$idx];
                    }
                }
            }

            if ($toRemove === []) {
                $u->out('  Skipped.');
            } else {
                foreach ($toRemove as $dup) {
                    $this->removeJettyInstall($dup, $u);
                }
            }
        }

        // ── 4. PATH check ──
        $u->out('');
        $u->section('PATH');

        if ($primary !== null) {
            $primaryDir = dirname($primary['path']);
            $pathDirs = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
            $found = false;
            foreach ($pathDirs as $dir) {
                $rp = realpath($dir);
                if ($rp !== false && $rp === realpath($primaryDir)) {
                    $found = true;
                    break;
                }
            }
            if ($found) {
                $u->successLine(
                    'Primary install directory ('.
                        $primaryDir.
                        ') is in PATH.',
                );
            } else {
                $u->warnLine(
                    $primaryDir.
                        ' is not in PATH — add it to your shell profile.',
                );
            }
        }

        $u->out('');

        return 0;
    }

    /**
     * @param  array{path: string, version: string, type: string}  $install
     */
    private function removeJettyInstall(array $install, CliUi $u): void
    {
        $path = $install['path'];
        $type = $install['type'];

        if ($type === 'composer-global') {
            $u->out('  Removing Composer global jetty/client…');
            $out = [];
            exec('composer global remove jetty/client 2>&1', $out, $code);
            if ($code === 0) {
                $u->successLine('Removed Composer global install.');
            } else {
                $u->warnLine(
                    'Composer remove failed — run manually: composer global remove jetty/client',
                );
            }

            return;
        }

        if ($type === 'composer-project') {
            // Find the project root (up from vendor/bin/jetty)
            $projectRoot = dirname($path, 3); // vendor/bin/jetty → project root
            $u->out('  Removing jetty/client from '.$projectRoot.'…');
            $out = [];
            exec(
                'cd '.
                    escapeshellarg($projectRoot).
                    ' && composer remove jetty/client 2>&1',
                $out,
                $code,
            );
            if ($code === 0) {
                $u->successLine('Removed jetty/client from '.$projectRoot);
            } else {
                $u->warnLine(
                    'Composer remove failed — run manually: cd '.
                        $projectRoot.
                        ' && composer remove jetty/client',
                );
            }

            return;
        }

        // File or symlink removal
        if (is_link($path) || is_file($path)) {
            if (@unlink($path)) {
                $u->successLine('Removed '.$path);
            } else {
                $u->warnLine(
                    'Could not remove '.
                        $path.
                        ' — check permissions or run: sudo rm '.
                        $path,
                );
            }

            return;
        }

        $u->warnLine(
            'Cannot remove '.$path.' — file not found or not writable.',
        );
    }

    /**
     * Find all jetty binaries across common install locations and PATH.
     *
     * @return list<array{path: string, version: string, type: string}>
     */
    private function findAllJettyInstalls(): array
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: '';
        $candidates = [];

        // Fixed well-known locations
        $locations = [
            $home.'/.local/bin/jetty' => 'phar',
            '/usr/local/bin/jetty' => 'system',
            '/opt/homebrew/bin/jetty' => 'homebrew',
            $home.'/.composer/vendor/bin/jetty' => 'composer-global',
            $home.'/.config/composer/vendor/bin/jetty' => 'composer-global',
        ];

        foreach ($locations as $path => $type) {
            if ($path !== '' && (is_file($path) || is_link($path))) {
                $rp = realpath($path);
                $candidates[$rp !== false ? $rp : $path] = [
                    'path' => $rp !== false ? $rp : $path,
                    'type' => $type,
                ];
            }
        }

        // Scan PATH for any `jetty` binary
        $pathDirs = explode(PATH_SEPARATOR, getenv('PATH') ?: '');
        foreach ($pathDirs as $dir) {
            $dir = trim($dir);
            if ($dir === '' || $dir === '.') {
                continue;
            }
            $p = rtrim($dir, '/').\DIRECTORY_SEPARATOR.'jetty';
            if (is_file($p) || is_link($p)) {
                $rp = realpath($p);
                $key = $rp !== false ? $rp : $p;
                if (! isset($candidates[$key])) {
                    // Determine type
                    $type = 'unknown';
                    if (
                        str_contains($key, '/.composer/') ||
                        str_contains($key, '/.config/composer/')
                    ) {
                        $type = 'composer-global';
                    } elseif (str_contains($key, '/vendor/bin/')) {
                        $type = 'composer-project';
                    } elseif (str_contains($key, '.local/bin')) {
                        $type = 'phar';
                    } elseif (
                        str_contains($key, 'homebrew') ||
                        str_contains($key, '/opt/')
                    ) {
                        $type = 'homebrew';
                    }
                    $candidates[$key] = ['path' => $key, 'type' => $type];
                }
            }
        }

        // Check CWD for ./jetty (project wrapper)
        $cwd = getcwd();
        if ($cwd !== false) {
            $cwdJetty = $cwd.\DIRECTORY_SEPARATOR.'jetty';
            if (is_file($cwdJetty)) {
                $rp = realpath($cwdJetty);
                $key = $rp !== false ? $rp : $cwdJetty;
                if (! isset($candidates[$key])) {
                    $candidates[$key] = [
                        'path' => $key,
                        'type' => 'project-wrapper',
                    ];
                } else {
                    $candidates[$key]['type'] = 'project-wrapper';
                }
            }
        }

        // Scan common project directories for vendor/bin/jetty (Composer project installs)
        $projectRoots = [];
        if ($home !== '') {
            foreach (['Projects', 'Sites', 'Code', 'src', 'dev'] as $dir) {
                $d = $home.\DIRECTORY_SEPARATOR.$dir;
                if (is_dir($d)) {
                    $projectRoots[] = $d;
                }
            }
        }
        foreach ($projectRoots as $root) {
            $d = @opendir($root);
            if ($d === false) {
                continue;
            }
            while (($entry = readdir($d)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                // Check 2 levels: ~/Projects/foo/vendor/bin/jetty and ~/Projects/Apps/foo/vendor/bin/jetty
                foreach (['', \DIRECTORY_SEPARATOR.$entry] as $sub) {
                    $vendorBin =
                        $root.
                        \DIRECTORY_SEPARATOR.
                        $entry.
                        $sub.
                        \DIRECTORY_SEPARATOR.
                        'vendor'.
                        \DIRECTORY_SEPARATOR.
                        'bin'.
                        \DIRECTORY_SEPARATOR.
                        'jetty';
                    if (is_file($vendorBin) || is_link($vendorBin)) {
                        $rp = realpath($vendorBin);
                        $key = $rp !== false ? $rp : $vendorBin;
                        if (! isset($candidates[$key])) {
                            $candidates[$key] = [
                                'path' => $rp !== false ? $rp : $vendorBin,
                                'type' => 'composer-project',
                            ];
                        }
                    }
                }
            }
            closedir($d);
        }

        // Resolve versions
        $results = [];
        foreach ($candidates as $info) {
            $version = $this->probeJettyVersion($info['path']);
            $results[] = [
                'path' => $info['path'],
                'version' => $version,
                'type' => $info['type'],
            ];
        }

        return $results;
    }

    /**
     * Probe a jetty binary for its version string.
     */
    private function probeJettyVersion(string $path): string
    {
        // If it's the currently running binary, use our constant.
        if ($path === $this->resolveRunningBinaryPath()) {
            return ApiClient::VERSION;
        }

        $output = [];
        $code = 0;
        @exec(
            'php '.escapeshellarg($path).' version 2>/dev/null',
            $output,
            $code,
        );
        if ($code === 0 && isset($output[0])) {
            // Output is like "0.1.19" or "Jetty · v0.1.19..."
            foreach ($output as $line) {
                if (preg_match("/(\d+\.\d+\.\d+)/", $line, $m)) {
                    return $m[1];
                }
            }
        }

        return '';
    }

    private function resolveRunningBinaryPath(): string
    {
        // PHAR?
        $phar = \Phar::running(false);
        if ($phar !== '') {
            $rp = realpath($phar);

            return $rp !== false ? $rp : $phar;
        }

        // Script path (bin/jetty)
        $script = $_SERVER['SCRIPT_FILENAME'] ?? ($_SERVER['argv'][0] ?? '');
        if ($script !== '') {
            $rp = realpath($script);

            return $rp !== false ? $rp : $script;
        }

        return '';
    }

    // ── jetty share ──────────────────────────────────────────────────

    private function cmdShare(array $global, array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->stdout($this->shareUsageHelp());

            return 0;
        }

        $cw0 = getcwd();
        if ($cw0 !== false) {
            $rp0 = realpath($cw0);
            putenv(
                'JETTY_SHARE_INVOCATION_CWD='.($rp0 !== false ? $rp0 : $cw0),
            );
        }
        putenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');

        $localHost = '127.0.0.1';
        /** @var non-empty-string|null Last non-IP --site/--host value for tunnel redirect rewriting */
        $shareCliHostnameForRewrite = null;
        $upstreamExplicit = false;
        $tunnelServerFlag = null;
        $printUrlOnly = false;
        $subdomain = null;
        $shareVerbose = false;
        $noDetect = false;
        $serveDocroot = null;
        $deleteOnExit = getenv('JETTY_SHARE_DELETE_ON_EXIT') === '1';
        $noResume = false;
        $forceShare = false;
        $noHealthCheck = false;
        $healthPath = null;

        $shareFile = Config::readProjectShareOverrides();
        $shareProjectRoot = $shareFile['project_root'] ?? null;
        if (
            getenv('JETTY_SHARE_PROJECT_ROOT') === false &&
            is_string($shareProjectRoot) &&
            trim($shareProjectRoot) !== ''
        ) {
            $root = trim($shareProjectRoot);
            $cfgPath = Config::nearestProjectJettyConfigPath();
            if (
                $cfgPath !== null &&
                $root !== '' &&
                ! preg_match('#^(/|[A-Za-z]:[\\\\/])#', $root)
            ) {
                $root = dirname($cfgPath).\DIRECTORY_SEPARATOR.$root;
            }
            $rp = realpath($root);
            if ($rp !== false && is_dir($rp)) {
                putenv('JETTY_SHARE_PROJECT_ROOT='.$rp);
            }
        }
        $shareDebugAgent = filter_var(
            $shareFile['debug_agent'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        if (EdgeAgentDebug::enabledFromEnvironment()) {
            $shareDebugAgent = true;
        }
        $skipEdge = filter_var(
            $shareFile['skip_edge'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $noBodyRewrite = filter_var(
            $shareFile['no_body_rewrite'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $noJsRewrite = filter_var(
            $shareFile['no_js_rewrite'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $noCssRewrite = filter_var(
            $shareFile['no_css_rewrite'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        if (
            getenv('JETTY_SHARE_REWRITE_HOSTS') === false &&
            isset($shareFile['rewrite_hosts'])
        ) {
            $rh = $shareFile['rewrite_hosts'];
            if (is_string($rh) && trim($rh) !== '') {
                putenv('JETTY_SHARE_REWRITE_HOSTS='.trim($rh));
            } elseif (is_array($rh)) {
                $parts = array_filter(
                    array_map('trim', array_map('strval', $rh)),
                );
                if ($parts !== []) {
                    putenv('JETTY_SHARE_REWRITE_HOSTS='.implode(',', $parts));
                }
            }
        }

        $positional = [];
        foreach ($args as $arg) {
            if ($arg === '--delete-on-exit') {
                $deleteOnExit = true;

                continue;
            }
            if ($arg === '--no-delete-on-exit') {
                $deleteOnExit = false;

                continue;
            }
            if ($arg === '--verbose' || $arg === '-v' || $arg === '--errors') {
                $shareVerbose = true;

                continue;
            }
            if ($arg === '--debug-agent') {
                $shareDebugAgent = true;

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
                $serveDocroot =
                    $p === ''
                        ? $this->shareDefaultServeDocroot()
                        : $this->shareResolveServeDocroot($p);

                continue;
            }
            if (str_starts_with($arg, '--server=')) {
                $v = trim(substr($arg, strlen('--server=')));
                if ($v === '') {
                    throw new \InvalidArgumentException(
                        '--server= requires a tunnel id (e.g. us-west-1)',
                    );
                }
                $this->assertTunnelServerLabel($v);
                $tunnelServerFlag = $v;

                continue;
            }
            if (str_starts_with($arg, '--site=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--site=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--bind=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--bind=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--local=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--local=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--local-host=')) {
                $localHost = $this->parseShareUpstreamValue(
                    $arg,
                    '--local-host=',
                );
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--host=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--host=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--subdomain=')) {
                $subdomain = substr($arg, strlen('--subdomain='));
                if ($subdomain === '') {
                    throw new \InvalidArgumentException(
                        '--subdomain= requires a value',
                    );
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
            if ($arg === '--edge') {
                $skipEdge = false;

                continue;
            }
            if ($arg === '--no-body-rewrite') {
                $noBodyRewrite = true;

                continue;
            }
            if ($arg === '--no-js-rewrite') {
                $noJsRewrite = true;

                continue;
            }
            if ($arg === '--no-css-rewrite') {
                $noCssRewrite = true;

                continue;
            }
            if ($arg === '--no-resume') {
                $noResume = true;

                continue;
            }
            if ($arg === '--force' || $arg === '-f') {
                $forceShare = true;

                continue;
            }
            if ($arg === '--no-health-check') {
                $noHealthCheck = true;

                continue;
            }
            if (str_starts_with($arg, '--health-path=')) {
                $healthPath = substr($arg, strlen('--health-path='));

                continue;
            }
            if (str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException(
                    'Unknown option: '.
                        $arg.
                        "\n".
                        $this->shareUsageSummary(),
                );
            }
            $positional[] = $arg;
        }

        if ($shareCliHostnameForRewrite !== null) {
            putenv(
                'JETTY_SHARE_CLI_UPSTREAM_HOSTNAME='.
                    $shareCliHostnameForRewrite,
            );
        }

        if (count($positional) > 1) {
            throw new \InvalidArgumentException(
                "Too many arguments.\n".$this->shareUsageHelp(),
            );
        }

        $explicitPort = null;
        if ($positional !== []) {
            $rawPort = $positional[0];
            if (
                ! is_numeric($rawPort) ||
                (string) (int) $rawPort !== (string) $rawPort
            ) {
                throw new \InvalidArgumentException(
                    'Invalid port: expected 1–65535, or omit port to auto-detect a local dev server.'.
                        "\n".
                        $this->shareUsageHelp(),
                );
            }
            $explicitPort = (int) $rawPort;
        }

        if ($printUrlOnly && $serveDocroot !== null) {
            throw new \InvalidArgumentException(
                '--serve cannot be combined with --print-url-only.',
            );
        }

        $builtInServerProc = null;
        $pendingServe = null;
        $port = 8000;
        $portHint = null;

        if ($serveDocroot !== null) {
            $listenPort = $explicitPort ?? $this->shareFindFreeTcpPort();
            if ($listenPort < 1 || $listenPort > 65535) {
                throw new \InvalidArgumentException(
                    'Invalid port for --serve.',
                );
            }
            $pendingServe = ['docroot' => $serveDocroot, 'port' => $listenPort];
            $localHost = '127.0.0.1';
            $port = $listenPort;
            $portHint =
                'PHP built-in server → http://127.0.0.1:'.
                $listenPort.
                ' (root: '.
                $serveDocroot.
                ')';
            $upstreamExplicit = true;
        } else {
            $detected = null;
            if (
                ! $noDetect &&
                ! $upstreamExplicit &&
                $explicitPort === null &&
                getenv('JETTY_SHARE_NO_DETECT') !== '1'
            ) {
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
                [$port, $portHint] = $this->resolveSharePort(
                    $localHost,
                    $explicitPort,
                );
            }
        }

        $cfg = $this->resolvedConfig($global);
        $cfg->validate();
        $client = new ApiClient($cfg->apiUrl, $cfg->token);

        if ($pendingServe !== null) {
            try {
                $builtInServerProc = $this->shareStartPhpBuiltInServer(
                    $pendingServe['docroot'],
                    $pendingServe['port'],
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Failed to start PHP built-in server: '.$e->getMessage(),
                );
            }
        }
        if ($subdomain === null || trim((string) $subdomain) === '') {
            $d = trim($cfg->defaultSubdomain);
            if ($d !== '') {
                $subdomain = $d;
            }
        }

        $tunnelServer =
            $tunnelServerFlag !== null
                ? $tunnelServerFlag
                : trim($cfg->defaultTunnelServer);
        if ($tunnelServer === '') {
            $tunnelServer = null;
        } else {
            $this->assertTunnelServerLabel($tunnelServer);
        }

        if ($shareVerbose) {
            $this->shareVerboseLog(true, 'API base: '.$client->apiBaseUrl());
            $this->shareVerboseLog(
                true,
                'share target: '.
                    $localHost.
                    ':'.
                    $port.
                    '; subdomain='.
                    ($subdomain ?? '').
                    '; tunnel_server='.
                    ($tunnelServer ?? 'null'),
            );
        }

        if ($portHint !== null && ! $printUrlOnly) {
            $this->ui()->infoLine($portHint);
        }

        $healthPathResolved = '/';
        if ($healthPath !== null && trim((string) $healthPath) !== '') {
            $healthPathResolved = '/'.ltrim(trim((string) $healthPath), '/');
        } else {
            $envPath = getenv('JETTY_SHARE_HEALTH_PATH');
            if (is_string($envPath) && trim($envPath) !== '') {
                $healthPathResolved = '/'.ltrim(trim($envPath), '/');
            }
        }

        $upstreamHostPolicy = ShareUpstreamHostPolicy::fromEnvironment();
        if (! $upstreamHostPolicy->allows($localHost)) {
            throw new \InvalidArgumentException(
                $upstreamHostPolicy->denyMessage($localHost).
                    "\n".
                    $this->shareUsageHelp(),
            );
        }

        $this->shareProbeUpstreamHealth(
            $localHost,
            $port,
            $healthPathResolved,
            $noHealthCheck,
        );

        $data = null;
        $id = 0;
        $publicUrl = '';
        $resumedTunnel = false;
        $tunnelLock = null;

        try {
            $resumeId = null;
            $allowResume =
                ! $noResume && getenv('JETTY_SHARE_NO_RESUME') !== '1';
            if ($allowResume) {
                try {
                    $listed = $client->listTunnels();
                    $hit = $this->shareFindResumableTunnel(
                        $listed,
                        $localHost,
                        $port,
                        $subdomain,
                        $tunnelServer,
                    );
                    if ($hit !== null && (int) ($hit['id'] ?? 0) > 0) {
                        $resumeId = (int) $hit['id'];
                    }
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'resume: list '.
                                count($listed).
                                ' tunnel(s); match='.
                                ($resumeId !== null
                                    ? (string) $resumeId
                                    : 'none'),
                        );
                    }
                } catch (\Throwable $e) {
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'resume: list tunnels failed: '.$e->getMessage(),
                        );
                    }
                }
            }

            if ($shareVerbose) {
                if ($resumeId !== null) {
                    $this->shareVerboseLog(
                        true,
                        'POST /api/tunnels/'.
                            $resumeId.
                            '/attach body: '.
                            json_encode(
                                [
                                    'local_host' => $localHost,
                                    'local_port' => $port,
                                    'server' => $tunnelServer !== null &&
                                        trim((string) $tunnelServer) !== ''
                                            ? trim((string) $tunnelServer)
                                            : null,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                    );
                } else {
                    $this->shareVerboseLog(
                        true,
                        'POST /api/tunnels body: '.
                            json_encode(
                                [
                                    'local_host' => $localHost,
                                    'local_port' => $port,
                                    'subdomain' => $subdomain,
                                    'server' => $tunnelServer,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                    );
                }
            }

            if ($resumeId !== null) {
                try {
                    $data = $client->attachTunnel(
                        $resumeId,
                        $localHost,
                        $port,
                        $tunnelServer,
                    );
                    $resumedTunnel = true;
                } catch (\RuntimeException $e) {
                    if (
                        preg_match("/HTTP (404|405)\b/", $e->getMessage()) !== 1
                    ) {
                        throw $e;
                    }
                    if (! $printUrlOnly) {
                        $this->stderr(
                            'Note: tunnel resume is not available on this Bridge; registering a new tunnel instead.',
                        );
                    }
                    $data = $client->createTunnel(
                        $localHost,
                        $port,
                        $subdomain,
                        $tunnelServer,
                    );
                }
            } else {
                $data = $client->createTunnel(
                    $localHost,
                    $port,
                    $subdomain,
                    $tunnelServer,
                );
            }

            if ($shareVerbose) {
                $this->shareVerboseLog(
                    true,
                    'tunnel API response (redacted): '.
                        json_encode(
                            $this->shareRedactTunnelResponseForLog($data),
                            JSON_THROW_ON_ERROR,
                        ),
                );
            }

            $publicUrl = (string) ($data['public_url'] ?? '');
            $localTarget = (string) ($data['local_target'] ?? '');
            $id = (string) ($data['id'] ?? '');

            $tunnelLock = new TunnelLock($id);
            $lockStatus = $tunnelLock->check();
            if ($lockStatus['locked'] && ! $forceShare) {
                $u = $this->ui();
                $u->out('');
                $u->out('  '.$u->bold($u->cyan($publicUrl)));
                $u->out('');
                $u->out(
                    '  '.
                        $u->dim(str_pad('Forwarding', 14)).
                        $u->dim($localTarget.' → ').
                        ($publicUrl !== ''
                            ? parse_url($publicUrl, PHP_URL_HOST)
                            : ''),
                );
                $u->out(
                    '  '.$u->dim(str_pad('Tunnel', 14)).$u->dim('#').$id,
                );
                $u->out(
                    '  '.
                        $u->dim(str_pad('PID', 14)).
                        $lockStatus['pid'].
                        ($lockStatus['started'] !== null
                            ? '  '.
                                $u->dim('started '.$lockStatus['started'])
                            : ''),
                );
                $u->out('');

                $msg =
                    'Another `jetty share` process (PID '.
                    $lockStatus['pid'].
                    ') is already connected to this tunnel.';
                $msg .=
                    "\n\nTo fix: kill it (`kill ".
                    $lockStatus['pid'].
                    '`) or use --force to proceed anyway.';
                throw new \RuntimeException($msg);
            }
            if ($lockStatus['stale']) {
                TunnelLock::cleanupStaleLocks();
            }
            $lockResult = $tunnelLock->acquire();
            if (! $lockResult['acquired']) {
                $u = $this->ui();
                $u->out('');
                $u->out('  '.$u->bold($u->cyan($publicUrl)));
                $u->out('');

                $existingPid = $lockResult['existing_pid'] ?? null;
                $msg =
                    'Another `jetty share` process is already connected to tunnel '.
                    $id.
                    '.';
                if ($existingPid !== null) {
                    $msg .= ' (PID '.$existingPid.')';
                    $msg .=
                        "\nTo fix: kill it (`kill ".
                        $existingPid.
                        '`) or use --force to proceed anyway.';
                } else {
                    $msg .= "\nUse --force to proceed anyway.";
                }
                throw new \RuntimeException($msg);
            }

            $shareStartedAt = time();
            EdgeAgent::initHttpActivity($shareStartedAt);
            $telegramBase = [
                'tunnel_id' => $id,
                'public_url' => $publicUrl,
                'local_target' => $localTarget,
                'server' => $tunnelServer,
            ];
            $status = (string) ($data['status'] ?? '');
            $subdomain = (string) ($data['subdomain'] ?? '');
            $edge = is_array($data['edge'] ?? null) ? $data['edge'] : [];
            $ws = (string) ($edge['websocket_url'] ?? '');
            $srvOut =
                isset($data['server']) &&
                is_string($data['server']) &&
                $data['server'] !== ''
                    ? $data['server']
                    : null;
            $agentToken = (string) ($data['agent_token'] ?? '');

            if ($printUrlOnly) {
                $this->stdout($publicUrl);
                TelegramNotifier::shareStarted(
                    array_merge($telegramBase, ['print_url_only' => true]),
                );

                return 0;
            }

            $u = $this->ui();

            $curlHost = parse_url($publicUrl, PHP_URL_HOST);
            if (! is_string($curlHost) || $curlHost === '') {
                $suffix =
                    $this->tunnelHostSuffixFromPublicUrl($publicUrl) ??
                    $this->tunnelHostSuffix();
                $curlHost = $subdomain.'.'.$suffix;
            }

            // ── Public URL (hero line) ──
            $u->out('');
            $u->out('  '.$u->bold($u->cyan($publicUrl)));
            $u->out('');

            // ── Tunnel details ──
            $pairs = [
                [
                    'Forwarding',
                    $u->dim($localTarget.' → ').$u->cyan($curlHost),
                ],
            ];
            if ($srvOut !== null) {
                $pairs[] = ['Server', $srvOut];
            }
            $pairs[] = [
                'Tunnel',
                $u->dim('#').
                (string) $id.
                ($resumedTunnel ? '  '.$u->yellow('resumed') : ''),
            ];
            $invocationCwd = getenv('JETTY_SHARE_INVOCATION_CWD');
            if (is_string($invocationCwd) && $invocationCwd !== '') {
                $pairs[] = ['Project', $u->dim($invocationCwd)];
            }
            foreach ($pairs as [$label, $value]) {
                $u->out('  '.$u->dim(str_pad($label, 14)).$value);
            }
            $u->out('');

            if ($this->shareUpdateCheck()) {
                return 0;
            }
            $this->shareDuplicateInstallCheck();

            TelegramNotifier::shareStarted($telegramBase);

            try {
                $client->heartbeat($id);
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'initial heartbeat OK for tunnel id '.$id,
                    );
                }
            } catch (\Throwable $e) {
                $this->ui()->warnLine('initial heartbeat: '.$e->getMessage());
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'initial heartbeat exception: '.$e->getMessage(),
                    );
                }
            }

            $edgeOutcome = null;
            $edgeFailDetail = null;
            $shareRewriteOptions = $this->shareTunnelRewriteOptionsFromCli(
                $noBodyRewrite,
                $noJsRewrite,
                $noCssRewrite,
            );
            $agentDebug = $shareDebugAgent
                ? EdgeAgentDebug::stderrJsonSink([
                    'tunnel_id' => $id,
                    'local_upstream' => $localHost.':'.$port,
                    'public_tunnel_host' => $curlHost,
                ])
                : null;
            if ($shareDebugAgent) {
                $this->stderr(
                    "Agent debug: structured JSON lines on stderr (prefix [jetty:agent-debug]).\n",
                );
            }
            $ndjsonFile = getenv('JETTY_SHARE_DEBUG_NDJSON_FILE');
            if (
                getenv('JETTY_SHARE_DEBUG_REWRITE') === '1' &&
                (! is_string($ndjsonFile) || trim($ndjsonFile) === '')
            ) {
                $this->stderr(
                    "[jetty share] JETTY_SHARE_DEBUG_REWRITE=1 but JETTY_SHARE_DEBUG_NDJSON_FILE is unset — file NDJSON is disabled.\n".
                        '  export JETTY_SHARE_DEBUG_NDJSON_FILE="/abs/path/debug.ndjson"  (each line: {"event","ts_ms","rewrite_debug_rev","data"}; rev '.
                        TunnelResponseRewriter::REWRITE_DEBUG_REV.
                        ").\n".
                        "  If your log still shows top-level sessionId/hypothesisId (no rewrite_debug_rev), rebuild the PHAR or run php bin/jetty from jetty-client.\n",
                );
            }
            if (is_string($ndjsonFile) && trim($ndjsonFile) !== '') {
                TunnelResponseRewriter::emitDebugNdjson(
                    'jetty.share.ndjson_sink_ready',
                    [
                        'tunnel_id' => $id,
                        'local_upstream' => $localHost.':'.$port,
                        'public_tunnel_host' => $curlHost,
                        'edge_agent_will_run' => ! $skipEdge && $ws !== '' && $agentToken !== '',
                    ],
                );
            }
            $rewriteHostProbe = TunnelResponseRewriter::tunnelRewriteHostLookup(
                $localHost,
            );
            $rewriteHostProbeCount = count($rewriteHostProbe);
            if (
                $rewriteHostProbeCount <= 2 &&
                filter_var($localHost, FILTER_VALIDATE_IP)
            ) {
                $this->ui()->warnLine(
                    'Tunnel redirect rewrite only knows '.
                        $rewriteHostProbeCount.
                        ' host(s) for upstream '.
                        $localHost.
                        '. '.
                        'If the browser jumps off the tunnel, run `jetty share` from this source tree (`php bin/jetty` or rebuild your PHAR), '.
                        'or `cd` into your Laravel app, set JETTY_SHARE_PROJECT_ROOT, or use --site=your-site.test.',
                );
            }
            $hotFile = TunnelResponseRewriter::detectViteHotFile();
            if ($hotFile !== null) {
                $hotContents = @file_get_contents($hotFile);
                $hotUrl = is_string($hotContents) ? trim($hotContents) : '';
                $hotProjectDir = dirname(dirname($hotFile)); // public/hot → project root
                $this->ui()->warnLine(
                    'Vite dev server detected (`public/hot` file exists'.
                        ($hotUrl !== '' ? ': '.$hotUrl : '').
                        '). Assets served by Vite (CSS, JS) run on a separate port that is NOT tunnelled — the page will likely appear blank or unstyled through the tunnel.',
                );
                // Offer to run npm run build automatically.
                $hasPackageJson = is_file(
                    rtrim($hotProjectDir, '/').'/package.json',
                );
                if ($hasPackageJson && ! ($printUrlOnly ?? false)) {
                    $this->stderr(
                        '  Run `npm run build` now to compile assets? [Y/n] ',
                    );
                    $answer = trim((string) fgets(\STDIN));
                    if ($answer === '' || strtolower($answer[0]) === 'y') {
                        $this->stderr('  Building assets…');
                        if (
                            TunnelResponseRewriter::runNpmBuild($hotProjectDir)
                        ) {
                            $this->ui()->successLine(
                                'Assets built successfully. The tunnel will serve compiled assets.',
                            );
                        } else {
                            $this->ui()->warnLine(
                                'Build failed — the page may still appear blank. Try running `npm run build` manually.',
                            );
                        }
                    } else {
                        $this->stderr(
                            '  Skipped. To fix manually: stop `npm run dev`, run `npm run build`, then reload the tunnel URL.',
                        );
                    }
                } else {
                    $this->stderr(
                        '  To fix: stop `npm run dev`, run `npm run build`, then restart `jetty share`.',
                    );
                }
                $this->stderr('');
            }
            if (
                ! $printUrlOnly &&
                ! $skipEdge &&
                $ws !== '' &&
                $agentToken !== ''
            ) {
                $this->stderr($this->ui()->dim('  Connecting…'));
                try {
                    $edgeOutcome = EdgeAgent::run(
                        $ws,
                        $id,
                        $agentToken,
                        $localHost,
                        $port,
                        $client,
                        $id,
                        fn (string $m) => $this->printEdgeAgentStderr($m),
                        $shareVerbose,
                        $shareRewriteOptions,
                        $curlHost,
                        $agentDebug,
                        function () use (
                            $client,
                            $id,
                            $localHost,
                            $port,
                            $tunnelServer,
                        ): string {
                            $data = $client->attachTunnel(
                                $id,
                                $localHost,
                                $port,
                                $tunnelServer,
                            );
                            $t = (string) ($data['agent_token'] ?? '');
                            if ($t === '') {
                                throw new \RuntimeException(
                                    'attach response missing agent_token',
                                );
                            }

                            return $t;
                        },
                    );
                } catch (\Throwable $e) {
                    $edgeFailDetail = $e->getMessage();
                    $this->ui()->errorLine(
                        'edge agent failed: '.$e->getMessage(),
                    );
                    if ($shareVerbose) {
                        $this->ui()->verboseLine(
                            '[jetty:share] '.
                                get_class($e).
                                ' in '.
                                $e->getFile().
                                ':'.
                                $e->getLine(),
                        );
                        $this->ui()->verboseLine(
                            '[jetty:share] '.$e->getTraceAsString(),
                        );
                    }
                    $this->ui()->warnLine(
                        'Continuing with heartbeats only (no HTTP forwarding until you fix edge connectivity).',
                    );
                    $edgeOutcome = EdgeAgentResult::FailedEarly;
                }
            } elseif (! $printUrlOnly && ! $skipEdge && $ws === '') {
                $this->ui()->warnLine(
                    'Bridge returned no edge WebSocket URL (JETTY_EDGE_WS_URL). Heartbeats only.',
                );
            } elseif (! $printUrlOnly && ! $skipEdge && $agentToken === '') {
                $this->ui()->warnLine(
                    'No agent_token in API response — heartbeats only.',
                );
            }

            $needsHeartbeatLoop = true;
            if ($edgeOutcome === EdgeAgentResult::Finished) {
                $needsHeartbeatLoop = false;
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'edge agent finished (user stop); skipping heartbeat fallback',
                    );
                }
            } elseif ($edgeOutcome === EdgeAgentResult::Disconnected) {
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'edge WebSocket dropped unexpectedly; falling back to heartbeat loop',
                    );
                }
                $this->stderr(
                    "\nEdge WebSocket disconnected (idle timeout, proxy, or edge restart). Tunnel stays registered; heartbeats continue.\n",
                );
                $this->stderr($this->shareExitTunnelHint($deleteOnExit));
                $needsHeartbeatLoop = true;
            } elseif ($edgeOutcome === EdgeAgentResult::FailedEarly) {
                $this->stderr('');
                $this->stderr(
                    'Edge WebSocket agent did not stay up; falling back to heartbeats only (tunnel stays registered).',
                );
                $this->stderr(
                    'Fix edge connectivity, or use --skip-edge for registration + heartbeats without the agent. '.
                        $this->shareCtrlCHint($deleteOnExit),
                );
                $needsHeartbeatLoop = true;
                TelegramNotifier::edgeAgentFailed(
                    $id,
                    $publicUrl,
                    $edgeFailDetail ??
                        'Edge WebSocket agent exited early (see CLI output).',
                );
            }

            $idleAutoDeleted = false;
            if ($needsHeartbeatLoop) {
                $idleAutoDeleted = $this->runShareHeartbeatLoop(
                    $client,
                    $id,
                    $publicUrl,
                    $shareVerbose,
                    $deleteOnExit,
                    $shareStartedAt,
                );
            }

            if ($idleAutoDeleted) {
                return 0;
            }

            if ($deleteOnExit) {
                TelegramNotifier::shareEnded(
                    $id,
                    $publicUrl,
                    'session ended (CLI deleting tunnel)',
                );

                try {
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'DELETE /api/tunnels/'.$id,
                        );
                    }
                    $client->deleteTunnel($id);
                    $this->stderr("Tunnel {$id} deleted.\n");
                } catch (\Throwable $e) {
                    $this->stderr(
                        'warning: could not delete tunnel '.
                            $id.
                            ': '.
                            $e->getMessage(),
                    );
                    TelegramNotifier::tunnelDeleteFailed($id, $e->getMessage());
                    if ($shareVerbose) {
                        $this->stderr(
                            '[jetty:share] delete exception: '.
                                $e->getTraceAsString(),
                        );
                    }
                }
            } else {
                TelegramNotifier::shareEnded(
                    $id,
                    $publicUrl,
                    'session ended (tunnel left registered — remove in app or jetty delete)',
                );
                $this->stderr(
                    "\nTunnel {$id} left registered. Remove it in the Jetty web app, or run: jetty delete {$id}\n",
                );
            }

            return 0;
        } catch (\Throwable $e) {
            TelegramNotifier::shareFailed(
                $data === null ? 'create_tunnel' : 'share',
                $e,
                [
                    'tunnel_id' => $id,
                    'public_url' => $publicUrl,
                ],
            );
            throw $e;
        } finally {
            $tunnelLock?->release();
            $this->shareStopBuiltInServer($builtInServerProc);
        }
    }

    private function shareDefaultServeDocroot(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException(
                'Could not determine working directory for --serve.',
            );
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

        return $real !== false && is_dir($real)
            ? $real
            : throw new \InvalidArgumentException('Not a directory: '.$path);
    }

    private function shareFindFreeTcpPort(): int
    {
        $s = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
        );
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
            throw new \InvalidArgumentException(
                'Document root is not a directory: '.$docroot,
            );
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
            $docroot,
        );
        if (! is_resource($proc)) {
            throw new \RuntimeException(
                'proc_open failed for PHP built-in server',
            );
        }
        usleep(200_000);
        if (! $this->tcpPortAcceptsConnections('127.0.0.1', $port)) {
            proc_close($proc);
            throw new \RuntimeException(
                'PHP built-in server did not listen on 127.0.0.1:'.$port,
            );
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
            $this->ui()->verboseLine('[jetty:share] '.$message);
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
            $out['agent_token'] =
                '(length '.strlen($out['agent_token']).')';
        }

        return $out;
    }

    /**
     * @return bool True if the tunnel was removed automatically (idle policy); caller should not delete again.
     */
    private function runShareHeartbeatLoop(
        ApiClient $client,
        string $tunnelId,
        string $publicUrl,
        bool $verbose,
        bool $deleteOnExit,
        int $shareStartedAt,
    ): bool {
        $idleCfg = ShareIdleConfig::fromEnvironment();
        $idleDisabled = $idleCfg->disabled;
        $promptAfter = $idleCfg->promptMinutes;
        $graceMin = $idleCfg->graceMinutes;

        if ($deleteOnExit) {
            $this->stderr(
                "\nSending heartbeats every 25s. Ctrl+C to delete this tunnel and exit.\n",
            );
        } else {
            $this->stderr(
                "\nSending heartbeats every 25s. Ctrl+C to stop this session (tunnel stays registered until you remove it in the app or run `jetty delete {$tunnelId}`).\n",
            );
        }
        if (! $idleDisabled) {
            $this->stderr(
                "Long idle: after {$promptAfter} minutes without HTTP traffic you will be prompted; if there is still no traffic and you do not type `keep` within {$graceMin} minutes, this tunnel is removed automatically (JETTY_SHARE_IDLE_DISABLE=1 to turn off).\n",
            );
        }
        if ($verbose) {
            $this->shareVerboseLog(
                true,
                'heartbeat loop start (tunnel id '.$tunnelId.')',
            );
        }

        $stop = false;
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            $handler = function () use (&$stop, $verbose): void {
                $stop = true;
                if ($verbose) {
                    fwrite(
                        \STDERR,
                        "[jetty:share] caught signal; stopping heartbeat loop\n",
                    );
                }
            };
            \pcntl_signal(\SIGINT, $handler);
            \pcntl_signal(\SIGTERM, $handler);
        } else {
            $this->stderr(
                "\n(ext-pcntl not loaded — Ctrl+C handling may vary by platform.)\n",
            );
        }

        $confirmDeadline = null;
        $idleBaselineAtPrompt = null;
        $stdinBuf = '';

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
                        $this->shareVerboseLog(
                            true,
                            'heartbeat #'.$beatNum.' OK',
                        );
                    }
                } catch (\Throwable $e) {
                    $this->stderr('heartbeat failed: '.$e->getMessage());
                    if ($verbose) {
                        $this->shareVerboseLog(
                            true,
                            'heartbeat exception: '.
                                get_class($e).
                                ' '.
                                $e->getMessage(),
                        );
                    }
                }
                $nextBeat = $now + 25;
            }

            if (! $idleDisabled) {
                $lastAct = EdgeAgent::lastHttpActivityUnix() ?? $shareStartedAt;

                if ($confirmDeadline !== null) {
                    if (
                        $idleBaselineAtPrompt !== null &&
                        $lastAct > $idleBaselineAtPrompt
                    ) {
                        $this->stderr(
                            "HTTP activity detected; idle warning cleared.\n",
                        );
                        $confirmDeadline = null;
                        $idleBaselineAtPrompt = null;
                    } elseif ($this->shareStdinHasKeep($stdinBuf)) {
                        $this->stderr(
                            "Keeping tunnel registered; idle timer reset.\n",
                        );
                        EdgeAgent::markHttpActivity();
                        $confirmDeadline = null;
                        $idleBaselineAtPrompt = null;
                    } elseif (time() >= $confirmDeadline) {
                        try {
                            $client->deleteTunnel($tunnelId);
                            $this->stderr(
                                "\nTunnel {$tunnelId} removed automatically (no HTTP traffic and no `keep` within {$graceMin} minutes).\n",
                            );
                            TelegramNotifier::shareEnded(
                                $tunnelId,
                                $publicUrl,
                                'idle timeout (tunnel removed by CLI policy)',
                            );
                        } catch (\Throwable $e) {
                            $this->stderr(
                                'warning: could not remove idle tunnel '.
                                    $tunnelId.
                                    ': '.
                                    $e->getMessage(),
                            );
                            TelegramNotifier::tunnelDeleteFailed(
                                $tunnelId,
                                $e->getMessage(),
                            );
                        }
                        $stop = true;

                        return true;
                    }
                }

                $idleSec = time() - $lastAct;
                if (
                    $confirmDeadline === null &&
                    $idleSec >= $promptAfter * 60
                ) {
                    $this->stderr(
                        $this->shareIdlePromptMessage(
                            $tunnelId,
                            $publicUrl,
                            $promptAfter,
                            $graceMin,
                        ),
                    );
                    $idleBaselineAtPrompt = $lastAct;
                    $confirmDeadline = time() + $graceMin * 60;
                }
            }

            usleep(200_000);
        }
        if ($verbose) {
            $this->shareVerboseLog(true, 'heartbeat loop end');
        }

        return false;
    }

    /**
     * Non-blocking stdin read; returns true if the user typed keep / y / yes on a line.
     */
    private function shareStdinHasKeep(string &$buffer): bool
    {
        if (! \is_resource(STDIN)) {
            return false;
        }
        if (\function_exists('posix_isatty') && ! posix_isatty(STDIN)) {
            return false;
        }
        stream_set_blocking(STDIN, false);
        $chunk = fread(STDIN, 4096);
        stream_set_blocking(STDIN, true);
        if ($chunk === false || $chunk === '') {
            return false;
        }

        // Check for single-key view switches before buffering lines.
        if ($this->trafficView !== null) {
            $u = $this->ui();
            $tv = $this->shareTrafficView();
            $filtered = '';
            for ($i = 0, $len = strlen($chunk); $i < $len; $i++) {
                $key = $chunk[$i];
                if ($key !== "\n" && $key !== "\r" && $tv->handleKey($key)) {
                    $u->err('');
                    $u->err($tv->statusLine($u));
                    $u->err('');
                } else {
                    $filtered .= $key;
                }
            }
            $chunk = $filtered;
        }

        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = (string) substr($buffer, $pos + 1);
            $t = strtolower(trim($line));
            if ($t === 'keep' || $t === 'y' || $t === 'yes') {
                return true;
            }
        }

        return false;
    }

    private function shareIdlePromptMessage(
        string $tunnelId,
        string $publicUrl,
        int $promptAfterMinutes,
        int $graceMinutes,
    ): string {
        $urlLine = $publicUrl !== '' ? "Public URL: {$publicUrl}\n" : '';

        return "\nNo HTTP traffic for {$promptAfterMinutes} minutes through this tunnel.\n".
            $urlLine.
            "Keep tunnel {$tunnelId} registered? Type keep (or y) and press Enter within {$graceMinutes} minutes, ".
            "or send a request to the URL above. Otherwise this tunnel will be removed automatically.\n\n";
    }

    private function shareCtrlCHint(bool $deleteOnExit): string
    {
        if ($deleteOnExit) {
            return 'Ctrl+C to exit and delete the tunnel.';
        }

        return 'Ctrl+C to stop this session (tunnel stays registered).';
    }

    private function shareExitTunnelHint(bool $deleteOnExit): string
    {
        $tail = $deleteOnExit
            ? 'Press Ctrl+C to exit and delete the tunnel.'
            : 'Press Ctrl+C to stop this session (tunnel stays registered until you remove it in the web app).';

        return 'HTTP via the public URL will not reach your app until you run `jetty share` again or the agent reconnects. '.
            $tail.
            "\n";
    }

    /**
     * @param  non-empty-string|null  $slot
     */
    private function shareMaybeSetCliHostnameForRewrite(
        ?string &$slot,
        string $candidate,
    ): void {
        $c = strtolower(trim($candidate));
        if ($c === '' || filter_var($c, FILTER_VALIDATE_IP)) {
            return;
        }
        $slot = $c;
    }

    private function parseShareUpstreamValue(
        string $arg,
        string $prefix,
    ): string {
        $v = substr($arg, strlen($prefix));
        if (trim($v) === '') {
            $hint =
                $prefix === '--host='
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
                'Invalid --server value "'.
                    $label.
                    '": use a tunnel id (letters/digits with optional . _ -), e.g. us-west-1.',
            );
        }
    }

    /**
     * Show an update-available notice in the tunnel info block (if a newer version exists).
     * Uses the same cached check as {@see maybePrintUpdateNotice} (at most one GitHub API call per 24h).
     */
    /**
     * @return bool True if the process should exit (update was applied).
     */
    private function shareUpdateCheck(): bool
    {
        $u = $this->ui();
        $current = ApiClient::VERSION;

        if (UpdateConfig::isNoticeSkipped()) {
            $u->out('  '.$u->dim(str_pad('Version', 14)).'v'.$current);

            return false;
        }

        $pharPath = \Phar::running(false);
        $canCheck = true;
        if ($pharPath !== '' && $this->localPharUpdateUrl() !== null) {
            $canCheck = false;
        }
        if (
            $pharPath === '' &&
            (! class_exists(InstalledVersions::class) ||
                ! InstalledVersions::isInstalled('jetty/client'))
        ) {
            $canCheck = false;
        }

        $latestTag = null;
        $updateAvailable = false;

        if ($canCheck) {
            $cachePath = $this->jettyUpdateNoticeCachePath();
            if ($cachePath !== '') {
                $now = time();
                $cache = $this->readUpdateNoticeCache($cachePath);
                $needRefresh =
                    $cache === null ||
                    $now - (int) ($cache['checked_at'] ?? 0) >= 86400;

                // Invalidate when the cached remote version is no longer
                // newer than the running version (e.g. user upgraded).
                if (
                    ! $needRefresh &&
                    $cache !== null &&
                    ! empty($cache['update_available'])
                ) {
                    $cachedRemote = (string) ($cache['remote_semver'] ?? '');
                    if (
                        $cachedRemote !== '' &&
                        version_compare($cachedRemote, $current) <= 0
                    ) {
                        $needRefresh = true;
                    }
                }

                if ($needRefresh) {
                    try {
                        $repo = $this->releasesRepo();
                        $token = $this->githubTokenForReleases();
                        $latest = GitHubPharRelease::latest($repo, $token);
                        if ($latest !== null) {
                            $remoteSemver = GitHubPharRelease::tagToSemver(
                                $latest['tag_name'],
                            );
                            $cmp = version_compare($remoteSemver, $current);
                            $cache = [
                                'checked_at' => $now,
                                'remote_tag' => $latest['tag_name'],
                                'remote_semver' => $remoteSemver,
                                'update_available' => $cmp > 0,
                                'last_notice_at' => (int) ($cache['last_notice_at'] ?? 0),
                                'last_notified_tag' => (string) ($cache['last_notified_tag'] ??
                                        ''),
                            ];
                            $this->writeUpdateNoticeCache($cachePath, $cache);
                        }
                    } catch (\Throwable) {
                        // Network error — show version without latest info.
                    }
                }

                if ($cache !== null) {
                    $latestTag = (string) ($cache['remote_tag'] ?? '');
                    $updateAvailable =
                        (bool) ($cache['update_available'] ?? false);
                }
            }
        }

        if ($updateAvailable && $latestTag !== '') {
            $latestSemver = GitHubPharRelease::tagToSemver($latestTag);
            $u->out(
                '  '.
                    $u->dim(str_pad('Version', 14)).
                    'v'.
                    $current.
                    '  '.
                    $u->yellow('update available: v'.$latestSemver),
            );
            $u->out('');

            $canSelfUpdate =
                $pharPath !== '' ||
                (class_exists(InstalledVersions::class) &&
                    InstalledVersions::isInstalled('jetty/client'));
            if ($canSelfUpdate) {
                $u->errRaw('  Update now? [Y/n] ');
                $answer = trim((string) fgets(\STDIN));
                if ($answer === '' || strtolower($answer[0]) === 'y') {
                    $u->err('  Updating…');
                    try {
                        if ($pharPath !== '') {
                            $this->updatePharInPlace([], $pharPath);
                        } else {
                            $this->updateComposerJettyClient([]);
                        }
                        $u->successLine(
                            'Updated to v'.
                                $latestSemver.
                                '. Run `jetty share` again to use the new version.',
                        );

                        return true;
                    } catch (\Throwable $e) {
                        $u->warnLine('Update failed: '.$e->getMessage());
                        $u->err('  Continuing with v'.$current.'…');
                    }
                }
            }
        } elseif ($latestTag !== '') {
            $u->out(
                '  '.
                    $u->dim(str_pad('Version', 14)).
                    'v'.
                    $current.
                    '  '.
                    $u->green('latest'),
            );
        } else {
            $u->out('  '.$u->dim(str_pad('Version', 14)).'v'.$current);
        }

        return false;
    }

    /**
     * Warn once during `jetty share` if multiple jetty binaries are found on the system.
     */
    private function shareDuplicateInstallCheck(): void
    {
        $installs = $this->findAllJettyInstalls();
        // Filter out project wrappers — those are expected in dev repos.
        $real = array_filter(
            $installs,
            fn ($i) => $i['type'] !== 'project-wrapper',
        );
        if (count($real) <= 1) {
            return;
        }

        $u = $this->ui();
        $paths = array_map(
            fn ($i) => $i['path'].
                ' ('.
                $i['type'].
                ($i['version'] !== '' ? ', v'.$i['version'] : '').
                ')',
            $real,
        );
        $u->warnLine(
            'Multiple jetty installs detected — this can cause version mismatches. Run '.
                $u->cmd('jetty doctor').
                ' to clean up.',
        );
        foreach ($paths as $p) {
            $u->err('    '.$u->dim($p));
        }
    }

    /**
     * CLI flags override environment for this `jetty share` run. Returns null when no CLI overrides (EdgeAgent uses env only).
     */
    private function shareTunnelRewriteOptionsFromCli(
        bool $noBody,
        bool $noJs,
        bool $noCss,
    ): ?TunnelRewriteOptions {
        if (! $noBody && ! $noJs && ! $noCss) {
            return null;
        }

        $base = TunnelRewriteOptions::fromEnvironment();
        if ($noBody) {
            return new TunnelRewriteOptions(
                false,
                false,
                false,
                $base->maxBodyBytes,
            );
        }

        return new TunnelRewriteOptions(
            $base->bodyRewrite,
            $noJs ? false : $base->jsRewrite,
            $noCss ? false : $base->cssRewrite,
            $base->maxBodyBytes,
        );
    }

    private function shareUsageSummary(): string
    {
        return 'Usage: jetty share [port] [--host=127.0.0.1] [--server=us-west-1] [--site=HOST] [--subdomain=label] [--print-url-only] [--skip-edge] [--serve[=DIR]] [--no-detect] [--no-resume] [--force|-f] [--health-path=PATH] [--no-health-check] [--delete-on-exit] [--no-body-rewrite] [--no-js-rewrite] [--no-css-rewrite] [--verbose|-v|--errors] [--debug-agent] (alias: http)';
    }

    private function shareUsageHelp(): string
    {
        return $this->shareUsageSummary().
            <<<'TXT'


              port  Optional. If omitted: auto-detect upstream from cwd (Laravel APP_URL, Herd/Valet links, DDEV, Docker Compose, Vite/Next/etc., or .env PORT), else scan common dev ports on 127.0.0.1. With --site=HOSTNAME (not 127.0.0.1), tries :443 then :80 when open (Valet/Herd TLS first — avoids HTTP→HTTPS redirect loops); pass an explicit port (e.g. 8000) for php artisan serve only.
              --host= / --site= / --bind= / --local= / --local-host=  Upstream hostname or IP (default 127.0.0.1).
              --serve[=DIR]  Start PHP’s built-in server (default docroot: ./public if present, else cwd) and tunnel to it; optional port as first arg.
              --no-detect    Skip local-dev auto-detection (use plain 127.0.0.1 + port scan). Env: JETTY_SHARE_NO_DETECT=1
              --no-resume    Always register a new tunnel (skip GET /api/tunnels + attach). Env: JETTY_SHARE_NO_RESUME=1
              --force / -f   Proceed even if another `jetty share` is already running for this tunnel (not recommended — causes edge conflicts).
              --health-path=PATH  Upstream probe path (default /). Env: JETTY_SHARE_HEALTH_PATH
              --no-health-check   Skip GET probe to local upstream before creating the tunnel. Env: JETTY_SHARE_NO_HEALTH_CHECK=1
              --skip-edge  Register + heartbeats only; no WebSocket forwarding agent.
              --no-body-rewrite  Disable tunnel URL rewriting of response bodies (HTML/CSS/JS). Env: JETTY_SHARE_NO_BODY_REWRITE=1
              --no-js-rewrite  Disable rewriting quoted URLs inside inline/standalone JavaScript only. Env: JETTY_SHARE_NO_JS_REWRITE=1
              --no-css-rewrite  Disable rewriting url() inside CSS (inline style + <style>). Env: JETTY_SHARE_NO_CSS_REWRITE=1
              --delete-on-exit  Exit: call DELETE /api/tunnels (default: leave tunnel registered — remove in the web app or `jetty delete`). Env: JETTY_SHARE_DELETE_ON_EXIT=1
              --no-delete-on-exit  Force no delete on exit (overrides env).
              --verbose / -v / --errors  Log connection steps, heartbeats, and edge WebSocket frames (stderr).
              --debug-agent  Structured agent diagnostics as JSON lines on stderr (prefix [jetty:agent-debug]); also JETTY_SHARE_DEBUG_AGENT=1 or jetty.config.json share.debug_agent. Heartbeat lines: JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=1.

            TXT;
    }

    /**
     * Valet/Herd-style hostnames (non-loopback, non-IP): prefer :443 then :80 before scanning common
     * dev ports, so TLS matches secured local sites and avoids HTTP→HTTPS redirect loops in tunnels.
     */
    private static function shareUpstreamHostPrefersStandardWebPort(
        string $localHost,
    ): bool {
        $h = strtolower(trim($localHost));
        if ($h === '' || $h === 'localhost' || $h === '::1') {
            return false;
        }

        return filter_var($h, FILTER_VALIDATE_IP) === false;
    }

    /**
     * @return array{0: int, 1: string|null} port and optional stderr hint when port was inferred
     */
    private function resolveSharePort(string $localHost, ?int $explicit): array
    {
        if ($explicit !== null) {
            if ($explicit < 1 || $explicit > 65535) {
                throw new \InvalidArgumentException(
                    'port must be between 1 and 65535',
                );
            }

            return [$explicit, null];
        }

        $common = [8000, 3000, 5173, 8080, 5000, 4000, 8765, 8888];
        if (self::shareUpstreamHostPrefersStandardWebPort($localHost)) {
            // Prefer :443 first: Valet/Herd often redirect http://site → https://site; tunneling :80
            // yields 301→tunnel URL→301 loops. HTTPS upstream avoids that.
            $common = array_merge([443, 80], $common);
        }

        foreach ($common as $p) {
            if (! $this->tcpPortAcceptsConnections($localHost, $p)) {
                continue;
            }
            if (
                $p === 443 &&
                self::shareUpstreamHostPrefersStandardWebPort($localHost)
            ) {
                return [
                    $p,
                    'Local upstream: '.
                    $localHost.
                    ':443 (auto — TLS preferred to avoid redirect loops; override with e.g. `jetty share 80`).',
                ];
            }
            if (
                $p === 80 &&
                self::shareUpstreamHostPrefersStandardWebPort($localHost)
            ) {
                return [
                    $p,
                    'Local upstream: '.
                    $localHost.
                    ':80 (auto — :443 not available; Valet/Herd may redirect, ensure :443 is listening).',
                ];
            }

            return [
                $p,
                'Local upstream: '.
                $localHost.
                ':'.
                $p.
                ' (auto-detected).',
            ];
        }

        return [
            8000,
            'Local upstream: '.
            $localHost.
            ':8000 (auto — no dev port responded; pass a port if yours is different).',
        ];
    }

    private function tcpPortAcceptsConnections(
        string $host,
        int $port,
        float $timeoutSeconds = 0.2,
    ): bool {
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

    /**
     * GET local upstream before registering the tunnel so misconfiguration fails fast.
     *
     * @param  non-empty-string  $path  Path beginning with /
     */
    private function shareProbeUpstreamHealth(
        string $localHost,
        int $port,
        string $path,
        bool $noHealthCheck,
    ): void {
        if ($noHealthCheck || getenv('JETTY_SHARE_NO_HEALTH_CHECK') === '1') {
            return;
        }

        $url = LocalUpstreamUrl::baseForCurl($localHost, $port).$path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException(
                'upstream health check: curl_init failed for '.$url,
            );
        }

        $connectT = EdgeAgent::upstreamConnectTimeoutSeconds();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => max(15, $connectT + 5),
            CURLOPT_CONNECTTIMEOUT => $connectT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPGET => true,
        ];

        // For local HTTPS (port 443), skip SSL verification — Valet/Herd/local dev certs are self-signed.
        if ($port === 443) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException(
                'upstream health check failed: could not reach '.
                    $url.
                    ' ('.
                    ($err !== '' ? $err : 'curl error '.$errno).
                    '). Fix your local server or pass --no-health-check.',
            );
        }

        if ($status === 0) {
            throw new \RuntimeException(
                'upstream health check failed: no HTTP response from '.
                    $url.
                    '. Fix your local server or pass --no-health-check.',
            );
        }

        if ($status >= 500) {
            throw new \RuntimeException(
                'upstream health check failed: '.
                    $url.
                    ' returned HTTP '.
                    $status.
                    '. Fix your app or pass --no-health-check.',
            );
        }
    }

    /**
     * Pick an existing API tunnel row to reconnect when local target + edge server + optional subdomain match.
     * Valet-style hostnames also resume across {@code :80} and {@code :443} for the same host.
     *
     * @param  list<array<string, mixed>>  $tunnels
     * @return array<string, mixed>|null
     */
    private function shareFindResumableTunnel(
        array $tunnels,
        string $localHost,
        int $port,
        ?string $subdomain,
        ?string $tunnelServer,
    ): ?array {
        return TunnelResumeMatcher::findResumableTunnel(
            $tunnels,
            $localHost,
            $port,
            $subdomain,
            $tunnelServer,
            self::shareUpstreamHostPrefersStandardWebPort($localHost),
        );
    }

    /**
     * Prefer the suffix from Bridge’s public_url so the curl hint matches the API even when
     * the shell still has JETTY_TUNNEL_HOST=tunnel… from an old export.
     *
     * @return non-empty-string|null
     */
    private function tunnelHostSuffixFromPublicUrl(string $publicUrl): ?string
    {
        $publicUrl = trim($publicUrl);
        if ($publicUrl === '') {
            return null;
        }
        $parts = parse_url($publicUrl);
        if (
            ! is_array($parts) ||
            empty($parts['host']) ||
            ! is_string($parts['host'])
        ) {
            return null;
        }
        $host = $parts['host'];
        $dot = strpos($host, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }

        $suffix = substr($host, $dot + 1);

        return $suffix !== '' ? $suffix : null;
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

        return 'tunnels.usejetty.online';
    }

    /**
     * Rich help for interactive terminals; plain text remains in {@see helpText()} for errors and pipes.
     */
    private function printStyledMainHelp(): void
    {
        $u = $this->ui();
        $u->banner('PHP client · tunnels & sharing');
        $u->section('Quick start');
        $u->commandGrid([
            ['jetty', 'First run: opens setup when no API token is saved'],
            [
                'jetty login',
                'Sign in via browser; choose team, subdomain, region',
            ],
            [
                'jetty onboard [--region=eu]',
                'Sign in via browser; pick Bridge / server',
            ],
            [
                'jetty setup',
                'Change Bridge, server, or token (interactive menu)',
            ],
        ]);
        $u->out('');
        $u->section('Tunnels');
        $u->commandGrid([
            [
                'jetty share [port]',
                'Public HTTPS URL → your local app (alias: http)',
            ],
            ['jetty list [--long|-l]', 'List tunnels for your account'],
            ['jetty delete <id>', 'Remove a tunnel'],
            ['jetty replay <sample-id>', 'Replay a captured request locally'],
            [
                'jetty domains [--json]',
                'Reserved subdomain labels for your team',
            ],
        ]);
        $u->out('');
        $u->section('Configuration');
        $u->commandGrid([
            [
                'jetty config set|get|clear|wizard …',
                'Persist settings (~/.config/jetty/config.json)',
            ],
            ['jetty logout', 'Clear saved token only'],
            ['jetty reset', 'Clear all local Jetty CLI settings'],
            [
                'jetty version [--install] [--check-update]',
                'Show version and install kind',
            ],
            ['jetty update', 'Update to the latest version (PHAR or Composer)'],
            [
                'jetty doctor',
                'Find duplicate installs, check version, clean up',
            ],
        ]);
        $u->out('');
        $u->section('Global flags');
        $u->out(
            '  '.
                $u->flag('--config=PATH').
                '   '.
                $u->dim('Alternate JSON config file'),
        );
        $u->out(
            '  '.
                $u->flag('--api-url=URL').
                '   '.
                $u->dim('Override Bridge API base URL'),
        );
        $u->out(
            '  '.
                $u->flag('--token=TOKEN').
                '   '.
                $u->dim('Override API token for this invocation'),
        );
        $u->out(
            '  '.
                $u->flag('--region=CODE').
                '  '.
                $u->dim(
                    'Regional Bridge (e.g. eu → https://eu.usejetty.online)',
                ),
        );
        $u->out('');
        $u->mutedLine('Package: jetty/client  ·  Docs: jetty help --advanced');
    }

    private function printAdvancedHelpStyled(): void
    {
        $u = $this->ui();
        $lines = explode("\n", $this->helpTextAdvanced());
        foreach ($lines as $line) {
            if ($line === '') {
                $u->out('');

                continue;
            }
            if (preg_match('/^──\s*(.+?)\s*──\s*$/u', $line, $m)) {
                $u->section(trim($m[1]));

                continue;
            }
            if (preg_match('/^(\s{2})(JETTY_[A-Z0-9_]+)(.*)$/', $line, $m)) {
                $u->out($m[1].$u->envName($m[2]).$u->dim($m[3]));

                continue;
            }
            $u->out($line);
        }
    }

    private function helpText(): string
    {
        return <<<'TXT'
        Jetty PHP client (Composer package jetty/client) — tunnel API helper.

        Common commands:
          jetty                      First-run: runs setup when no token is configured (same as onboard)
          jetty login                Sign in via browser; choose team, subdomain, and region defaults
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
          jetty update [--check] [--force]   Update to the latest version (auto-detects PHAR or Composer)
          jetty install-client       composer require jetty/client in the current project (useful with a global jetty on PATH)
          jetty login                Browser login with team/subdomain/region preferences
          jetty onboard              (see also: plain `jetty` when no token)
          jetty setup
          jetty help [--advanced|-a]   This help; --advanced lists config file & environment variables
          jetty logout
          jetty reset
          jetty list [--long]   Tunnels (--long includes notes when set)
          jetty replay <sample-id>   Replay a captured request against your local upstream (GET by default; see JETTY_REPLAY_ALLOW_UNSAFE)
          jetty domains [--json]   Reserved subdomain labels for your team (from Domains in the app)
          jetty delete <id>
          jetty config set|get|clear|wizard ...
          jetty share [port] [--host=127.0.0.1] [--server=us-west-1] [--site=HOST] [--subdomain=label] [--print-url-only] [--skip-edge] [--serve[=DIR]] [--no-detect] [--no-resume] [--force|-f] [--delete-on-exit] [--no-body-rewrite] [--no-js-rewrite] [--no-css-rewrite] [--verbose|-v|--errors] [--debug-agent]
            (alias: http)  Auto-detect local dev upstream from cwd (see jetty help --advanced), or --serve for a static PHP server
            --server= tunnel/edge id; default from config.  --site= / --host= upstream host (default 127.0.0.1)

        Install: composer require jetty/client  (or: composer global require jetty/client — put Composer’s global vendor/bin on PATH)
          Same config (~/.config/jetty/config.json) for PHAR and Composer. Releases: one “Release CLI” workflow ships the PHAR on GitHub and the same version to Packagist — bump once, not twice.
          Day to day: pick one binary (PHAR or Composer); jetty update upgrades the copy you run. Use jetty doctor to find and clean up duplicate installs.

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
          Prefer JSON config (above). Precedence: CLI flags → env → config file.

          API / Bridge (CLI)
          JETTY_API_URL              API base URL (preferred)
          JETTY_SERVER               Legacy: host or https:// URL if JETTY_API_URL unset
          JETTY_BRIDGE_URL           Bridge root if neither API_URL nor SERVER set
          JETTY_ONBOARD_BRIDGE_URL   Same (curl|bash installer sets this)
          JETTY_REGION               Same as --region=
          JETTY_TOKEN                Dashboard personal access token
          JETTY_TUNNEL_SERVER        Default --server= for jetty share (edge region id)
          JETTY_CONFIG               Path to JSON config file
          JETTY_ALLOW_LOCAL_BRIDGE=1 Allow localhost Bridge in saved api_url / bootstrap
          JETTY_CLI_LOCAL_URL        Optional extra bootstrap URL (legacy alias: JETTY_CLI_DEV_URL)
          JETTY_CLI_BOOTSTRAP_FALLBACKS  Comma-separated extra Bridge roots

          jetty share — dozens of optional JETTY_SHARE_* toggles (upstream, resume, health, rewrite, body/js/css,
          edge reconnect, WebSocket ping, idle prompt, request samples, debug). Full list and defaults: jetty-client/README.md
          Common: JETTY_SHARE_UPSTREAM=URL  JETTY_SHARE_REWRITE_HOSTS=h1,h2  JETTY_SHARE_NO_DETECT=1
          Debug stderr: JETTY_SHARE_DEBUG_REWRITE=1  JETTY_SHARE_DEBUG_AGENT=1
          Opt out of extras: JETTY_SHARE_CAPTURE_SAMPLES=0  JETTY_SHARE_TELEMETRY=0

          jetty replay
          JETTY_REPLAY_ALLOW_UNSAFE=1  Allow non-GET replay (dangerous)

          PHAR / self-update
          JETTY_PHAR_PATH            Global PHAR path for jetty global-update --phar
          JETTY_CLI_GITHUB_REPO      owner/repo or URL; JETTY_PHAR_RELEASES_REPO is an alternate name for a URL
          JETTY_PHAR_GITHUB_TOKEN    Private GitHub / rate limits
          JETTY_LOCAL_PHAR_URL       Force PHAR downloads from this URL (else GitHub)
          JETTY_SKIP_UPDATE_NOTICE=1 Suppress “update available” hint after commands

          Bridge app (.env): see .env.example and config/jetty.php (tunnel host, edge, plans, Telegram, …).

        TXT;
    }

    /**
     * After successful commands, optionally print a one-line notice when a newer PHAR/Packagist release
     * exists (GitHub cli-v*). Cached: at most one GitHub check per 24h; at most one notice per 24h for the
     * same release tag (immediate notice when the tag changes). Opt out: JETTY_SKIP_UPDATE_NOTICE=1.
     */
    private function maybePrintUpdateNotice(
        string $command,
        int $exitCode,
    ): void {
        if ($exitCode !== 0) {
            return;
        }
        if (UpdateConfig::isNoticeSkipped()) {
            return;
        }

        $skipCommands = [
            'version',
            '--version',
            '-V',
            'update',
            'self-update',
            'global-update',
            'help',
            '--help',
            '-h',
        ];
        if (in_array($command, $skipCommands, true)) {
            return;
        }

        $pharPath = \Phar::running(false);
        if ($pharPath !== '' && $this->localPharUpdateUrl() !== null) {
            return;
        }

        if (
            $pharPath === '' &&
            (! class_exists(InstalledVersions::class) ||
                ! InstalledVersions::isInstalled('jetty/client'))
        ) {
            return;
        }

        $cachePath = $this->jettyUpdateNoticeCachePath();
        if ($cachePath === '') {
            return;
        }

        $now = time();
        /** @var array{checked_at?: int, remote_tag?: string, remote_semver?: string, update_available?: bool, last_notice_at?: int, last_notified_tag?: string}|null $cache */
        $cache = $this->readUpdateNoticeCache($cachePath);
        $needRefresh =
            $cache === null ||
            $now - (int) ($cache['checked_at'] ?? 0) >= 86400;

        // Invalidate when the cached remote version is no longer newer than
        // the running version (e.g. user upgraded or cache had a stale pick).
        if (
            ! $needRefresh &&
            $cache !== null &&
            ! empty($cache['update_available'])
        ) {
            $cachedRemote = (string) ($cache['remote_semver'] ?? '');
            if (
                $cachedRemote !== '' &&
                version_compare($cachedRemote, ApiClient::VERSION) <= 0
            ) {
                $needRefresh = true;
            }
        }

        if ($needRefresh) {
            $repo = $this->releasesRepo();
            $token = $this->githubTokenForReleases();
            $latest = GitHubPharRelease::latest($repo, $token);
            if ($latest === null) {
                return;
            }
            $remoteSemver = GitHubPharRelease::tagToSemver($latest['tag_name']);
            $cmp = version_compare($remoteSemver, ApiClient::VERSION);
            $cache = [
                'checked_at' => $now,
                'remote_tag' => $latest['tag_name'],
                'remote_semver' => $remoteSemver,
                'update_available' => $cmp > 0,
                'last_notice_at' => (int) ($cache['last_notice_at'] ?? 0),
                'last_notified_tag' => (string) ($cache['last_notified_tag'] ?? ''),
            ];
            $this->writeUpdateNoticeCache($cachePath, $cache);
        }

        if (! ($cache['update_available'] ?? false)) {
            return;
        }

        $tag = (string) ($cache['remote_tag'] ?? '');
        if ($tag === '') {
            return;
        }

        $lastNotifiedTag = (string) ($cache['last_notified_tag'] ?? '');
        $lastNotice = (int) ($cache['last_notice_at'] ?? 0);
        if ($tag === $lastNotifiedTag && $now - $lastNotice < 86400) {
            return;
        }

        $this->ui()->warnLine(
            'jetty: update available ('.
                $tag.
                ') — run: '.
                $this->ui()->cmd('jetty update'),
        );
        $cache['last_notice_at'] = $now;
        $cache['last_notified_tag'] = $tag;
        $this->writeUpdateNoticeCache($cachePath, $cache);
    }

    private function jettyUpdateNoticeCachePath(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');
        if (! is_string($home) || $home === '') {
            return '';
        }

        return $home.
            \DIRECTORY_SEPARATOR.
            '.config'.
            \DIRECTORY_SEPARATOR.
            'jetty'.
            \DIRECTORY_SEPARATOR.
            'update-notice.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readUpdateNoticeCache(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeUpdateNoticeCache(string $path, array $data): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return;
            }
        }
        $tmp = $path.'.tmp.'.uniqid('', true);
        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        if (@file_put_contents($tmp, $json) === false) {
            return;
        }
        @rename($tmp, $path);
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
