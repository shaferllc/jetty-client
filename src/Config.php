<?php

declare(strict_types=1);

namespace JettyCli;

final class Config
{
    /** Sentinel default when no Bridge URL is known (onboarding treats this like “unset”). */
    public const PLACEHOLDER_API_URL = 'https://usejetty.online';

    public function __construct(
        public readonly string $apiUrl,
        public readonly string $token,
        public readonly string $defaultSubdomain = '',
        public readonly string $customDomain = '',
        /** Tunnel placement / edge region (e.g. us-west-1). Not the Bridge "server" name used for api_url. */
        public readonly string $defaultTunnelServer = '',
    ) {}

    /**
     * Resolve config: optional JSON file overrides env vars per key. Precedence after merge():
     * --api-url / --token flags (highest), then JSON file values where set, then JETTY_* env, then defaults.
     *
     * JSON search order (first readable file wins): --config path, JETTY_CONFIG,
     * ~/.config/jetty/config.json, ~/.jetty.json, then jetty.config.json / .jetty.json walking up from cwd.
     */
    public static function resolve(?string $configFileFlag = null, ?string $cliRegion = null): self
    {
        $base = self::defaultApiBaseBeforeConfigFile($cliRegion);
        $token = trim((string) (getenv('JETTY_TOKEN') ?: ''));

        $defaultSubdomain = '';
        $customDomain = '';
        $defaultTunnelServer = '';
        $envTunnelServer = getenv('JETTY_TUNNEL_SERVER');
        if ($envTunnelServer !== false && trim((string) $envTunnelServer) !== '') {
            $defaultTunnelServer = trim((string) $envTunnelServer);
        }

        $path = self::findConfigFilePath($configFileFlag);
        if ($path !== null) {
            $data = self::readJsonConfig($path);
            if (is_array($data)) {
                if (array_key_exists('api_url', $data) && is_string($data['api_url']) && trim($data['api_url']) !== '') {
                    $base = rtrim(trim($data['api_url']), '/');
                } elseif (array_key_exists('server', $data) && is_string($data['server']) && trim($data['server']) !== '') {
                    $base = self::serverToUrl(trim($data['server']));
                }
                if (array_key_exists('token', $data) && is_string($data['token'])) {
                    $token = trim($data['token']);
                }
                if (array_key_exists('subdomain', $data) && is_string($data['subdomain'])) {
                    $defaultSubdomain = trim($data['subdomain']);
                }
                if (array_key_exists('custom_domain', $data) && is_string($data['custom_domain'])) {
                    $customDomain = trim($data['custom_domain']);
                }
                if (array_key_exists('tunnel_server', $data) && is_string($data['tunnel_server']) && trim($data['tunnel_server']) !== '') {
                    $defaultTunnelServer = trim($data['tunnel_server']);
                }
                if (isset($data['share']) && is_array($data['share'])) {
                    $sh = $data['share'];
                    if (isset($sh['subdomain']) && is_string($sh['subdomain']) && trim($sh['subdomain']) !== '') {
                        $defaultSubdomain = trim($sh['subdomain']);
                    }
                    if (isset($sh['tunnel_server']) && is_string($sh['tunnel_server']) && trim($sh['tunnel_server']) !== '') {
                        $defaultTunnelServer = trim($sh['tunnel_server']);
                    }
                }
            }
        }

        return new self($base, $token, $defaultSubdomain, $customDomain, $defaultTunnelServer);
    }

    /**
     * Optional `share` object from the nearest jetty.config.json / .jetty.json walking up from cwd.
     * Used by `jetty share` for defaults (CLI flags still win).
     *
     * @return array<string, mixed>
     */
    public static function readProjectShareOverrides(): array
    {
        $path = self::findProjectConfigPathWalkingUp();
        if ($path === null) {
            return [];
        }
        $data = self::readJsonConfig($path);
        if (! is_array($data) || ! isset($data['share']) || ! is_array($data['share'])) {
            return [];
        }

        return $data['share'];
    }

