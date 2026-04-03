<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\ShareIdleConfig;
use PHPUnit\Framework\TestCase;

final class ShareIdleConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_SHARE_IDLE_DISABLE');
        putenv('JETTY_SHARE_IDLE_PROMPT_MINUTES');
        putenv('JETTY_SHARE_IDLE_GRACE_MINUTES');
    }

    public function test_default_values(): void
    {
        putenv('JETTY_SHARE_IDLE_DISABLE');
        putenv('JETTY_SHARE_IDLE_PROMPT_MINUTES');
        putenv('JETTY_SHARE_IDLE_GRACE_MINUTES');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertFalse($cfg->disabled);
        $this->assertSame(120, $cfg->promptMinutes);
        $this->assertSame(60, $cfg->graceMinutes);
    }

    public function test_disabled_explicitly(): void
    {
        putenv('JETTY_SHARE_IDLE_DISABLE=1');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertTrue($cfg->disabled);
    }

    public function test_not_disabled_when_set_to_other_value(): void
    {
        putenv('JETTY_SHARE_IDLE_DISABLE=0');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertFalse($cfg->disabled);
    }

    public function test_prompt_minutes_zero_is_falsy_falls_to_default(): void
    {
        // PHP's ?: treats '0' as falsy, so it falls back to the default 120
        putenv('JETTY_SHARE_IDLE_PROMPT_MINUTES=0');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertFalse($cfg->disabled);
        $this->assertSame(120, $cfg->promptMinutes);
    }

    public function test_disabled_when_prompt_minutes_negative(): void
    {
        putenv('JETTY_SHARE_IDLE_PROMPT_MINUTES=-5');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertTrue($cfg->disabled);
    }

    public function test_custom_prompt_minutes(): void
    {
        putenv('JETTY_SHARE_IDLE_PROMPT_MINUTES=30');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertFalse($cfg->disabled);
        $this->assertSame(30, $cfg->promptMinutes);
    }

    public function test_custom_grace_minutes(): void
    {
        putenv('JETTY_SHARE_IDLE_GRACE_MINUTES=10');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertSame(10, $cfg->graceMinutes);
    }

    public function test_grace_minutes_zero_is_falsy_falls_to_default(): void
    {
        // PHP's ?: treats '0' as falsy, so it falls back to the default 60
        putenv('JETTY_SHARE_IDLE_GRACE_MINUTES=0');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertSame(60, $cfg->graceMinutes);
    }

    public function test_grace_minutes_negative_floored_at_one(): void
    {
        putenv('JETTY_SHARE_IDLE_GRACE_MINUTES=-10');

        $cfg = ShareIdleConfig::fromEnvironment();

        $this->assertSame(1, $cfg->graceMinutes);
    }
}
