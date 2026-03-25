<?php

declare(strict_types=1);

namespace JettyCli;

use function Laravel\Prompts\select;

/**
 * Onboarding: find a Bridge that serves GET /api/cli/bootstrap, then browser login and server pick (Prompts).
 *
 * Bridge URL candidates (first success wins): JETTY_ONBOARD_BRIDGE_URL / JETTY_BRIDGE_URL, APP_URL from
 * nearest .env (cwd), JETTY_CLI_LOCAL_URL, saved api_url, then JETTY_CLI_BOOTSTRAP_FALLBACKS. That way a
 * stale production api_url in ~/.config/jetty/config.json does not block local dev when you run onboard
 * from a Jetty checkout.
 */
final class SetupWizard
{
    public static function runOnboarding(?string $configFlag): void
    {
        self::runBridgeAndServer(Config::resolve($configFlag), true, $configFlag);
    }

    public static function runSetupMenu(?string $configFlag): void
    {
        $start = Config::resolve($configFlag);
        self::printSummary($start);
        while (true) {
            self::out('');
            self::out('What would you like to change?');
            self::out('  1) Server (reload from Bridge, set api_url)');
            self::out('  2) API token only');
            self::out('  3) Exit');
            self::outRaw('Choice [1]: ');
            $line = self::readLine();
            if ($line === false) {
                return;
            }
            $line = trim($line);
            if ($line === '' || $line === '1') {
                self::runBridgeAndServer(Config::resolve($configFlag), false, $configFlag);

                return;
            }
            if ($line === '2') {
                self::runTokenOnly(Config::resolve($configFlag), $configFlag);

                return;
            }
            if ($line === '3') {
                return;
            }
            self::out('Please enter 1, 2, or 3.');
        }
    }

    private static function printSummary(Config $start): void
    {
        $path = Config::userConfigPath() ?? '(could not resolve config path)';
        self::out('Jetty config file: '.$path);
        if ($start->apiUrl !== '') {
            self::out('  api_url: '.$start->apiUrl);
        }
        self::out('  token: '.self::maskToken($start->token));
    }

    private static function maskToken(string $t): string
    {
        $t = trim($t);
        if ($t === '') {
            return '(not set)';
        }
        if (strlen($t) <= 8) {
            return '****';
        }

        return substr($t, 0, 4).'…'.substr($t, -4);
    }

    private static function runTokenOnly(Config $start, ?string $configFlag): void
    {
        self::outRaw('Paste API token (Bridge → Tokens), or press Enter to sign in in your browser ['.self::maskToken($start->token).']: ');
        $line = self::readLine();
        if ($line === false) {
            throw new \InvalidArgumentException('cancelled');
        }
        $tok = trim($line);
        if ($tok === '') {
            if (trim($start->token) === '') {
                $base = rtrim(Config::resolve($configFlag)->apiUrl, '/');
                if ($base === '' || $base === Config::PLACEHOLDER_API_URL) {
                    throw new \InvalidArgumentException('Pick a server first (option 1), or paste a token.');
                }
                self::out('Browser sign-in…');
                $tok = self::waitForBrowserToken($base);
                Config::writeUserConfigMerged(['token' => $tok]);

                return;
            }
            self::out('Token unchanged.');

            return;
        }
        Config::writeUserConfigMerged(['token' => $tok]);
    }

