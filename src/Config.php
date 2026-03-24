<?php

declare(strict_types=1);

namespace JettyCli;

final class Config
{
    public function __construct(
        public readonly string $apiUrl,
        public readonly string $token,
        public readonly string $defaultSubdomain = '',
        public readonly string $customDomain = '',
    ) {}

    /**
     * Resolve config: optional JSON file overrides env vars per key. Precedence after merge():
     * --api-url / --token flags (highest), then JSON file values where set, then JETTY_* env, then defaults.
     *
     * JSON search order (first readable file wins): --config path, JETTY_CONFIG,
     * ~/.config/jetty/config.json, ~/.jetty.json, ./jetty.config.json (cwd).
     */
    public static function resolve(?string $configFileFlag = null): self
    {
        $envBase = getenv('JETTY_API_URL');
        $envServer = getenv('JETTY_SERVER');
        $base = ($envBase !== false && trim((string) $envBase) !== '')
            ? rtrim(trim((string) $envBase), '/')
            : (($envServer !== false && trim((string) $envServer) !== '')
                ? self::serverToUrl(trim((string) $envServer))
                : 'http://127.0.0.1:8000');
        $token = trim((string) (getenv('JETTY_TOKEN') ?: ''));

        $defaultSubdomain = '';
        $customDomain = '';

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
            }
        }

        return new self($base, $token, $defaultSubdomain, $customDomain);
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

        return new self($base, $token, $this->defaultSubdomain, $this->customDomain);
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
        ];
        if (! isset($map[$k])) {
            throw new \InvalidArgumentException('Unknown config key: '.$key.' (use server, api-url, token, subdomain, domain)');
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
        foreach (['api_url', 'server', 'token', 'subdomain', 'custom_domain'] as $k) {
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
            $candidates[] = $cwd.\DIRECTORY_SEPARATOR.'jetty.config.json';
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
