<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use JettyCli\ApiClient;
use JettyCli\CliDiagnostics;
use JettyCli\CliUi;
use JettyCli\Config;
use JettyCli\GitHubPharRelease;

final class DoctorCommand
{
    public function __construct(
        private readonly CliUi $ui,
        private readonly ApiClient $client,
        private readonly Config $config,
    ) {}

    public function execute(): int
    {
        $u = $this->ui;
        $u->banner('doctor');

        $current = ApiClient::VERSION;
        $thisPath = $this->resolveRunningBinaryPath();

        // -- 1. Find all jetty installs --
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

        // -- 2. Version check --
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

        // -- 3. Offer to clean other installs --
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

        // -- 4. PATH check --
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

        // -- 5. API connectivity --
        $u->out('');
        $u->section('API connectivity');

        $apiUrl = $this->config->apiUrl;
        $token = $this->config->token;

        if (! is_string($apiUrl) || $apiUrl === '') {
            $u->warnLine('No API URL configured. Run: jetty setup');
        } else {
            $u->out('  '.$u->dim('API URL: ').$apiUrl);
            try {
                $client = new ApiClient($apiUrl, is_string($token) ? $token : '');
                $bootstrap = $client->request('GET', '/api/cli/bootstrap', null);
                if ($bootstrap['status'] === 200) {
                    $u->successLine('API reachable (HTTP 200).');
                } else {
                    $u->warnLine('API returned HTTP '.$bootstrap['status'].'.');
                }
            } catch (\Throwable $e) {
                $u->errorLine('API unreachable: '.$e->getMessage());
                $diag = CliDiagnostics::diagnose($e);
                if ($diag !== null) {
                    foreach ($diag['suggestions'] as $s) {
                        $u->out('    - '.$s);
                    }
                }
            }
        }

        // -- 6. Auth check --
        $u->out('');
        $u->section('Authentication');

        if (! is_string($token) || $token === '') {
            $u->warnLine('No auth token configured. Run: jetty login');
        } else {
            $u->out('  '.$u->dim('Token: ').$u->dim(substr($token, 0, 8).'...'));
            try {
                $client = new ApiClient(is_string($apiUrl) ? $apiUrl : '', $token);
                $res = $client->request('GET', '/api/tunnels', null);
                if ($res['status'] === 200) {
                    $u->successLine('Token valid (tunnel list returned HTTP 200).');
                } elseif ($res['status'] === 401) {
                    $u->errorLine('Token rejected (HTTP 401). Run: jetty login');
                } else {
                    $u->warnLine('Unexpected HTTP '.$res['status'].' when checking token.');
                }
            } catch (\Throwable $e) {
                $u->warnLine('Could not verify token: '.$e->getMessage());
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
    public function findAllJettyInstalls(): array
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
        // If it is the currently running binary, use our constant.
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
}
