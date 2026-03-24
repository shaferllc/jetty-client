<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Interactive Bridge URL, server list, and token (matches Go jetty onboard / jetty setup).
 */
final class SetupWizard
{
    private const DEFAULT_API = 'http://127.0.0.1:8000';

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
            self::out('  1) Bridge URL & server');
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
                if ($base === '' || $base === self::DEFAULT_API) {
                    throw new \InvalidArgumentException('Set Bridge URL first (option 1), or paste a token.');
                }
                self::out('Opening browser to sign in…');
                $tok = self::waitForBrowserToken($base);
                Config::writeUserConfigMerged(['token' => $tok]);
                $p = Config::userConfigPath() ?? '(unknown)';
                self::out('Saved token to '.$p);

                return;
            }
            self::out('Token unchanged.');

            return;
        }
        Config::writeUserConfigMerged(['token' => $tok]);
        $p = Config::userConfigPath() ?? '(unknown)';
        self::out('Saved token to '.$p);
    }

    private static function runBridgeAndServer(Config $start, bool $requireToken, ?string $configFlag): void
    {
        $def = '';
        if (trim($start->apiUrl) !== '' && $start->apiUrl !== self::DEFAULT_API) {
            $def = $start->apiUrl;
        }
        $prompt = 'Bridge base URL (e.g. https://app.example.com)';
        if ($def !== '') {
            $prompt .= ' ['.$def.']';
        }
        self::outRaw($prompt.': ');
        $line = self::readLine();
        if ($line === false) {
            throw new \InvalidArgumentException('cancelled');
        }
        $raw = trim($line);
        if ($raw === '') {
            $raw = $def;
        }
        if ($raw === '') {
            throw new \InvalidArgumentException('Bridge URL is required');
        }
        $bridgeBase = self::normalizeBridgeInput($raw);

        try {
            $boot = self::fetchBootstrap($bridgeBase);
        } catch (\Throwable $e) {
            self::out('Could not load server list ('.$e->getMessage().').');
            self::outRaw('Enter API base URL manually (same as Bridge, e.g. https://app.example.com): ');
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
            $pManual = Config::userConfigPath() ?? '(unknown)';
            self::out('Saved connection settings to '.$pManual);
            self::promptAndSaveToken($start, $requireToken, $configFlag);

            return;
        }

        $servers = $boot['servers'] ?? [];
        if ($servers === []) {
            throw new \InvalidArgumentException('Bridge returned no servers (check jetty.cli_servers / JETTY_CLI_SERVERS)');
        }
        self::out('');
        self::out($boot['app_name'].' — remote servers:');
        foreach ($servers as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            self::out('  '.($idx + 1).') '.$name.'  '.$url);
        }
        self::out('');
        self::outRaw('Select server [1]: ');
        $choiceLine = self::readLine();
        if ($choiceLine === false) {
            throw new \InvalidArgumentException('cancelled');
        }
        $choice = trim($choiceLine);
        if ($choice === '') {
            $choice = '1';
        }
        $idx = (int) $choice;
        if ($idx < 1 || $idx > count($servers)) {
            throw new \InvalidArgumentException('invalid choice (use 1–'.count($servers).')');
        }
        /** @var array<string, mixed> $sel */
        $sel = $servers[$idx - 1];
        $name = trim((string) ($sel['name'] ?? ''));
        $apiBase = rtrim(trim((string) ($sel['url'] ?? '')), '/');
        if ($apiBase === '') {
            throw new \InvalidArgumentException('selected server has empty url');
        }
        self::persistServerChoice($name, $apiBase);
        $p = Config::userConfigPath() ?? '(unknown)';
        self::out('Saved connection settings to '.$p);
        self::promptAndSaveToken($start, $requireToken, $configFlag);
    }

    private static function promptAndSaveToken(Config $start, bool $requireToken, ?string $configFlag): void
    {
        $existing = trim($start->token);
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
            $base = rtrim(Config::resolve($configFlag)->apiUrl, '/');
            if ($base === '' || $base === self::DEFAULT_API) {
                throw new \InvalidArgumentException('Paste a token, or configure a Bridge URL first.');
            }
            self::out('Opening browser to sign in…');
            $tok = self::waitForBrowserToken($base);
        }
        Config::writeUserConfigMerged(['token' => $tok]);
        $p = Config::userConfigPath() ?? '(unknown)';
        self::out('Saved token to '.$p);
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
        curl_close($ch);
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
        curl_close($ch);
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
    }
}
