<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use JettyCli\ApiClient;
use JettyCli\CliUi;
use JettyCli\Config;
use JettyCli\GitHubPharRelease;
use JettyCli\UpdateConfig;

final class UpdateCommand
{
    public function __construct(
        private readonly CliUi $ui,
        private readonly Config $config,
    ) {}

    /**
     * @param  list<string>  $args
     */
    public function executeSelfUpdate(array $args): int
    {
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            return $this->updatePharInPlace($args, $pharPath);
        }

        // Not running from a PHAR (e.g. invoked via a Composer dev clone or
        // vendor/bin wrapper). The supported install path is the PHAR placed
        // by install.sh, so update that on disk if we can find it.
        $installedPhar = self::findInstalledPhar();
        if ($installedPhar !== null) {
            $this->stderr(
                'Updating PHAR at '.$installedPhar.' (the running binary is not a PHAR).',
            );

            return $this->updatePharInPlace($args, $installedPhar);
        }

        $this->stderr(
            'jetty update: this is not a PHAR install. The CLI is distributed as a PHAR via install.sh — Composer is not a supported install path.',
        );
        $this->stderr('');
        $this->stderr('Reinstall:');
        $this->stderr('  curl -sSf https://usejetty.online/install.sh | sh');
        $this->stderr('');
        $this->stderr('Override the PHAR path with JETTY_PHAR_PATH if you installed it somewhere other than ~/.local/bin/jetty.');

        return 1;
    }

    /**
     * Locate the PHAR installed by install.sh. Returns the first existing
     * candidate, or null if none are found.
     */
    private static function findInstalledPhar(): ?string
    {
        $candidates = [];

        $envOverride = getenv('JETTY_PHAR_PATH');
        if (is_string($envOverride) && trim($envOverride) !== '') {
            $candidates[] = trim($envOverride);
        }

        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            $home = (string) ($_SERVER['HOME'] ?? '');
        }
        if ($home !== '') {
            $candidates[] = $home.\DIRECTORY_SEPARATOR.'.local'.\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'jetty';
        }

        $candidates[] = \DIRECTORY_SEPARATOR.'usr'.\DIRECTORY_SEPARATOR.'local'.\DIRECTORY_SEPARATOR.'bin'.\DIRECTORY_SEPARATOR.'jetty';

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Add jetty/client to the Composer project in the current working directory (e.g. from a global `jetty` install).
     *
     * @param  list<string>  $args
     */
    public function executeInstallClient(array $args): int
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
    public function updatePharInPlace(array $args, string $pharPath): int
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
                $latest['checksum_url'] ?? null,
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

    public function localPharUpdateUrl(): ?string
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
    public function applyPharDownload(
        string $pharPath,
        string $url,
        ?string $githubToken,
        ?string $checksumUrl = null,
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

        // Verify SHA256 checksum if a sidecar file is available.
        try {
            $verified = GitHubPharRelease::verifyChecksum($tmp, $checksumUrl, $githubToken);
            if ($verified) {
                $this->stderr('  Checksum verified (SHA256).');
            }
        } catch (\RuntimeException $e) {
            @unlink($tmp);
            throw $e;
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

    public function emitPostUpdateConfigTip(): void
    {
        $this->stdout(
            'Saved config (~/.config/jetty/config.json) is unchanged; run jetty setup only if you need a new Bridge URL or token.',
        );
    }

    public function releasesRepo(): string
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

    public function githubTokenForReleases(): ?string
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

    public function unknownCommandUpgradeHint(string $command): string
    {
        $newerOnly = ['setup', 'onboard', 'login', 'logout', 'reset', 'update'];
        if (! in_array($command, $newerOnly, true)) {
            return '';
        }

        return "\n\n".
            'This build of jetty is too old for `jetty '.
            $command.
            '`. '.
            'Upgrade: reinstall the PHAR via `curl -sSf https://usejetty.online/install.sh | sh`, or run `jetty update` from a current PHAR. '.
            'You can configure this version with `jetty config set api-url …` and `jetty config set token …`.';
    }

    private function stdout(string $s): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }

    private function stderr(string $s): void
    {
        fwrite(\STDERR, $s.\PHP_EOL);
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
}
