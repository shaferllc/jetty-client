<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgent;
use PHPUnit\Framework\TestCase;

final class EdgeAgentPingIntervalTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_SHARE_WS_PING_INTERVAL');
        putenv('JETTY_SHARE_NO_WS_PING');
        parent::tearDown();
    }

    public function test_defaults_to_eight_seconds(): void
    {
        putenv('JETTY_SHARE_WS_PING_INTERVAL');
        $this->assertSame(8.0, EdgeAgent::websocketPingIntervalSeconds());
    }

    public function test_respects_numeric_env(): void
    {
        putenv('JETTY_SHARE_WS_PING_INTERVAL=15');
        $this->assertSame(15.0, EdgeAgent::websocketPingIntervalSeconds());
    }

    public function test_invalid_env_falls_back(): void
    {
        putenv('JETTY_SHARE_WS_PING_INTERVAL=not-a-number');
        $this->assertSame(8.0, EdgeAgent::websocketPingIntervalSeconds());

        putenv('JETTY_SHARE_WS_PING_INTERVAL=1');
        $this->assertSame(8.0, EdgeAgent::websocketPingIntervalSeconds());

        putenv('JETTY_SHARE_WS_PING_INTERVAL=200');
        $this->assertSame(8.0, EdgeAgent::websocketPingIntervalSeconds());
    }
}
