<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Onboarding: find a Bridge that serves GET /api/cli/bootstrap, then browser login and auto-pick server.
 *
 * Defaults to https://usejetty.online (or https://{region}.usejetty.online with --region / JETTY_REGION).
 * Localhost Bridge URLs are skipped unless JETTY_ALLOW_LOCAL_BRIDGE=1.
 */
final class SetupWizard
{
    /**
     * `jetty login` — bootstrap Bridge discovery, then browser login with preferences.
     */
    public static function runLogin(?string $configFlag, ?string $region = null): void
    {
        $start = Config::resolve($configFlag, $region);
        $candidates = self::bootstrapBridgeCandidates($start, $region);
        if ($candidates === []) {
            throw new \InvalidArgumentException(
                'Cannot load servers: set JETTY_BRIDGE_URL or JETTY_ALLOW_LOCAL_BRIDGE=1 for a local Bridge.'
            );
        }
        $boot = self::tryResolveBootstrapFromCandidates($candidates);
        if ($boot !== null) {
            self::pickServerFromBootstrapAndPersist($boot, $region);
        }
        $apiBase = rtrim(Config::resolve($configFlag, $region)->apiUrl, '/');
        if ($apiBase === '') {
            throw new \InvalidArgumentException('No Bridge URL configured. Run jetty onboard or set JETTY_BRIDGE_URL.');
        }
        $ui = CliUi::default();
        $ui->out('Opening browser to sign in...');
        $result = self::waitForBrowserLogin($apiBase);
        $allowedConfigKeys = ['subdomain', 'tunnel_server'];
        $patch = ['token' => $result['token']];
        foreach ($result['config'] as $k => $v) {
            if (in_array($k, $allowedConfigKeys, true) && is_string($v) && trim($v) !== '') {
                $patch[$k] = trim($v);
            }
        }
        Config::writeUserConfigMerged($patch);
        $ui->out('');
        $ui->section('Login complete');
        $ui->out('  '.CliUi::default()->dim('Token').'       saved');
        foreach ($result['config'] as $k => $v) {
            if (is_string($v) && trim($v) !== '') {
                $ui->out('  '.CliUi::default()->dim($k).'  '.$v);
            }
        }
        $ui->out('');
        $ui->out('Try: jetty share 8000');
    }

    public static function runOnboarding(?string $configFlag, ?string $region = null): void
    {
        self::runBridgeAndServer(Config::resolve($configFlag, $region), true, $configFlag, $region);
    }

    public static function runSetupMenu(?string $configFlag): void
    {
        $start = Config::resolve($configFlag);
        self::printSummary($start);
        $ui = CliUi::default();
        while (true) {
            $ui->out('');
            $ui->section('Setup menu');
            $ui->out('  '.$ui->bold($ui->cyan('1')).'  Bridge & server — refresh server list from Bridge');
            $ui->out('  '.$ui->bold($ui->cyan('2')).'  API token only');
            $ui->out('  '.$ui->bold($ui->cyan('3')).'  Exit');
            $ui->out('');
            $ui->outRaw($ui->dim('Choice [1]: '));
            $line = self::readLine();
            if ($line === false) {
                return;
            }
            $line = trim($line);
            if ($line === '' || $line === '1') {
                self::runBridgeAndServer(Config::resolve($configFlag), false, $configFlag, null);

                return;
            }
            if ($line === '2') {
                self::runTokenOnly(Config::resolve($configFlag), $configFlag);

                continue;
            }
            if ($line === '3') {
                return;
            }
            $ui->warnLine('Please enter 1, 2, or 3.');
        }
    }

    private static function printSummary(Config $start): void
    {
        $ui = CliUi::default();
        $path = Config::userConfigPath() ?? '(could not resolve config path)';
        $ui->banner('Configuration');
        $ui->out('  '.$ui->dim('Config file').'  '.$path);
        if ($start->apiUrl !== '') {
            $ui->out('  '.$ui->dim('Bridge API').'   '.$ui->cyan($start->apiUrl));
        }
        $ui->out('  '.$ui->dim('Token').'       '.self::maskToken($start->token));
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
        $ui = CliUi::default();
        while (true) {
            $start = Config::resolve($configFlag);
            $base = rtrim($start->apiUrl, '/');
            $choice = self::promptTokenAuthChoice($start->token, $base !== '', true);
            if ($choice === 'skip') {
                self::applyTokenAuthChoice($choice, $base, $start->token, true, false);

                return;
            }
            self::applyTokenAuthChoice($choice, $base, $start->token, true, false);
            $ui->out('');
            if ($choice === 'browser') {
                $ui->out($ui->green('You are logged in. Token saved.'));
            } else {
                $ui->out($ui->green('Token saved.'));
            }
        }
    }

