<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\DefaultBridge;
use PHPUnit\Framework\TestCase;

final class DefaultBridgeTest extends TestCase
{
    /** @var list<string> */
    private array $envVars = [
        'JETTY_ALLOW_LOCAL_BRIDGE',
    ];

    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
    }

    private function clearEnv(): void
    {
        foreach ($this->envVars as $var) {
            putenv($var);
        }
    }

    // -- baseUrl() --

    public function test_base_url_with_null_region_returns_default(): void
    {
        $this->assertSame('https://usejetty.online', DefaultBridge::baseUrl(null));
    }

    public function test_base_url_with_empty_region_returns_default(): void
    {
        $this->assertSame('https://usejetty.online', DefaultBridge::baseUrl(''));
    }

    public function test_base_url_with_whitespace_region_returns_default(): void
    {
        $this->assertSame('https://usejetty.online', DefaultBridge::baseUrl('   '));
    }

    public function test_base_url_with_region_returns_regional_url(): void
    {
        $this->assertSame('https://eu.usejetty.online', DefaultBridge::baseUrl('eu'));
    }

    public function test_base_url_with_complex_region(): void
    {
        $this->assertSame('https://us-east-1.usejetty.online', DefaultBridge::baseUrl('us-east-1'));
    }

    public function test_base_url_normalizes_region_to_lowercase(): void
    {
        $this->assertSame('https://eu.usejetty.online', DefaultBridge::baseUrl('EU'));
    }

    // -- normalizeRegion() --

    public function test_normalize_region_null_returns_empty(): void
    {
        $this->assertSame('', DefaultBridge::normalizeRegion(null));
    }

    public function test_normalize_region_empty_returns_empty(): void
    {
        $this->assertSame('', DefaultBridge::normalizeRegion(''));
    }

    public function test_normalize_region_lowercases(): void
    {
        $this->assertSame('eu', DefaultBridge::normalizeRegion('EU'));
    }

    public function test_normalize_region_trims_whitespace(): void
    {
        $this->assertSame('eu', DefaultBridge::normalizeRegion('  eu  '));
    }

    public function test_normalize_region_rejects_invalid_characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DefaultBridge::normalizeRegion('eu_west!');
    }

    public function test_normalize_region_accepts_hyphens(): void
    {
        $this->assertSame('us-east-1', DefaultBridge::normalizeRegion('us-east-1'));
    }

    public function test_normalize_region_rejects_leading_hyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DefaultBridge::normalizeRegion('-eu');
    }

    public function test_normalize_region_rejects_trailing_hyphen(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DefaultBridge::normalizeRegion('eu-');
    }

    // -- isProbablyLocalBridge() --

    public function test_is_probably_local_bridge_localhost(): void
    {
        $this->assertTrue(DefaultBridge::isProbablyLocalBridge('http://localhost:8080'));
    }

    public function test_is_probably_local_bridge_127(): void
    {
        $this->assertTrue(DefaultBridge::isProbablyLocalBridge('http://127.0.0.1:3000'));
    }

    public function test_is_probably_local_bridge_0000(): void
    {
        $this->assertTrue(DefaultBridge::isProbablyLocalBridge('http://0.0.0.0:8000'));
    }

    public function test_is_probably_local_bridge_remote_url(): void
    {
        $this->assertFalse(DefaultBridge::isProbablyLocalBridge('https://usejetty.online'));
    }

    public function test_is_probably_local_bridge_case_insensitive(): void
    {
        $this->assertTrue(DefaultBridge::isProbablyLocalBridge('http://LOCALHOST:9000'));
    }

    // -- allowLocalBridgeCandidates() --

    public function test_allow_local_bridge_not_set_returns_false(): void
    {
        $this->assertFalse(DefaultBridge::allowLocalBridgeCandidates());
    }

    public function test_allow_local_bridge_set_to_1_returns_true(): void
    {
        putenv('JETTY_ALLOW_LOCAL_BRIDGE=1');
        $this->assertTrue(DefaultBridge::allowLocalBridgeCandidates());
    }

    public function test_allow_local_bridge_set_to_true_returns_true(): void
    {
        putenv('JETTY_ALLOW_LOCAL_BRIDGE=true');
        $this->assertTrue(DefaultBridge::allowLocalBridgeCandidates());
    }

    public function test_allow_local_bridge_set_to_yes_returns_true(): void
    {
        putenv('JETTY_ALLOW_LOCAL_BRIDGE=yes');
        $this->assertTrue(DefaultBridge::allowLocalBridgeCandidates());
    }

    public function test_allow_local_bridge_set_to_0_returns_false(): void
    {
        putenv('JETTY_ALLOW_LOCAL_BRIDGE=0');
        $this->assertFalse(DefaultBridge::allowLocalBridgeCandidates());
    }

    public function test_allow_local_bridge_case_insensitive(): void
    {
        putenv('JETTY_ALLOW_LOCAL_BRIDGE=TRUE');
        $this->assertTrue(DefaultBridge::allowLocalBridgeCandidates());
    }

    // -- Constants --

    public function test_host_constant(): void
    {
        $this->assertSame('usejetty.online', DefaultBridge::HOST);
    }

    public function test_https_base_constant(): void
    {
        $this->assertSame('https://usejetty.online', DefaultBridge::HTTPS_BASE);
    }
}
