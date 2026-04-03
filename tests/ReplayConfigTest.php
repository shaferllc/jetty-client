<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\ReplayConfig;
use PHPUnit\Framework\TestCase;

final class ReplayConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_REPLAY_ALLOW_UNSAFE');
    }

    public function test_unsafe_not_allowed_by_default(): void
    {
        putenv('JETTY_REPLAY_ALLOW_UNSAFE');

        $this->assertFalse(ReplayConfig::allowUnsafe());
    }

    public function test_unsafe_allowed_when_set_to_one(): void
    {
        putenv('JETTY_REPLAY_ALLOW_UNSAFE=1');

        $this->assertTrue(ReplayConfig::allowUnsafe());
    }

    public function test_unsafe_not_allowed_for_other_values(): void
    {
        putenv('JETTY_REPLAY_ALLOW_UNSAFE=yes');

        $this->assertFalse(ReplayConfig::allowUnsafe());
    }

    public function test_unsafe_not_allowed_when_zero(): void
    {
        putenv('JETTY_REPLAY_ALLOW_UNSAFE=0');

        $this->assertFalse(ReplayConfig::allowUnsafe());
    }
}
