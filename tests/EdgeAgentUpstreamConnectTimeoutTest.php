<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgent;
use PHPUnit\Framework\TestCase;

final class EdgeAgentUpstreamConnectTimeoutTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT');
        parent::tearDown();
    }

    public function test_default_ten(): void
    {
        putenv('JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT');
        $this->assertSame(10, EdgeAgent::upstreamConnectTimeoutSeconds());
    }

    public function test_clamped(): void
    {
        putenv('JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT=200');
        $this->assertSame(120, EdgeAgent::upstreamConnectTimeoutSeconds());

        putenv('JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT=0');
        $this->assertSame(1, EdgeAgent::upstreamConnectTimeoutSeconds());
    }
}
