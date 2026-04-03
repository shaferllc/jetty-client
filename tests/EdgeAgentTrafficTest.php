<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgent;
use PHPUnit\Framework\TestCase;

final class EdgeAgentTrafficTest extends TestCase
{
    protected function setUp(): void
    {
        // Flush any leftover state from previous tests.
        EdgeAgent::flushTrafficDeltas();
    }

    public function test_record_traffic_accumulates_bytes(): void
    {
        EdgeAgent::recordTraffic(100, 200);
        EdgeAgent::recordTraffic(50, 75);

        $deltas = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(2, $deltas['requests']);
        $this->assertSame(150, $deltas['bytes_in']);
        $this->assertSame(275, $deltas['bytes_out']);
    }

    public function test_flush_resets_counters_to_zero(): void
    {
        EdgeAgent::recordTraffic(100, 200);
        EdgeAgent::flushTrafficDeltas();

        $deltas = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(0, $deltas['requests']);
        $this->assertSame(0, $deltas['bytes_in']);
        $this->assertSame(0, $deltas['bytes_out']);
    }

    public function test_multiple_records_then_flush_returns_sum(): void
    {
        EdgeAgent::recordTraffic(10, 20);
        EdgeAgent::recordTraffic(30, 40);
        EdgeAgent::recordTraffic(50, 60);

        $deltas = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(3, $deltas['requests']);
        $this->assertSame(90, $deltas['bytes_in']);
        $this->assertSame(120, $deltas['bytes_out']);
    }

    public function test_flush_with_no_traffic_returns_zeros(): void
    {
        $deltas = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(0, $deltas['requests']);
        $this->assertSame(0, $deltas['bytes_in']);
        $this->assertSame(0, $deltas['bytes_out']);
    }

    public function test_second_flush_after_traffic_returns_zeros(): void
    {
        EdgeAgent::recordTraffic(500, 1000);
        $first = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(1, $first['requests']);
        $this->assertSame(500, $first['bytes_in']);
        $this->assertSame(1000, $first['bytes_out']);

        $second = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(0, $second['requests']);
        $this->assertSame(0, $second['bytes_in']);
        $this->assertSame(0, $second['bytes_out']);
    }
}
