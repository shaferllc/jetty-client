<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use Composer\InstalledVersions;
use JettyCli\ApiClient;
use JettyCli\CliUi;
use JettyCli\GitHubPharRelease;
use JettyCli\UpdateConfig;

final class HelpRenderer
{
    public function __construct(
        private readonly CliUi $ui,
    ) {}

    public function execute(array $rest): int
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
        $this->ui->out('');
        $this->ui->mutedLine(
            'Share options: '.$this->ui->cmd('jetty share --help'),
        );
        if ($advanced) {
            $this->ui->out('');
            $this->printAdvancedHelpStyled();
        } else {
            $this->ui->out('');
            $this->ui->infoLine(
                'Advanced (config & env): '.
                    $this->ui->cmd('jetty help --advanced'),
            );
        }

        return 0;
    }

    public function printStyledMainHelp(): void
    {
        $u = $this->ui;
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
                'jetty config set|get|clear|wizard ...',
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

    public function printAdvancedHelpStyled(): void
    {
        $u = $this->ui;
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

    public function helpText(): string
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

        Install: composer require jetty/client  (or: composer global require jetty/client — put Composer's global vendor/bin on PATH)
          Same config (~/.config/jetty/config.json) for PHAR and Composer. Releases: one "Release CLI" workflow ships the PHAR on GitHub and the same version to Packagist — bump once, not twice.
          Day to day: pick one binary (PHAR or Composer); jetty update upgrades the copy you run. Use jetty doctor to find and clean up duplicate installs.

        TXT;
    }

    public function helpTextAdvanced(): string
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
          JETTY_SKIP_UPDATE_NOTICE=1 Suppress "update available" hint after commands

          Bridge app (.env): see .env.example and config/jetty.php (tunnel host, edge, plans, Telegram, …).

        TXT;
    }

    /**
     * After successful commands, optionally print a one-line notice when a newer PHAR/Packagist release
     * exists (GitHub cli-v*). Cached: at most one GitHub check per 24h; at most one notice per 24h for the
     * same release tag (immediate notice when the tag changes). Opt out: JETTY_SKIP_UPDATE_NOTICE=1.
     *
     * @param  callable(): ?string  $localPharUpdateUrl
     * @param  callable(): string  $releasesRepo
     * @param  callable(): ?string  $githubTokenForReleases
     */
    public function maybePrintUpdateNotice(
        string $command,
        int $exitCode,
        callable $localPharUpdateUrl,
        callable $releasesRepo,
        callable $githubTokenForReleases,
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
        if ($pharPath !== '' && $localPharUpdateUrl() !== null) {
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
            $repo = $releasesRepo();
            $token = $githubTokenForReleases();
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

        $this->ui->warnLine(
            'jetty: update available ('.
                $tag.
                ') — run: '.
                $this->ui->cmd('jetty update'),
        );
        $cache['last_notice_at'] = $now;
        $cache['last_notified_tag'] = $tag;
        $this->writeUpdateNoticeCache($cachePath, $cache);
    }

    public function jettyUpdateNoticeCachePath(): string
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
    public function readUpdateNoticeCache(string $path): ?array
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
    public function writeUpdateNoticeCache(string $path, array $data): void
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
}