    private static function runBridgeAndServer(Config $start, bool $requireToken, ?string $configFlag): void
    {
        $candidates = self::bootstrapBridgeCandidates($start);
        if ($candidates === []) {
            throw new \InvalidArgumentException(
                'Cannot load servers: set JETTY_BRIDGE_URL, run from a Jetty checkout (APP_URL in .env), '.
                'or set api_url in ~/.config/jetty/config.json.'
            );
        }
        $boot = self::tryResolveBootstrapFromCandidates($candidates);
        if ($boot === null) {
            self::out('Could not load server list from any Bridge (GET /api/cli/bootstrap failed on every URL):');
            foreach ($candidates as $u) {
                self::out('  • '.$u);
            }
            self::out('Common cause: saved api_url points at production (old deploy or wrong app) while you mean local, or the reverse.');
            self::out('Try: cd to your Jetty repo (APP_URL is tried before saved api_url), or:');
            self::out('  export JETTY_BRIDGE_URL=https://jetty.test');
            self::out('  export JETTY_CLI_BOOTSTRAP_FALLBACKS=https://your-production.example   # optional extra URLs to try');
            self::out('  jetty config clear api-url');
            self::outRaw('Enter API base URL manually (Bridge root — local e.g. https://jetty.test, or production): ');
            $line2 = self::readLine();
            if ($line2 === false) {
                throw new \InvalidArgumentException('cancelled');
            }
            $apiBase = rtrim(trim($line2), '/');
            if ($apiBase === '') {
                throw new \InvalidArgumentException('API base URL is required');
            }
            if (! str_starts_with($apiBase, 'http://') && ! str_starts_with($apiBase, 'https://')) {
                $apiBase = 'https://'.$apiBase;
            }
            self::persistApiChoice($apiBase);
            self::promptAndSaveToken(Config::resolve($configFlag), $requireToken, $configFlag);

            return;
        }
        self::pickServerFromBootstrapAndPersist($boot);
        $apiRoot = rtrim(Config::resolve($configFlag)->apiUrl, '/');
        if ($requireToken) {
            self::browserLoginFirstIfNeeded($apiRoot, $configFlag);
        }
        if ($requireToken) {
            if (trim(Config::resolve($configFlag)->token) === '') {
                self::promptAndSaveToken(Config::resolve($configFlag), true, $configFlag);
            }
        } else {
            self::promptAndSaveToken(Config::resolve($configFlag), false, $configFlag);
        }
    }

    /**
     * First-run: save token from JETTY_ONBOARD_TOKEN, or open browser against Bridge (unless JETTY_ONBOARD_USE_BROWSER=0).
     */
    private static function browserLoginFirstIfNeeded(string $bridgeBase, ?string $configFlag): void
    {
        $fromEnv = self::trimmedEnv('JETTY_ONBOARD_TOKEN');
        if ($fromEnv !== null && $fromEnv !== '') {
            Config::writeUserConfigMerged(['token' => $fromEnv]);

            return;
        }
        if (trim(Config::resolve($configFlag)->token) !== '') {
            return;
        }
        if (self::envIsFalsy('JETTY_ONBOARD_USE_BROWSER')) {
            return;
        }
        self::out('Browser sign-in…');
        $tok = self::waitForBrowserToken($bridgeBase);
        Config::writeUserConfigMerged(['token' => $tok]);
    }

