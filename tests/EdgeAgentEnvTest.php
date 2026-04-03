<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgent;
use PHPUnit\Framework\TestCase;

final class EdgeAgentEnvTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_SHARE_NO_EDGE_RECONNECT');
        putenv('JETTY_SHARE_CAPTURE_SAMPLES');
    }

    // --- edgeReconnectEnabled ---

    public function test_edge_reconnect_enabled_by_default(): void
    {
        putenv('JETTY_SHARE_NO_EDGE_RECONNECT');

        $this->assertTrue(EdgeAgent::edgeReconnectEnabled());
    }

    public function test_edge_reconnect_disabled_when_set_to_one(): void
    {
        putenv('JETTY_SHARE_NO_EDGE_RECONNECT=1');

        $this->assertFalse(EdgeAgent::edgeReconnectEnabled());
    }

    public function test_edge_reconnect_enabled_for_other_values(): void
    {
        putenv('JETTY_SHARE_NO_EDGE_RECONNECT=0');

        $this->assertTrue(EdgeAgent::edgeReconnectEnabled());
    }

    // --- captureSamplesEnabled ---

    public function test_capture_samples_enabled_by_default(): void
    {
        putenv('JETTY_SHARE_CAPTURE_SAMPLES');

        $this->assertTrue(EdgeAgent::captureSamplesEnabled());
    }

    public function test_capture_samples_disabled_when_set_to_zero(): void
    {
        putenv('JETTY_SHARE_CAPTURE_SAMPLES=0');

        $this->assertFalse(EdgeAgent::captureSamplesEnabled());
    }

    public function test_capture_samples_enabled_for_other_values(): void
    {
        putenv('JETTY_SHARE_CAPTURE_SAMPLES=1');

        $this->assertTrue(EdgeAgent::captureSamplesEnabled());
    }
}