    /**
     * Nearest project config (jetty.config.json or .jetty.json) walking up from cwd.
     */
    private static function findProjectConfigPathWalkingUp(): ?string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return null;
        }
        $dir = $cwd;
        for ($i = 0; $i < 64; $i++) {
            foreach (['jetty.config.json', '.jetty.json'] as $name) {
                $p = $dir.\DIRECTORY_SEPARATOR.$name;
                if (is_file($p) && is_readable($p)) {
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
     * @deprecated Use resolve() which loads JSON + env.
     */
    public static function fromEnv(): self
    {
        return self::resolve();
    }

    public function merge(?string $apiUrlFlag, ?string $tokenFlag): self
    {
        $base = $apiUrlFlag !== null && $apiUrlFlag !== '' ? rtrim($apiUrlFlag, '/') : $this->apiUrl;
        $token = $tokenFlag !== null && $tokenFlag !== '' ? trim($tokenFlag) : $this->token;

        return new self($base, $token, $this->defaultSubdomain, $this->customDomain, $this->defaultTunnelServer);
    }

    /** @return non-empty-string */
    private static function serverToUrl(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return 'https://localhost';
        }
        if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) {
            return rtrim($s, '/');
        }

        return 'https://'.$s;
    }

    /** @return non-empty-string */
    public static function normalizeConfigCliKey(string $key): string
    {
        $k = strtolower(trim($key));
        $map = [
            'token' => 'token',
            'server' => 'server',
            'api-url' => 'api_url',
            'api_url' => 'api_url',
            'subdomain' => 'subdomain',
            'domain' => 'custom_domain',
            'custom-domain' => 'custom_domain',
            'custom_domain' => 'custom_domain',
            'tunnel-server' => 'tunnel_server',
            'tunnel_server' => 'tunnel_server',
        ];
        if (! isset($map[$k])) {
            throw new \InvalidArgumentException('Unknown config key: '.$key.' (use server, api-url, token, subdomain, domain, tunnel-server)');
        }

        return $map[$k];
    }

    public static function userConfigPath(): ?string
    {
        $home = self::homeDirectory();
        if ($home === null) {
            return null;
        }

        return $home.'/.config/jetty/config.json';
    }

    /**
     * @return array<string, mixed>
     */
    public static function readUserConfigMap(): array
    {
        $path = self::userConfigPath();
        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return [];
        }
        $data = self::readJsonConfig($path);

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    public static function writeUserConfigMerged(array $patch): void
    {
        $path = self::userConfigPath();
        if ($path === null) {
            throw new \InvalidArgumentException('Cannot resolve home directory for ~/.config/jetty/config.json');
        }
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0o700, true) && ! is_dir($dir)) {
                throw new \InvalidArgumentException('Cannot create directory: '.$dir);
            }
        }
        $current = self::readUserConfigMap();
        foreach ($patch as $k => $v) {
            $current[$k] = $v;
        }
        $json = json_encode($current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $json.\PHP_EOL) === false) {
            throw new \InvalidArgumentException('Cannot write: '.$path);
        }
    }

    public static function clearUserConfigKey(string $jsonKey): void
    {
        $path = self::userConfigPath();
        if ($path === null) {
            return;
        }
        $current = self::readUserConfigMap();
        unset($current[$jsonKey]);
        if ($current === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $json = json_encode($current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $json.\PHP_EOL) === false) {
            throw new \InvalidArgumentException('Cannot write: '.$path);
        }
    }

    public static function clearAllUserConfig(): void
    {
        $path = self::userConfigPath();
        if ($path === null) {
            return;
        }
        $current = self::readUserConfigMap();
        foreach (['api_url', 'server', 'token', 'subdomain', 'custom_domain', 'tunnel_server'] as $k) {
            unset($current[$k]);
        }
        if ($current === []) {
            if (is_file($path)) {
                @unlink($path);
            }

            return;
        }
        $json = json_encode($current, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $json.\PHP_EOL) === false) {
            throw new \InvalidArgumentException('Cannot write: '.$path);
        }
    }

    /**
     * Clear all standard Jetty keys from ~/.config/jetty/config.json and remove ~/.jetty.json if present.
     * Does not delete ./jetty.config.json or a file set via JETTY_CONFIG / --config (project or explicit paths).
     */
    public static function resetLocalUserConfig(): void
    {
        self::clearAllUserConfig();
        $home = self::homeDirectory();
        if ($home === null) {
            return;
        }
        $legacy = $home.\DIRECTORY_SEPARATOR.'.jetty.json';
        if (is_file($legacy)) {
            @unlink($legacy);
        }
    }

    public function validate(): void
    {
        if ($this->token === '') {
            throw new \InvalidArgumentException(
                'Missing API token: run jetty onboard (or jetty setup), add "token" to ~/.config/jetty/config.json, set JETTY_TOKEN, or pass --token (Bridge → Tokens).'
            );
        }
        if ($this->apiUrl === '') {
            throw new \InvalidArgumentException('Empty API URL.');
        }
    }

    private static function findConfigFilePath(?string $flag): ?string
    {
        $candidates = [];
        if ($flag !== null && $flag !== '') {
            $candidates[] = $flag;
        }
        $envPath = getenv('JETTY_CONFIG');
        if ($envPath !== false && $envPath !== '') {
            $candidates[] = $envPath;
        }
        $home = self::homeDirectory();
        if ($home !== null) {
            $candidates[] = $home.'/.config/jetty/config.json';
            $candidates[] = $home.'/.jetty.json';
        }
        $cwd = getcwd();
        if ($cwd !== false) {
            $dir = $cwd;
            for ($i = 0; $i < 64; $i++) {
                foreach (['jetty.config.json', '.jetty.json'] as $name) {
                    $candidates[] = $dir.\DIRECTORY_SEPARATOR.$name;
                }
                $parent = dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        foreach ($candidates as $p) {
            if ($p === '' || ! is_file($p) || ! is_readable($p)) {
                continue;
            }

            return $p;
        }

        return null;
    }

    /**
     * @return ?array<string, mixed>
     */
    private static function readJsonConfig(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
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
     * Env-only defaults before merging JSON config. Uses JETTY_API_URL, JETTY_SERVER, installer-style
     * JETTY_BRIDGE_URL / JETTY_ONBOARD_BRIDGE_URL, then APP_URL from a .env file found walking up from cwd.
     */
    private static function defaultApiBaseBeforeConfigFile(?string $cliRegion): string
    {
        $envBase = getenv('JETTY_API_URL');
        if (is_string($envBase) && trim($envBase) !== '') {
            return rtrim(trim($envBase), '/');
        }
        $envServer = getenv('JETTY_SERVER');
        if (is_string($envServer) && trim($envServer) !== '') {
            return self::serverToUrl(trim($envServer));
        }
        foreach (['JETTY_BRIDGE_URL', 'JETTY_ONBOARD_BRIDGE_URL'] as $key) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return rtrim(trim($v), '/');
            }
        }

        $region = $cliRegion;
        if ($region === null || trim($region) === '') {
            $envRegion = getenv('JETTY_REGION');
            if (is_string($envRegion) && trim($envRegion) !== '') {
                $region = trim($envRegion);
            }
        }

        return DefaultBridge::baseUrl($region !== null && $region !== '' ? $region : null);
    }

    /**
     * APP_URL from a .env file found walking up from the current working directory (Jetty project root).
     */
    public static function appUrlFromNearestDotEnv(): ?string
    {
        return self::appUrlFromEnvWalkingUp(getcwd());
    }

    private static function appUrlFromEnvWalkingUp(string|false $startDir): ?string
    {
        if (! is_string($startDir) || $startDir === '') {
            return null;
        }
        $dir = $startDir;
        for ($depth = 0; $depth < 12; $depth++) {
            $envFile = $dir.\DIRECTORY_SEPARATOR.'.env';
            if (is_file($envFile) && is_readable($envFile)) {
                $parsed = self::parseAppUrlFromEnvFile($envFile);
                if ($parsed !== null) {
                    return $parsed;
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

    private static function parseAppUrlFromEnvFile(string $path): ?string
    {
        $raw = @file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        if (! preg_match('/^\s*APP_URL\s*=\s*(.*)$/m', $raw, $m)) {
            return null;
        }
        $u = trim($m[1]);
        if ($u === '' || str_starts_with($u, '#')) {
            return null;
        }
        if (! str_starts_with($u, '"') && ! str_starts_with($u, "'")) {
            $u = preg_replace('/\s+#.*$/', '', $u) ?? $u;
            $u = trim($u);
        }
        $u = trim($u, " \t\"'");
        if ($u === '' || (! str_starts_with($u, 'http://') && ! str_starts_with($u, 'https://'))) {
            return null;
        }

        return rtrim($u, '/');
    }

    private static function homeDirectory(): ?string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home;
        }
        if (\PHP_OS_FAMILY === 'Windows') {
            $userProfile = getenv('USERPROFILE');
            if (is_string($userProfile) && $userProfile !== '') {
                return $userProfile;
            }
        }

        return null;
    }
}
