<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\TunnelResumeMatcher;
use PHPUnit\Framework\TestCase;

final class TunnelResumeMatcherTest extends TestCase
{
    public function test_exact_match(): void
    {
        $tunnels = [
            ['id' => 1, 'local_target' => 'beacon.test:443', 'subdomain' => 'a', 'server' => null],
        ];
        $hit = TunnelResumeMatcher::findResumableTunnel($tunnels, 'beacon.test', 443, null, null, true);
        $this->assertSame(1, (int) ($hit['id'] ?? 0));
    }

    public function test_fallback_443_resumes_80_for_same_host(): void
    {
        $tunnels = [
            ['id' => 10, 'local_target' => 'beacon.test:80', 'subdomain' => 'cove', 'server' => null],
        ];
        $hit = TunnelResumeMatcher::findResumableTunnel($tunnels, 'beacon.test', 443, null, null, true);
        $this->assertSame(10, (int) ($hit['id'] ?? 0));
    }

    public function test_fallback_80_resumes_443(): void
    {
        $tunnels = [
            ['id' => 7, 'local_target' => 'beacon.test:443', 'subdomain' => 'x', 'server' => null],
        ];
        $hit = TunnelResumeMatcher::findResumableTunnel($tunnels, 'beacon.test', 80, null, null, true);
        $this->assertSame(7, (int) ($hit['id'] ?? 0));
    }

    public function test_no_fallback_for_loopback(): void
    {
        $tunnels = [
            ['id' => 1, 'local_target' => '127.0.0.1:80', 'subdomain' => 'a', 'server' => null],
        ];
        $hit = TunnelResumeMatcher::findResumableTunnel($tunnels, '127.0.0.1', 443, null, null, false);
        $this->assertNull($hit);
    }

    public function test_parse_local_target(): void
    {
        $this->assertSame(['host' => 'beacon.test', 'port' => 443], TunnelResumeMatcher::parseLocalTarget('beacon.test:443'));
        $this->assertNull(TunnelResumeMatcher::parseLocalTarget('nocolon'));
    }
}
