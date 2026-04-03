<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Best-effort local dev upstream detection: PHP stacks (Laravel, Herd, Valet, DDEV, Sail, Lando),
 * containers (Docker Compose, wp-env), and JS tooling (Vite, Next, Nuxt, Astro, etc.).
 *
 * Order matters: more specific PHP / project URL checks run before generic 127.0.0.1 port probes.
 */
final class LocalDevDetector
{
    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    public static function detect(string $startDir): ?array
    {
        $startDir = realpath($startDir) ?: $startDir;

        $detectors = [
            fn () => self::detectExplicitUpstreamEnv(),
            fn () => self::detectLaravelAppUrl($startDir),
            fn () => self::detectBedrockWordPressAppUrl($startDir),
            fn () => self::detectHerdLinks($startDir),
            fn () => self::detectValetLinks($startDir),
            fn () => self::detectDdev($startDir),
            fn () => self::detectLando($startDir),
            fn () => self::detectSymfonyLocal($startDir),
            fn () => self::detectLaravelSailCompose($startDir),
            fn () => self::detectDockerComposePublishedPort($startDir),
            fn () => self::detectWpEnv($startDir),
            fn () => self::detectCraftNitro($startDir),
            fn () => self::detectViteConfigPort($startDir),
            fn () => self::detectNuxtConfigPort($startDir),
            fn () => self::detectAstroConfigPort($startDir),
            fn () => self::detectSvelteConfigPort($startDir),
            fn () => self::detectNextJsDefault($startDir),
            fn () => self::detectRemixOrTanStackStart($startDir),
            fn () => self::detectGatsbyDefault($startDir),
            fn () => self::detectDevcontainerForwardPort($startDir),
            fn () => self::detectCaddyfilePort($startDir),
            fn () => self::detectGenericDotEnvPort($startDir),
            fn () => self::detectPackageJsonDevHeuristic($startDir),
            fn () => self::detectMampStylePath($startDir),
            fn () => self::detectPhpStormPublicUrl($startDir),
        ];

        foreach ($detectors as $fn) {
            $r = $fn();
            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Override for any stack (e.g. PHP Monitor, custom proxy) when the project has no detectable files.
     * Example: export JETTY_SHARE_UPSTREAM=http://127.0.0.1:8080
     *
     * Does not require the port to accept TCP yet (unlike file-based detection), so sharing can start before the local server.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectExplicitUpstreamEnv(): ?array
    {
        $v = getenv('JETTY_SHARE_UPSTREAM');
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (! str_contains($v, '://')) {
            $v = 'http://'.$v;
        }
        $p = parse_url($v);
        if (! is_array($p) || empty($p['host'])) {
            return null;
        }
        $host = (string) $p['host'];
        $scheme = strtolower((string) ($p['scheme'] ?? 'http'));
        $port = isset($p['port']) ? (int) $p['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
            'hint' => 'Local upstream: '.$host.':'.$port.' (JETTY_SHARE_UPSTREAM).',
        ];
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectLaravelAppUrl(string $startDir): ?array
    {
        $root = self::findAppRootWithFile($startDir, 'artisan');
        if ($root === null) {
            return null;
        }

        return self::upstreamFromDotEnvKey($root.'/.env', 'APP_URL', 'Laravel .env APP_URL');
    }

    /**
     * Roots Bedrock-style WordPress (web/wp-config.php + .env APP_URL).
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectBedrockWordPressAppUrl(string $startDir): ?array
    {
        $dir = $startDir;
        for ($i = 0; $i < 32; $i++) {
            if (is_file($dir.\DIRECTORY_SEPARATOR.'web'.\DIRECTORY_SEPARATOR.'wp-config.php')) {
                return self::upstreamFromDotEnvKey($dir.'/.env', 'APP_URL', 'Bedrock / WordPress .env APP_URL');
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Laravel Herd (macOS) — CLI similar to Valet.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectHerdLinks(string $startDir): ?array
    {
        if (self::runShellLine('command -v herd 2>/dev/null') === '') {
            return null;
        }

        return self::upstreamFromCliTableUrls(
            'cd '.escapeshellarg($startDir).' && herd links 2>/dev/null',
            'Laravel Herd (`herd links`)',
            $startDir
        );
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectValetLinks(string $startDir): ?array
    {
        if (self::runShellLine('command -v valet 2>/dev/null') === '') {
            return null;
        }

        return self::upstreamFromCliTableUrls(
            'cd '.escapeshellarg($startDir).' && valet links 2>/dev/null',
            'Laravel Valet (`valet links`)',
            $startDir
        );
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function upstreamFromCliTableUrls(string $shellCmd, string $sourceLabel, string $startDir = ''): ?array
    {
        $out = self::runShellLines($shellCmd);
        if ($out === []) {
            return null;
        }

        if ($startDir === '') {
            return null;
        }

        // Parse each pipe-delimited table row into [url, path] pairs.
        // Valet/Herd rows look like:
        //   | lookout | X | https://lookout.test | /Users/.../lookout | php@8.5 |
        $rows = [];
        foreach ($out as $line) {
            if (! preg_match('#https?://[^\s]+\.(?:test|localhost|wip)[^\s]*#i', $line, $urlMatch)) {
                continue;
            }
            $url = rtrim($urlMatch[0], ')"\'');
            // Extract the path column — the cell after the URL cell.
            $cells = array_map('trim', explode('|', $line));
            $rowPath = '';
            foreach ($cells as $idx => $cell) {
                if (str_contains($cell, '://') && isset($cells[$idx + 1])) {
                    $rowPath = $cells[$idx + 1];
                    break;
                }
            }
            if ($rowPath !== '') {
                $rows[] = ['url' => $url, 'path' => rtrim($rowPath, '/')];
            }
        }

        // Match rows whose path is exactly $startDir or a parent of it.
        // Prefer the deepest (most specific) path match.
        $startDir = rtrim($startDir, '/');
        $bestUrl = null;
        $bestDepth = -1;
        foreach ($rows as $row) {
            $rp = $row['path'];
            if ($rp === $startDir || str_starts_with($startDir, $rp.'/')) {
                $depth = substr_count($rp, '/');
                if ($depth > $bestDepth) {
                    $bestDepth = $depth;
                    $bestUrl = $row['url'];
                }
            }
        }

        if ($bestUrl !== null) {
            return self::appUrlToUpstream($bestUrl, $sourceLabel);
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectDdev(string $startDir): ?array
    {
        $dir = $startDir;
        for ($i = 0; $i < 32; $i++) {
            $cfg = $dir.\DIRECTORY_SEPARATOR.'.ddev'.\DIRECTORY_SEPARATOR.'config.yaml';
            if (is_file($cfg)) {
                $raw = @file_get_contents($cfg);
                if ($raw === false) {
                    return null;
                }
                if (preg_match('/^\s*name:\s*(.+)$/m', $raw, $m)) {
                    $name = trim($m[1], " \t\"'");
                    $port = 80;
                    if (preg_match('/^\s*router_http_port:\s*"?(\d+)"?/m', $raw, $pm)) {
                        $port = (int) $pm[1];
                    }
                    $host = $name.'.ddev.site';
                    if (self::tcpAccepts($host, $port)) {
                        return [
                            'host' => $host,
                            'port' => $port,
                            'hint' => 'Local upstream: '.$host.':'.$port.' (auto — DDEV `.ddev/config.yaml`).',
                        ];
                    }
                }

                return null;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectLando(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['.lando.yml', '.lando.yaml']);
        if ($path === null) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        if (preg_match('/^\s*name:\s*(.+)$/m', $raw, $m)) {
            $name = trim($m[1], " \t\"'");
            $host = $name.'.lndo.site';
            foreach ([80, 443, 8080] as $port) {
                if (self::tcpAccepts($host, $port)) {
                    return [
                        'host' => $host,
                        'port' => $port,
                        'hint' => 'Local upstream: '.$host.':'.$port.' (auto — Lando `.lando.yml`).',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Symfony CLI local proxy / server.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectSymfonyLocal(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['.symfony.local.yaml', '.symfony.local.yml']);
        if ($path === null) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        if (preg_match('#https?://([^\s/]+)(?::(\d+))?#', $raw, $m)) {
            $host = $m[1];
            $port = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 8000;

            return self::verifyLocalhostOrDevHost($host, $port, 'Symfony (`.symfony.local.yaml`)');
        }

        return null;
    }

    /**
     * First host port published to container 80/8080/443 in compose files.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectDockerComposePublishedPort(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml']);
        if ($path === null) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        foreach (self::publishedPortsFromComposeBody($raw) as $pub) {
            if (self::tcpAccepts('127.0.0.1', $pub)) {
                return [
                    'host' => '127.0.0.1',
                    'port' => $pub,
                    'hint' => 'Local upstream: 127.0.0.1:'.$pub.' (auto — Docker Compose `'.$path.'` published port).',
                ];
            }
        }

        return null;
    }

    /**
     * Laravel Sail — `docker-compose.yml` with `laravel.test` / sail service (published port).
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectLaravelSailCompose(string $startDir): ?array
    {
        $root = self::findAppRootWithFile($startDir, 'artisan') ?? $startDir;
        $compose = $root.\DIRECTORY_SEPARATOR.'docker-compose.yml';
        if (! is_file($compose)) {
            return null;
        }
        $raw = (string) file_get_contents($compose);
        if (! str_contains($raw, 'laravel.test') && ! str_contains($raw, 'sail')) {
            return null;
        }
        foreach (self::publishedPortsFromComposeBody($raw) as $pub) {
            if (self::tcpAccepts('127.0.0.1', $pub)) {
                return [
                    'host' => '127.0.0.1',
                    'port' => $pub,
                    'hint' => 'Local upstream: 127.0.0.1:'.$pub.' (auto — Laravel Sail `docker-compose.yml`).',
                ];
            }
        }

        return null;
    }

    /**
     * Host ports published to common container ports (80, 8080, 8000, 3000, 5173, 443).
     *
     * @return list<int>
     */
    private static function publishedPortsFromComposeBody(string $raw): array
    {
        if (! preg_match_all('/(?:^|\s)([0-9]{2,5})\s*:\s*(80|8080|443|8000|3000|5173)\b/m', $raw, $matches, PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($matches as $match) {
            $pub = (int) $match[1];
            if ($pub >= 1 && $pub <= 65535) {
                $out[] = $pub;
            }
        }

        return $out;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectWpEnv(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['.wp-env.json']);
        if ($path === null) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }
        $port = null;
        if (isset($data['port']) && is_numeric($data['port'])) {
            $port = (int) $data['port'];
        } elseif (isset($data['tests']['port']) && is_numeric($data['tests']['port'])) {
            $port = (int) $data['tests']['port'];
        }
        if ($port === null || $port < 1) {
            $port = 8888;
        }
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — `@wordpress/env` `.wp-env.json`).',
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectCraftNitro(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['nitro.yaml']);
        if ($path === null) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        if (preg_match('/^\s*hostname:\s*(.+)$/m', $raw, $m)) {
            $host = trim($m[1], " \t\"'");
            foreach ([80, 443, 8080] as $port) {
                if (self::tcpAccepts($host, $port)) {
                    return [
                        'host' => $host,
                        'port' => $port,
                        'hint' => 'Local upstream: '.$host.':'.$port.' (auto — Craft Nitro `nitro.yaml`).',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectViteConfigPort(string $startDir): ?array
    {
        $names = ['vite.config.ts', 'vite.config.js', 'vite.config.mjs', 'vite.config.cjs', 'vite.config.mts'];
        $path = self::findFileUpwardAny($startDir, $names);
        if ($path === null) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $port = 5173;
        if (preg_match('/\bport\s*:\s*(\d+)/', $raw, $m)) {
            $port = (int) $m[1];
        }
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Vite `'.basename($path).'`, port '.$port.').',
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectNuxtConfigPort(string $startDir): ?array
    {
        $path = self::findFileUpwardAny($startDir, ['nuxt.config.ts', 'nuxt.config.js']);
        if ($path === null) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $port = 3000;
        if (preg_match('/devServer\s*:\s*\{[^}]*port\s*:\s*(\d+)/s', $raw, $m)) {
            $port = (int) $m[1];
        } elseif (preg_match('/port\s*:\s*(\d+)/', $raw, $m) && str_contains($raw, 'dev')) {
            $port = (int) $m[1];
        }
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Nuxt config).',
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectAstroConfigPort(string $startDir): ?array
    {
        $path = self::findFileUpwardAny($startDir, ['astro.config.mjs', 'astro.config.ts', 'astro.config.js']);
        if ($path === null) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $port = 4321;
        if (preg_match('/\bport\s*:\s*(\d+)/', $raw, $m)) {
            $port = (int) $m[1];
        }
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Astro config).',
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectSvelteConfigPort(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, 'svelte.config.js');
        if ($path === null) {
            return null;
        }
        // Often defers to Vite — try vite port first
        $vite = self::detectViteConfigPort($startDir);
        if ($vite !== null) {
            return $vite;
        }
        $port = 5173;
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — SvelteKit / Vite default).',
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectNextJsDefault(string $startDir): ?array
    {
        if (self::findFileUpwardAny($startDir, ['next.config.js', 'next.config.mjs', 'next.config.ts']) === null) {
            return null;
        }
        foreach ([3000, 3001] as $port) {
            if (self::tcpAccepts('127.0.0.1', $port)) {
                return [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Next.js dev server).',
                ];
            }
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectRemixOrTanStackStart(string $startDir): ?array
    {
        $hasRemix = self::findFileUpwardAny($startDir, ['remix.config.js', 'react-router.config.ts']) !== null;
        $pkgPath = self::findFileUpward($startDir, 'package.json');
        $hasTanStack = $pkgPath !== null && str_contains((string) @file_get_contents($pkgPath), '@tanstack/react-start');
        if (! $hasRemix && ! $hasTanStack) {
            return null;
        }
        foreach ([3000, 5173] as $port) {
            if (self::tcpAccepts('127.0.0.1', $port)) {
                return [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Remix / TanStack Start dev).',
                ];
            }
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectGatsbyDefault(string $startDir): ?array
    {
        if (self::findFileUpward($startDir, 'gatsby-config.js') === null && self::findFileUpward($startDir, 'gatsby-config.ts') === null) {
            return null;
        }
        $port = 8000;
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Gatsby develop default).',
            ];
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectDevcontainerForwardPort(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['.devcontainer/devcontainer.json']);
        if ($path === null) {
            return null;
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }
        if (preg_match('/"forwardPorts"\s*:\s*\[\s*(\d+)/', $json, $m)) {
            $port = (int) $m[1];
            if ($port >= 1 && self::tcpAccepts('127.0.0.1', $port)) {
                return [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — devcontainer `forwardPorts`).',
                ];
            }
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectCaddyfilePort(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['Caddyfile']);
        if ($path === null) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (preg_match('/(?:127\.0\.0\.1|localhost):(\d{2,5})\b/', $raw, $m)) {
            $port = (int) $m[1];
            if (self::tcpAccepts('127.0.0.1', $port)) {
                return [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — Caddyfile).',
                ];
            }
        }

        return null;
    }

    /**
     * Generic PORT / APP_PORT / VITE_PORT / FORWARD_HTTP_PORT in .env (walk up).
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectGenericDotEnvPort(string $startDir): ?array
    {
        $keys = ['FORWARD_HTTP_PORT', 'APP_PORT', 'PORT', 'VITE_PORT', 'DEV_SERVER_PORT', 'SERVER_PORT'];
        $dir = $startDir;
        for ($i = 0; $i < 32; $i++) {
            foreach (['.env', '.env.local', '.env.development'] as $envName) {
                $envPath = $dir.\DIRECTORY_SEPARATOR.$envName;
                if (! is_file($envPath)) {
                    continue;
                }
                $content = (string) file_get_contents($envPath);
                foreach ($keys as $key) {
                    if (preg_match('/^'.preg_quote($key, '/').'\s*=\s*(\d+)/m', $content, $m)) {
                        $port = (int) $m[1];
                        if ($port >= 1 && $port <= 65535 && self::tcpAccepts('127.0.0.1', $port)) {
                            return [
                                'host' => '127.0.0.1',
                                'port' => $port,
                                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — `'.$envName.'` '.$key.').',
                            ];
                        }
                    }
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

    /**
     * package.json scripts + devDependencies heuristics.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectPackageJsonDevHeuristic(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, 'package.json');
        if ($path === null) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return null;
        }
        $depBlock = array_merge(
            is_array($json['dependencies'] ?? null) ? $json['dependencies'] : [],
            is_array($json['devDependencies'] ?? null) ? $json['devDependencies'] : [],
        );
        $scripts = is_array($json['scripts'] ?? null) ? $json['scripts'] : [];
        $devScript = (string) ($scripts['dev'] ?? '');

        $portHints = [
            'vite' => 5173,
            'astro' => 4321,
            '@astrojs' => 4321,
            'next' => 3000,
            'nuxt' => 3000,
            'react-scripts' => 3000,
            'parcel' => 1234,
            'webpack' => 8080,
            'gatsby' => 8000,
            'strapi' => 1337,
            '@strapi/strapi' => 1337,
            'directus' => 8055,
            'svelte' => 5173,
        ];
        foreach ($portHints as $needle => $port) {
            if (isset($depBlock[$needle]) || str_contains($devScript, $needle)) {
                if (self::tcpAccepts('127.0.0.1', $port)) {
                    return [
                        'host' => '127.0.0.1',
                        'port' => $port,
                        'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — package.json / `'.$needle.'` heuristic).',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Common MAMP default port when cwd is under MAMP htdocs.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectMampStylePath(string $startDir): ?array
    {
        $norm = str_replace('\\', '/', $startDir);
        if (! str_contains($norm, 'MAMP/htdocs') && ! str_contains($norm, 'MAMP\\htdocs')) {
            return null;
        }
        $port = 8888;
        if (self::tcpAccepts('127.0.0.1', $port)) {
            return [
                'host' => '127.0.0.1',
                'port' => $port,
                'hint' => 'Local upstream: 127.0.0.1:'.$port.' (auto — path under MAMP `htdocs`; default Apache port).',
            ];
        }

        return null;
    }

    /**
     * PhpStorm / JetBrains — optional URL in .idea/php.xml or workspace.
     *
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function detectPhpStormPublicUrl(string $startDir): ?array
    {
        $path = self::findFileUpward($startDir, ['.idea/php.xml']);
        if ($path === null) {
            return null;
        }
        $raw = (string) file_get_contents($path);
        if (preg_match('#https?://[^\s<]+\.(?:test|localhost)[^\s<]*#i', $raw, $m)) {
            return self::appUrlToUpstream($m[0], 'PhpStorm `.idea/php.xml`');
        }

        return null;
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function verifyLocalhostOrDevHost(string $host, int $port, string $label): ?array
    {
        if (self::tcpAccepts($host, $port)) {
            return [
                'host' => $host,
                'port' => $port,
                'hint' => 'Local upstream: '.$host.':'.$port.' (auto — '.$label.').',
            ];
        }

        return null;
    }

    private static function findAppRootWithFile(string $dir, string $filename): ?string
    {
        $dir = $dir !== '' ? $dir : '.';
        $max = 32;
        for ($i = 0; $i < $max; $i++) {
            $candidate = $dir.\DIRECTORY_SEPARATOR.$filename;
            if (is_file($candidate)) {
                return realpath($dir) ?: $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * @param  string|list<string>  $names
     */
    private static function findFileUpward(string $startDir, array|string $names): ?string
    {
        $list = is_array($names) ? $names : [$names];
        $dir = $startDir;
        for ($i = 0; $i < 32; $i++) {
            foreach ($list as $name) {
                $p = $dir.\DIRECTORY_SEPARATOR.$name;
                if (is_file($p)) {
                    return $p;
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

    /**
     * @param  list<string>  $names
     */
    private static function findFileUpwardAny(string $startDir, array $names): ?string
    {
        return self::findFileUpward($startDir, $names);
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function upstreamFromDotEnvKey(string $envPath, string $key, string $sourceLabel): ?array
    {
        if (! is_file($envPath) || ! is_readable($envPath)) {
            return null;
        }
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return null;
        }
        if (! preg_match('/^'.preg_quote($key, '/').'\s*=\s*(.+)$/m', $content, $m)) {
            return null;
        }
        $raw = trim($m[1], " \t\"'");
        if (($p = strpos($raw, '#')) !== false) {
            $raw = trim(substr($raw, 0, $p));
        }
        if ($raw === '') {
            return null;
        }

        return self::appUrlToUpstream($raw, $sourceLabel);
    }

    /**
     * @return array{host: string, port: int, hint: string}|null
     */
    private static function appUrlToUpstream(string $rawUrl, string $sourceLabel): ?array
    {
        if (! str_contains($rawUrl, '://')) {
            $rawUrl = 'http://'.$rawUrl;
        }
        $p = parse_url($rawUrl);
        if (! is_array($p) || empty($p['host'])) {
            return null;
        }
        $host = (string) $p['host'];
        $scheme = strtolower((string) ($p['scheme'] ?? 'http'));
        $explicitPort = isset($p['port']) ? (int) $p['port'] : null;

        $port = $explicitPort ?? ($scheme === 'https' ? 443 : 80);

        // When APP_URL is a bare loopback (localhost, 127.0.0.1) without an
        // explicit port, skip it so later detectors (Herd/Valet) can find the
        // real .test domain. An explicit port like localhost:8000 is kept
        // because the user deliberately configured it.
        $lower = strtolower($host);
        if ($explicitPort === null && ($lower === 'localhost' || $lower === '127.0.0.1' || $lower === '::1')) {
            return null;
        }

        if (! self::tcpAccepts($host, $port)) {
            if ($explicitPort === null && $scheme === 'https' && self::tcpAccepts($host, 80)) {
                $port = 80;
            } else {
                return null;
            }
        }

        $hint = 'Local upstream: '.$host.':'.$port.' (auto — '.$sourceLabel;
        if (str_contains($sourceLabel, 'APP_URL')) {
            $hint .= '; use --no-detect for 127.0.0.1 port scan only';
        }
        $hint .= ').';

        return ['host' => $host, 'port' => $port, 'hint' => $hint];
    }

    private static function isProbablyLocalDevTld(string $host): bool
    {
        $h = strtolower($host);

        return str_ends_with($h, '.test')
            || str_ends_with($h, '.localhost')
            || str_ends_with($h, '.local')
            || str_contains($h, '.ddev.')
            || str_ends_with($h, '.wip');
    }

    /**
     * Parse APP_URL-style value into hostnames (lowercase) for tunnel redirect rewriting.
     * Includes a `www.` variant when the canonical host is not already www-prefixed.
     *
     * @return list<string>
     */
    public static function hostsFromAppUrlRaw(string $rawUrl): array
    {
        $raw = trim($rawUrl);
        if ($raw === '') {
            return [];
        }
        if (! str_contains($raw, '://')) {
            $raw = 'http://'.$raw;
        }
        $p = parse_url($raw);
        if (! is_array($p) || empty($p['host'])) {
            return [];
        }
        $host = strtolower((string) $p['host']);
        $out = [$host];
        if (! str_starts_with($host, 'www.')) {
            $out[] = 'www.'.$host;
        }

        return array_values(array_unique($out));
    }

    /**
     * APP_URL hosts from Laravel apps in immediate subdirectories of {@code $cwd} (e.g. monorepo root
     * with {@code apps/my-app/artisan}).
     *
     * Bounded to 48 directories that contain an {@code artisan} file. Opt out with
     * {@code JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1}.
     *
     * @return list<string>
     */
    public static function appUrlHostsFromAdjacentArtisanProjects(string $cwd): array
    {
        $cwd = trim($cwd);
        if ($cwd === '' || ! is_dir($cwd)) {
            return [];
        }
        $out = [];
        if (is_file($cwd.\DIRECTORY_SEPARATOR.'artisan')) {
            foreach (self::appUrlHostsForTunnelRewrite($cwd) as $h) {
                $out[] = $h;
            }
        }
        $n = 0;
        foreach (scandir($cwd) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if ($n >= 48) {
                break;
            }
            $sub = $cwd.\DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($sub) || ! is_file($sub.\DIRECTORY_SEPARATOR.'artisan')) {
                continue;
            }
            $n++;
            foreach (self::appUrlHostsForTunnelRewrite($sub) as $h) {
                $out[] = $h;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Hostnames from APP_URL in a project .env (walk upward from $startDir).
     *
     * @return list<string>
     */
    public static function appUrlHostsForTunnelRewrite(?string $startDir = null): array
    {
        $dir = $startDir ?? getcwd();
        if ($dir === false || $dir === '') {
            $dir = '.';
        }
        $envPath = self::findFileUpward($dir, ['.env', '.env.local', '.env.development']);
        if ($envPath === null) {
            return [];
        }
        $content = @file_get_contents($envPath);
        if ($content === false) {
            return [];
        }
        if (! preg_match('/^APP_URL\s*=\s*(.+)$/m', $content, $m)) {
            return [];
        }
        $raw = trim($m[1], " \t\"'");
        if (($p = strpos($raw, '#')) !== false) {
            $raw = trim(substr($raw, 0, $p));
        }

        return self::hostsFromAppUrlRaw($raw);
    }

    private static function runShellLine(string $cmd): string
    {
        $out = shell_exec($cmd);

        return is_string($out) ? trim($out) : '';
    }

    /**
     * @return list<string>
     */
    private static function runShellLines(string $cmd): array
    {
        $out = shell_exec($cmd);
        if (! is_string($out) || $out === '') {
            return [];
        }
        $lines = preg_split("/\r\n|\n|\r/", $out) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $l) => $l !== ''));
    }

    private static function tcpAccepts(string $host, int $port): bool
    {
        $host = trim($host);
        if ($host === '') {
            return false;
        }
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.25);
        if (\is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }
}
