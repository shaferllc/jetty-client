<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    /** @var list<string> */
    private array $envKeysToClean = [
        'JETTY_CONFIG',
        'JETTY_TOKEN',
        'JETTY_API_URL',
        'JETTY_SERVER',
        'JETTY_BRIDGE_URL',
        'JETTY_ONBOARD_BRIDGE_URL',
        'JETTY_TUNNEL_SERVER',
        'JETTY_REGION',
    ];

    private ?string $origHome = null;

    private ?string $tmpHome = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Save original HOME so we can restore it.
        $h = getenv('HOME');
        $this->origHome = is_string($h) ? $h : null;

        // Clear all Jetty env vars so tests are isolated.
        foreach ($this->envKeysToClean as $key) {
            putenv($key);
        }

        // Point HOME to a temp dir so resolve() won't find a real user config file.
        $this->tmpHome = sys_get_temp_dir().'/jetty-cfg-test-'.getmypid().'-'.mt_rand();
        mkdir($this->tmpHome, 0700, true);
        putenv('HOME='.$this->tmpHome);
    }

    protected function tearDown(): void
    {
        foreach ($this->envKeysToClean as $key) {
            putenv($key);
        }
        // Restore HOME.
        if ($this->origHome !== null) {
            putenv('HOME='.$this->origHome);
        } else {
            putenv('HOME');
        }
        // Clean up temp HOME dir.
        if ($this->tmpHome !== null && is_dir($this->tmpHome)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpHome, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($this->tmpHome);
        }
        parent::tearDown();
    }

    // ── JETTY_TOKEN env ──

    public function test_resolve_reads_jetty_token_env(): void
    {
        putenv('JETTY_TOKEN=tok-from-env');
        $cfg = Config::resolve();
        $this->assertSame('tok-from-env', $cfg->token);
    }

    public function test_resolve_trims_jetty_token(): void
    {
        putenv('JETTY_TOKEN=  spaced-tok  ');
        $cfg = Config::resolve();
        $this->assertSame('spaced-tok', $cfg->token);
    }

    public function test_resolve_empty_token_when_unset(): void
    {
        putenv('JETTY_TOKEN');
        $cfg = Config::resolve();
        $this->assertSame('', $cfg->token);
    }

    // ── JETTY_API_URL env ──

    public function test_resolve_reads_jetty_api_url_env(): void
    {
        putenv('JETTY_API_URL=https://custom.bridge.example.com/');
        $cfg = Config::resolve();
        $this->assertSame('https://custom.bridge.example.com', $cfg->apiUrl);
    }

    // ── JETTY_SERVER env (fallback when API_URL unset) ──

    public function test_resolve_reads_jetty_server_when_api_url_unset(): void
    {
        putenv('JETTY_API_URL');
        putenv('JETTY_SERVER=bridge.example.com');
        $cfg = Config::resolve();
        $this->assertSame('https://bridge.example.com', $cfg->apiUrl);
    }

    public function test_resolve_api_url_takes_precedence_over_server(): void
    {
        putenv('JETTY_API_URL=https://explicit.example.com');
        putenv('JETTY_SERVER=bridge.example.com');
        $cfg = Config::resolve();
        $this->assertSame('https://explicit.example.com', $cfg->apiUrl);
    }

    // ── JETTY_BRIDGE_URL env (further fallback) ──

    public function test_resolve_reads_jetty_bridge_url_when_server_unset(): void
    {
        putenv('JETTY_API_URL');
        putenv('JETTY_SERVER');
        putenv('JETTY_BRIDGE_URL=https://bridge-fallback.example.com/');
        $cfg = Config::resolve();
        $this->assertSame('https://bridge-fallback.example.com', $cfg->apiUrl);
    }

    // ── JETTY_TUNNEL_SERVER env ──

    public function test_resolve_reads_jetty_tunnel_server_env(): void
    {
        putenv('JETTY_TUNNEL_SERVER=us-west-1');
        $cfg = Config::resolve();
        $this->assertSame('us-west-1', $cfg->defaultTunnelServer);
    }

    public function test_resolve_tunnel_server_empty_when_unset(): void
    {
        putenv('JETTY_TUNNEL_SERVER');
        $cfg = Config::resolve();
        $this->assertSame('', $cfg->defaultTunnelServer);
    }

    // ── JETTY_CONFIG env → config file path ──

    public function test_resolve_reads_config_file_from_jetty_config_env(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jetty-cfg-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, json_encode([
            'token' => 'tok-from-file',
            'api_url' => 'https://file-bridge.example.com',
            'tunnel_server' => 'eu-west-1',
        ]));
        putenv('JETTY_CONFIG='.$tmp);
        $cfg = Config::resolve();
        $this->assertSame('tok-from-file', $cfg->token);
        $this->assertSame('https://file-bridge.example.com', $cfg->apiUrl);
        $this->assertSame('eu-west-1', $cfg->defaultTunnelServer);
        @unlink($tmp);
    }

    public function test_resolve_config_file_server_key_builds_url(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jetty-cfg-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, json_encode(['server' => 'myhost.example.com']));
        putenv('JETTY_CONFIG='.$tmp);
        $cfg = Config::resolve();
        $this->assertSame('https://myhost.example.com', $cfg->apiUrl);
        @unlink($tmp);
    }

    public function test_resolve_env_token_overridden_by_config_file_token(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jetty-cfg-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, json_encode(['token' => 'file-tok']));
        putenv('JETTY_CONFIG='.$tmp);
        putenv('JETTY_TOKEN=env-tok');
        $cfg = Config::resolve();
        // Config file token wins over env.
        $this->assertSame('file-tok', $cfg->token);
        @unlink($tmp);
    }

    // ── merge() ──

    public function test_merge_overrides_api_url_and_token(): void
    {
        $cfg = new Config('https://orig.example.com', 'orig-tok', 'sub', 'cd', 'ts');
        $merged = $cfg->merge('https://new.example.com/', 'new-tok');
        $this->assertSame('https://new.example.com', $merged->apiUrl);
        $this->assertSame('new-tok', $merged->token);
        $this->assertSame('sub', $merged->defaultSubdomain);
        $this->assertSame('cd', $merged->customDomain);
        $this->assertSame('ts', $merged->defaultTunnelServer);
    }

    public function test_merge_keeps_existing_when_null(): void
    {
        $cfg = new Config('https://orig.example.com', 'orig-tok');
        $merged = $cfg->merge(null, null);
        $this->assertSame('https://orig.example.com', $merged->apiUrl);
        $this->assertSame('orig-tok', $merged->token);
    }

    // ── normalizeConfigCliKey() ──

    public function test_normalize_config_cli_key_maps_known_keys(): void
    {
        $this->assertSame('token', Config::normalizeConfigCliKey('token'));
        $this->assertSame('server', Config::normalizeConfigCliKey('server'));
        $this->assertSame('api_url', Config::normalizeConfigCliKey('api-url'));
        $this->assertSame('api_url', Config::normalizeConfigCliKey('api_url'));
        $this->assertSame('subdomain', Config::normalizeConfigCliKey('subdomain'));
        $this->assertSame('custom_domain', Config::normalizeConfigCliKey('domain'));
        $this->assertSame('custom_domain', Config::normalizeConfigCliKey('custom-domain'));
        $this->assertSame('custom_domain', Config::normalizeConfigCliKey('custom_domain'));
        $this->assertSame('tunnel_server', Config::normalizeConfigCliKey('tunnel-server'));
        $this->assertSame('tunnel_server', Config::normalizeConfigCliKey('tunnel_server'));
    }

    public function test_normalize_config_cli_key_is_case_insensitive(): void
    {
        $this->assertSame('token', Config::normalizeConfigCliKey('TOKEN'));
        $this->assertSame('api_url', Config::normalizeConfigCliKey('API-URL'));
    }

    public function test_normalize_config_cli_key_throws_for_unknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::normalizeConfigCliKey('unknown-key');
    }

    // ── userConfigPath() ──

    public function test_user_config_path_resolves_from_home(): void
    {
        $path = Config::userConfigPath();
        $this->assertSame($this->tmpHome.'/.config/jetty/config.json', $path);
    }

    // ── readUserConfigMap() + writeUserConfigMerged() with temp file ──

    public function test_read_and_write_user_config_roundtrip(): void
    {
        // Write initial data.
        Config::writeUserConfigMerged(['token' => 'abc', 'server' => 'example.com']);
        $data = Config::readUserConfigMap();
        $this->assertSame('abc', $data['token']);
        $this->assertSame('example.com', $data['server']);

        // Merge additional data.
        Config::writeUserConfigMerged(['subdomain' => 'my-sub']);
        $data = Config::readUserConfigMap();
        $this->assertSame('abc', $data['token']);
        $this->assertSame('my-sub', $data['subdomain']);
    }

    public function test_read_user_config_map_returns_empty_when_missing(): void
    {
        $data = Config::readUserConfigMap();
        $this->assertSame([], $data);
    }

    // ── validate() ──

    public function test_validate_throws_when_token_empty(): void
    {
        $cfg = new Config('https://example.com', '');
        $this->expectException(\InvalidArgumentException::class);
        $cfg->validate();
    }

    public function test_validate_throws_when_api_url_empty(): void
    {
        $cfg = new Config('', 'some-token');
        $this->expectException(\InvalidArgumentException::class);
        $cfg->validate();
    }

    public function test_validate_passes_when_both_set(): void
    {
        $cfg = new Config('https://example.com', 'some-token');
        $cfg->validate();
        $this->assertTrue(true); // No exception.
    }

    // ── clearUserConfigKey() ──

    public function test_clear_user_config_key_removes_single_key(): void
    {
        Config::writeUserConfigMerged(['token' => 'abc', 'server' => 'example.com']);
        Config::clearUserConfigKey('token');
        $data = Config::readUserConfigMap();
        $this->assertArrayNotHasKey('token', $data);
        $this->assertSame('example.com', $data['server']);
    }

    public function test_clear_user_config_key_deletes_file_when_empty(): void
    {
        Config::writeUserConfigMerged(['token' => 'only-key']);
        Config::clearUserConfigKey('token');
        $this->assertFileDoesNotExist($this->tmpHome.'/.config/jetty/config.json');
    }
}