    /**
     * @return 'paste'|'browser'|'skip'
     */
    private static function promptTokenAuthChoice(string $existingTokenRaw, bool $hasApiBase, bool $allowSkip): string
    {
        $ui = CliUi::default();
        $hasExisting = trim($existingTokenRaw) !== '';

        while (true) {
            $ui->out('');
            $ui->out('How do you want to set your API token?');
            $ui->out('  '.$ui->bold($ui->cyan('1')).'  Paste token (Bridge → Tokens)');
            $ui->out('  '.$ui->bold($ui->cyan('2')).'  Open browser to sign in');
            if ($allowSkip) {
                $third = $hasExisting
                    ? 'Skip — keep current token ['.self::maskToken($existingTokenRaw).']'
                    : 'Skip — exit without saving a token';
                $ui->out('  '.$ui->bold($ui->cyan('3')).'  '.$third);
            }

            if ($allowSkip && $hasExisting) {
                $default = '3';
            } elseif ($hasApiBase) {
                $default = '2';
            } else {
                $default = '1';
            }

            $ui->outRaw($ui->dim('Choice ['.$default.']: '));
            $line = self::readLine();
            if ($line === false) {
                throw new \InvalidArgumentException('cancelled');
            }
            $line = trim($line);
            if ($line === '') {
                $line = $default;
            }
            if ($line === '1') {
                return 'paste';
            }
            if ($line === '2') {
                return 'browser';
            }
            if ($line === '3' && $allowSkip) {
                return 'skip';
            }
            $ui->warnLine($allowSkip ? 'Please enter 1, 2, or 3.' : 'Please enter 1 or 2.');
        }
    }

    /**
     * @param  'paste'|'browser'|'skip'  $choice
     */
    private static function applyTokenAuthChoice(string $choice, string $apiBase, string $previousToken, bool $isTokenOnlyMenu, bool $requireToken): void
    {
        if ($choice === 'skip') {
            if (trim($previousToken) !== '') {
                self::out('Token unchanged.');
            } else {
                self::out('No token saved.');
            }

            return;
        }
        if ($choice === 'browser') {
            if ($apiBase === '') {
                throw new \InvalidArgumentException(
                    $isTokenOnlyMenu
                        ? 'Run Bridge & server first (setup option 1), or paste a token (option 1).'
                        : 'Paste a token, or run onboarding first (jetty onboard / jetty setup).'
                );
            }
            self::out('Browser sign-in…');
            $tok = self::waitForBrowserToken($apiBase);
            Config::writeUserConfigMerged(['token' => $tok]);

            return;
        }

        self::outRaw('Paste token: ');
        $paste = self::readLine();
        if ($paste === false) {
            throw new \InvalidArgumentException('cancelled');
        }
        $tok = trim($paste);
        if ($tok === '') {
            if ($requireToken) {
                throw new \InvalidArgumentException(
                    'A token is required for onboarding. Paste a non-empty token or choose browser sign-in (2).'
                );
            }
            self::out('Empty token — nothing saved.');

            return;
        }
        Config::writeUserConfigMerged(['token' => $tok]);
    }

    private static function runBridgeAndServer(Config $start, bool $requireToken, ?string $configFlag, ?string $region): void
    {
        $candidates = self::bootstrapBridgeCandidates($start, $region);
        if ($candidates === []) {
            throw new \InvalidArgumentException(
                'Cannot load servers: set JETTY_BRIDGE_URL or JETTY_ALLOW_LOCAL_BRIDGE=1 for a local Bridge.'
            );
        }
        $boot = self::tryResolveBootstrapFromCandidates($candidates);
        if ($boot === null) {
            self::out('Could not load server list from any Bridge (GET /api/cli/bootstrap failed on every URL):');
            foreach ($candidates as $u) {
                self::out('  • '.$u);
            }
            self::out('Try: export JETTY_BRIDGE_URL=https://usejetty.online   # or your Bridge base URL');
            self::out('     jetty config clear api-url');
            self::out('Local Bridge dev: JETTY_ALLOW_LOCAL_BRIDGE=1 to allow http://127.0.0.1 / localhost as your Bridge URL.');

            throw new \InvalidArgumentException('No reachable Bridge for onboarding.');
        }
        self::pickServerFromBootstrapAndPersist($boot, $region);
        $apiRoot = rtrim(Config::resolve($configFlag, $region)->apiUrl, '/');
        self::out('');
        self::out('Using Bridge API: '.$apiRoot);
        if ($requireToken) {
            self::browserLoginFirstIfNeeded($apiRoot, $configFlag, $region);
        }
        if ($requireToken) {
            if (trim(Config::resolve($configFlag, $region)->token) === '') {
                self::promptAndSaveToken(Config::resolve($configFlag, $region), true, $configFlag, $region);
            } else {
                self::out('API token already saved — skipping sign-in.');
            }
        } else {
            self::promptAndSaveToken(Config::resolve($configFlag, $region), false, $configFlag, $region);
        }
        self::out('');
        self::out('Onboarding finished. Try: jetty list');
    }

