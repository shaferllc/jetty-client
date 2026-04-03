<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\TunnelRewriteOptions;
use PHPUnit\Framework\TestCase;

final class TunnelRewriteOptionsTest extends TestCase
{
    /** @var list<string> */
    private array $envVars = [
        'JETTY_SHARE_NO_BODY_REWRITE',
        'JETTY_SHARE_BODY_REWRITE',
        'JETTY_SHARE_NO_JS_REWRITE',
        'JETTY_SHARE_JS_REWRITE',
        'JETTY_SHARE_NO_CSS_REWRITE',
        'JETTY_SHARE_CSS_REWRITE',
        'JETTY_SHARE_BODY_REWRITE_MAX_BYTES',
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

    public function test_defaults_when_no_env_set(): void
    {
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertTrue($opts->bodyRewrite);
        $this->assertTrue($opts->jsRewrite);
        $this->assertTrue($opts->cssRewrite);
        $this->assertSame(4194304, $opts->maxBodyBytes);
    }

    public function test_no_body_rewrite_disables_all(): void
    {
        putenv('JETTY_SHARE_NO_BODY_REWRITE=1');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertFalse($opts->bodyRewrite);
        $this->assertFalse($opts->jsRewrite);
        $this->assertFalse($opts->cssRewrite);
    }

    public function test_legacy_body_rewrite_zero_disables_all(): void
    {
        putenv('JETTY_SHARE_BODY_REWRITE=0');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertFalse($opts->bodyRewrite);
        $this->assertFalse($opts->jsRewrite);
        $this->assertFalse($opts->cssRewrite);
    }

    public function test_no_js_rewrite_disables_js_only(): void
    {
        putenv('JETTY_SHARE_NO_JS_REWRITE=1');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertTrue($opts->bodyRewrite);
        $this->assertFalse($opts->jsRewrite);
        $this->assertTrue($opts->cssRewrite);
    }

    public function test_legacy_js_rewrite_zero_disables_js_only(): void
    {
        putenv('JETTY_SHARE_JS_REWRITE=0');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertTrue($opts->bodyRewrite);
        $this->assertFalse($opts->jsRewrite);
        $this->assertTrue($opts->cssRewrite);
    }

    public function test_no_css_rewrite_disables_css_only(): void
    {
        putenv('JETTY_SHARE_NO_CSS_REWRITE=1');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertTrue($opts->bodyRewrite);
        $this->assertTrue($opts->jsRewrite);
        $this->assertFalse($opts->cssRewrite);
    }

    public function test_legacy_css_rewrite_zero_disables_css_only(): void
    {
        putenv('JETTY_SHARE_CSS_REWRITE=0');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertTrue($opts->bodyRewrite);
        $this->assertTrue($opts->jsRewrite);
        $this->assertFalse($opts->cssRewrite);
    }

    public function test_max_body_bytes_from_env(): void
    {
        putenv('JETTY_SHARE_BODY_REWRITE_MAX_BYTES=1024');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertSame(1024, $opts->maxBodyBytes);
    }

    public function test_max_body_bytes_clamped_to_minimum_1024(): void
    {
        putenv('JETTY_SHARE_BODY_REWRITE_MAX_BYTES=500');
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertSame(1024, $opts->maxBodyBytes);
    }

    public function test_body_off_forces_js_and_css_off(): void
    {
        putenv('JETTY_SHARE_NO_BODY_REWRITE=1');
        // Even without explicit JS/CSS disable, they should be off
        $opts = TunnelRewriteOptions::fromEnvironment();

        $this->assertFalse($opts->bodyRewrite);
        $this->assertFalse($opts->jsRewrite);
        $this->assertFalse($opts->cssRewrite);
    }
}