    /**
     * Ordered Bridge roots to try for GET /api/cli/bootstrap (deduped). Local checkout (.env APP_URL)
     * is tried before saved ~/.config api_url so prod entries do not break local onboarding.
     *
     * @return list<string>
     */
    private static function bootstrapBridgeCandidates(Config $start): array
    {
        $seen = [];
        $out = [];
        $add = function (string $u) use (&$seen, &$out): void {
            $u = trim($u);
            if ($u === '' || $u === Config::PLACEHOLDER_API_URL) {
                return;
            }
            try {
                $n = self::normalizeBridgeInput($u);
            } catch (\InvalidArgumentException) {
                return;
            }
            $k = strtolower($n);
            if (isset($seen[$k])) {
                return;
            }
            $seen[$k] = true;
            $out[] = $n;
        };

        foreach (['JETTY_ONBOARD_BRIDGE_URL', 'JETTY_BRIDGE_URL'] as $k) {
            $v = getenv($k);
            if (is_string($v) && trim($v) !== '') {
                $add($v);
            }
        }
        $dot = Config::appUrlFromNearestDotEnv();
        if ($dot !== null) {
            $add($dot);
        }
        foreach (['JETTY_CLI_LOCAL_URL', 'JETTY_CLI_DEV_URL'] as $k) {
            $v = getenv($k);
            if (is_string($v) && trim($v) !== '') {
                $add($v);
            }
        }
        $api = trim($start->apiUrl);
        if ($api !== '' && $api !== Config::PLACEHOLDER_API_URL) {
            $add($api);
        }
        $fb = getenv('JETTY_CLI_BOOTSTRAP_FALLBACKS');
        if (is_string($fb) && trim($fb) !== '') {
            foreach (explode(',', $fb) as $part) {
                $add(trim($part));
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $candidates
     * @return ?array<string, mixed>
     */
    private static function tryResolveBootstrapFromCandidates(array $candidates): ?array
    {
        foreach ($candidates as $base) {
            try {
                return self::fetchBootstrap($base);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Apply server choice from bootstrap (sets api_url) before browser login so OAuth/token match the selected Bridge.
     */
    private static function pickServerFromBootstrapAndPersist(array $boot): void
    {
        $servers = $boot['servers'] ?? [];
        /** @var list<array{name: string, url: string}> $clean */
        $clean = [];
        foreach ($servers as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($name === '' || $url === '') {
                continue;
            }
            $clean[] = ['name' => $name, 'url' => rtrim($url, '/')];
        }
        if ($clean === []) {
            throw new \InvalidArgumentException('Bridge returned no servers (check jetty.cli_servers / JETTY_CLI_SERVERS)');
        }

        $autoServer = self::envIsTruthy('JETTY_ONBOARD_AUTO_SERVER');
        if ($autoServer) {
            $sel = $clean[0];
        } else {
            $options = [];
            foreach ($clean as $i => $row) {
                $options[$i + 1] = $row['name'].' — '.$row['url'];
            }
            $picked = select(
                label: 'Server for this CLI',
                options: $options,
                default: 1,
            );
            $idx = (int) $picked;
            if ($idx < 1 || $idx > count($clean)) {
                throw new \InvalidArgumentException('invalid server choice');
            }
            $sel = $clean[$idx - 1];
        }

        self::persistServerChoice($sel['name'], $sel['url']);
    }

    private static function promptAndSaveToken(Config $start, bool $requireToken, ?string $configFlag): void
    {
        $existing = trim($start->token);
        $fromEnv = self::trimmedEnv('JETTY_ONBOARD_TOKEN');
        if ($fromEnv !== null && $fromEnv !== '') {
            Config::writeUserConfigMerged(['token' => $fromEnv]);

            return;
        }

        $base = rtrim(Config::resolve($configFlag)->apiUrl, '/');
        $hint = '(required — paste token or press Enter for browser)';
        if (! $requireToken && $existing !== '') {
            $hint = '['.self::maskToken($existing).' to keep, or Enter for browser]';
        }
        self::out('');
        self::outRaw('API token (Bridge → Tokens) '.$hint.': ');
        $line = self::readLine();
        if ($line === false) {
            throw new \InvalidArgumentException('cancelled');
        }
        $tok = trim($line);
        if ($tok === '') {
            if (! $requireToken && $existing !== '') {
                self::out('Token unchanged.');

                return;
            }
            if ($base === '' || $base === Config::PLACEHOLDER_API_URL) {
                throw new \InvalidArgumentException('Paste a token, or pick a server first (jetty onboard / jetty setup).');
            }
            self::out('Browser sign-in…');
            $tok = self::waitForBrowserToken($base);
        }
        Config::writeUserConfigMerged(['token' => $tok]);
    }

    private static function trimmedEnv(string $name): ?string
    {
        $v = getenv($name);
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }

    private static function envIsTruthy(string $name): bool
    {
        $v = getenv($name);
        if (! is_string($v)) {
            return false;
        }

        return match (strtolower(trim($v))) {
            '1', 'true', 'yes', 'on' => true,
            default => false,
        };
    }

    private static function envIsFalsy(string $name): bool
    {
        $v = getenv($name);
        if (! is_string($v)) {
            return false;
        }

        return match (strtolower(trim($v))) {
            '0', 'false', 'no', 'off' => true,
            default => false,
        };
    }

    /**
     * POST /api/cli/auth/session, open browser, poll until token.
     */
    private static function waitForBrowserToken(string $apiBase): string
    {
        $sessionUrl = rtrim($apiBase, '/').'/api/cli/auth/session';
        $raw = self::curlPostJson($sessionUrl, '{}');
        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $pollUrl = isset($data['poll_url']) && is_string($data['poll_url']) ? $data['poll_url'] : '';
        $authorizeUrl = isset($data['authorize_url']) && is_string($data['authorize_url']) ? $data['authorize_url'] : '';
        if ($pollUrl === '' || $authorizeUrl === '') {
            throw new \RuntimeException('Invalid auth session response from Bridge');
        }
        self::openBrowser($authorizeUrl);
        $deadline = time() + 600;
        while (time() < $deadline) {
            usleep(1_500_000);
            $pollRaw = self::curlGet($pollUrl);
            /** @var array<string, mixed> $poll */
            $poll = json_decode($pollRaw, true, 512, JSON_THROW_ON_ERROR);
            $st = isset($poll['status']) && is_string($poll['status']) ? $poll['status'] : '';
            if ($st === 'success' && isset($poll['token']) && is_string($poll['token']) && $poll['token'] !== '') {
                return $poll['token'];
            }
            if ($st === 'expired' || $st === 'invalid' || $st === 'rate_limited') {
                throw new \RuntimeException('Browser login failed: '.$st);
            }
        }

        throw new \RuntimeException('Timed out waiting for browser login (10 minutes)');
    }

    private static function openBrowser(string $url): void
    {
        $u = escapeshellarg($url);
        if (\PHP_OS_FAMILY === 'Darwin') {
            @exec('open '.$u.' 2>/dev/null');
        } elseif (\PHP_OS_FAMILY === 'Linux') {
            @exec('xdg-open '.$u.' 2>/dev/null');
        } elseif (\PHP_OS_FAMILY === 'Windows') {
            @pclose(@popen('start "" '.$u, 'r'));
        }
    }

    private static function curlPostJson(string $url, string $body): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'User-Agent: jetty-cli-php',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($resp === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException('HTTP '.$code.' starting auth session');
        }

        return (string) $resp;
    }

    private static function persistApiChoice(string $apiBase): void
    {
        Config::clearUserConfigKey('server');
        Config::writeUserConfigMerged(['api_url' => rtrim($apiBase, '/')]);
    }

    private static function persistServerChoice(string $name, string $apiBase): void
    {
        Config::writeUserConfigMerged([
            'api_url' => rtrim($apiBase, '/'),
            'server' => $name,
        ]);
    }

    private static function normalizeBridgeInput(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') {
            throw new \InvalidArgumentException('empty');
        }
        if (! str_contains($s, '://')) {
            $s = 'https://'.$s;
        }
        $parts = parse_url($s);
        if (! is_array($parts) || empty($parts['host'])) {
            throw new \InvalidArgumentException('invalid URL');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException('URL must be http or https');
        }

        return rtrim($scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''), '/');
    }

    /**
     * @return array{app_name: string, servers: list<array{name: string, url: string}>}
     */
    private static function fetchBootstrap(string $bridgeBase): array
    {
        $url = rtrim($bridgeBase, '/').'/api/cli/bootstrap';
        $raw = self::curlGet($url);
        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $appName = (string) ($data['app_name'] ?? 'Jetty');
        $serversRaw = $data['servers'] ?? [];
        $servers = [];
        if (is_array($serversRaw)) {
            foreach ($serversRaw as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $name = trim((string) ($row['name'] ?? ''));
                $u = trim((string) ($row['url'] ?? ''));
                if ($name === '' || $u === '') {
                    continue;
                }
                $servers[] = ['name' => $name, 'url' => rtrim($u, '/')];
            }
        }

        return ['app_name' => $appName, 'servers' => $servers];
    }

    private static function curlGet(string $url): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: jetty-cli-php',
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false || $code < 200 || $code >= 300) {
            throw new \RuntimeException('HTTP '.$code);
        }

        return (string) $body;
    }

    private static function readLine(): string|false
    {
        $line = fgets(\STDIN);

        return $line === false ? false : rtrim($line, "\r\n");
    }

    private static function out(string $s): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }

    private static function outRaw(string $s): void
    {
        fwrite(\STDOUT, $s);
        fflush(\STDOUT);
    }
}