    /**
     * First-run: save token from JETTY_ONBOARD_TOKEN, or open browser against Bridge (unless JETTY_ONBOARD_USE_BROWSER=0).
     */
    private static function browserLoginFirstIfNeeded(string $bridgeBase, ?string $configFlag, ?string $region): void
    {
        $fromEnv = self::trimmedEnv('JETTY_ONBOARD_TOKEN');
        if ($fromEnv !== null && $fromEnv !== '') {
            Config::writeUserConfigMerged(['token' => $fromEnv]);

            return;
        }
        if (trim(Config::resolve($configFlag, $region)->token) !== '') {
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
     * Ordered Bridge roots to try for GET /api/cli/bootstrap (deduped).
     * Defaults include https://usejetty.online (or regional). Localhost URLs are skipped unless
     * JETTY_ALLOW_LOCAL_BRIDGE=1.
     *
     * @return list<string>
     */
    private static function bootstrapBridgeCandidates(Config $start, ?string $region): array
    {
        $seen = [];
        $out = [];
        $add = function (string $u) use (&$seen, &$out): void {
            $u = trim($u);
            if ($u === '') {
                return;
            }
            if (DefaultBridge::isProbablyLocalBridge($u) && ! DefaultBridge::allowLocalBridgeCandidates()) {
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

        $r = $region !== null ? trim($region) : '';
        if ($r === '' && is_string(getenv('JETTY_REGION')) && trim((string) getenv('JETTY_REGION')) !== '') {
            $r = trim((string) getenv('JETTY_REGION'));
        }
        $add(DefaultBridge::baseUrl($r !== '' ? $r : null));

        foreach (['JETTY_CLI_LOCAL_URL', 'JETTY_CLI_DEV_URL'] as $k) {
            $v = getenv($k);
            if (is_string($v) && trim($v) !== '') {
                $add($v);
            }
        }
        $api = trim($start->apiUrl);
        if ($api !== '') {
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
     * Apply server choice from bootstrap (persists server name + Bridge API base) before browser login so OAuth/token match.
     * Auto-selects by region / default Bridge URL (usejetty.online), not a manual URL prompt.
     */
    private static function pickServerFromBootstrapAndPersist(array $boot, ?string $region): void
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

        $sel = self::autoPickServerRow($clean, $region);
        self::out('Server for this CLI: '.$sel['name'].' — '.$sel['url']);
        self::persistServerChoice($sel['name'], $sel['url']);
    }

    /**
     * @param  list<array{name: string, url: string}>  $clean
     * @return array{name: string, url: string}
     */
    private static function autoPickServerRow(array $clean, ?string $region): array
    {
        if (count($clean) === 1) {
            return $clean[0];
        }

        $r = $region !== null ? trim($region) : '';
        if ($r === '' && is_string(getenv('JETTY_REGION')) && trim((string) getenv('JETTY_REGION')) !== '') {
            $r = trim((string) getenv('JETTY_REGION'));
        }

        $wantUrl = DefaultBridge::baseUrl($r !== '' ? $r : null);
        foreach ($clean as $row) {
            if (rtrim($row['url'], '/') === $wantUrl) {
                return $row;
            }
        }
        if ($r !== '') {
            $rl = strtolower($r);
            foreach ($clean as $row) {
                if (strtolower($row['name']) === $rl) {
                    return $row;
                }
            }
        }
        foreach ($clean as $row) {
            if (str_contains(strtolower($row['url']), 'usejetty.online')) {
                return $row;
            }
        }

        return $clean[0];
    }

    private static function promptAndSaveToken(Config $start, bool $requireToken, ?string $configFlag, ?string $region = null): void
    {
        $existing = trim($start->token);
        $fromEnv = self::trimmedEnv('JETTY_ONBOARD_TOKEN');
        if ($fromEnv !== null && $fromEnv !== '') {
            Config::writeUserConfigMerged(['token' => $fromEnv]);

            return;
        }

        $base = rtrim(Config::resolve($configFlag, $region)->apiUrl, '/');
        $allowSkip = ! $requireToken;
        $choice = self::promptTokenAuthChoice($start->token, $base !== '', $allowSkip);
        self::applyTokenAuthChoice($choice, $base, $start->token, false, $requireToken);
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

    /**
     * POST /api/cli/auth/session, open browser, poll until token + config preferences.
     *
     * @return array{token: string, config: array<string, string>}
     */
    private static function waitForBrowserLogin(string $apiBase): array
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
                $config = [];
                if (isset($poll['config']) && is_array($poll['config'])) {
                    foreach ($poll['config'] as $k => $v) {
                        if (is_string($k) && is_string($v)) {
                            $config[$k] = $v;
                        }
                    }
                }

                return ['token' => $poll['token'], 'config' => $config];
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
        CliUi::default()->out($s);
    }

    private static function outRaw(string $s): void
    {
        CliUi::default()->outRaw($s);
    }
}
