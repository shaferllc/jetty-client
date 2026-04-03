<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\TunnelUpstreamRequestHeaders;
use PHPUnit\Framework\TestCase;

final class TunnelUpstreamRequestHeadersTest extends TestCase
{
    public function test_replaces_tunnel_host_with_local_site_host(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
            'Accept' => 'text/html',
        ], 'beacon.test', 80);

        $this->assertSame('beacon.test', $out['Host']);
        $this->assertSame('lbl.tunnels.example.test', $out['X-Forwarded-Host']);
        $this->assertSame('https', $out['X-Forwarded-Proto']);
        $this->assertSame('text/html', $out['Accept']);
    }

    public function test_appends_port_when_not_80(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
        ], '127.0.0.1', 8000);

        $this->assertSame('127.0.0.1:8000', $out['Host']);
        $this->assertSame('lbl.tunnels.example.test', $out['X-Forwarded-Host']);
    }

    public function test_443_upstream_host_omits_port_suffix(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
        ], 'beacon.test', 443);

        $this->assertSame('beacon.test', $out['Host']);
        $this->assertSame('lbl.tunnels.example.test', $out['X-Forwarded-Host']);
    }

    public function test_does_not_set_x_forwarded_host_when_already_matches_upstream(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'beacon.test',
        ], 'beacon.test', 80);

        $this->assertSame('beacon.test', $out['Host']);
        $this->assertArrayNotHasKey('X-Forwarded-Host', $out);
    }

    public function test_preserves_existing_x_forwarded_proto(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
            'X-Forwarded-Proto' => 'http',
        ], 'beacon.test', 80);

        $this->assertSame('http', $out['X-Forwarded-Proto']);
    }
}
