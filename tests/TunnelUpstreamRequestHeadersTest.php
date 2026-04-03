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

    public function test_strips_accept_encoding_header(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
            'Accept' => 'text/html',
            'Accept-Encoding' => 'gzip, deflate, br',
        ], 'beacon.test', 80);

        $this->assertArrayNotHasKey('Accept-Encoding', $out);
        $this->assertSame('text/html', $out['Accept']);
    }

    public function test_strips_accept_encoding_case_insensitive(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
            'accept-encoding' => 'gzip',
        ], 'beacon.test', 80);

        $this->assertArrayNotHasKey('accept-encoding', $out);
        $this->assertArrayNotHasKey('Accept-Encoding', $out);

        $out2 = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
            'ACCEPT-ENCODING' => 'br',
        ], 'beacon.test', 80);

        $this->assertArrayNotHasKey('ACCEPT-ENCODING', $out2);
        $this->assertArrayNotHasKey('Accept-Encoding', $out2);
    }

    public function test_other_headers_preserved_when_accept_encoding_stripped(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
            'Accept' => 'text/html',
            'Accept-Language' => 'en-US',
            'Accept-Encoding' => 'gzip',
            'User-Agent' => 'Mozilla/5.0',
        ], 'beacon.test', 80);

        $this->assertArrayNotHasKey('Accept-Encoding', $out);
        $this->assertSame('text/html', $out['Accept']);
        $this->assertSame('en-US', $out['Accept-Language']);
        $this->assertSame('Mozilla/5.0', $out['User-Agent']);
    }

    public function test_strips_hop_by_hop_headers(): void
    {
        $hopByHop = [
            'Connection' => 'keep-alive',
            'Keep-Alive' => 'timeout=5',
            'Proxy-Connection' => 'keep-alive',
            'Transfer-Encoding' => 'chunked',
            'Upgrade' => 'websocket',
            'TE' => 'trailers',
            'Trailer' => 'Expires',
        ];
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream(
            array_merge(['Host' => 'lbl.tunnels.example.test', 'Accept' => 'text/html'], $hopByHop),
            'beacon.test',
            80
        );

        foreach (array_keys($hopByHop) as $header) {
            $this->assertArrayNotHasKey($header, $out, "Hop-by-hop header '$header' should be stripped");
        }
        $this->assertSame('text/html', $out['Accept']);
    }

    public function test_x_forwarded_proto_set_to_https_when_absent(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
        ], 'beacon.test', 80);

        $this->assertArrayHasKey('X-Forwarded-Proto', $out);
        $this->assertSame('https', $out['X-Forwarded-Proto']);
    }

    public function test_x_forwarded_host_set_when_host_differs_from_upstream(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'lbl.tunnels.example.test',
        ], 'beacon.test', 8000);

        $this->assertSame('lbl.tunnels.example.test', $out['X-Forwarded-Host']);
        $this->assertSame('beacon.test:8000', $out['Host']);
    }

    public function test_x_forwarded_host_not_set_when_host_matches_upstream(): void
    {
        $out = TunnelUpstreamRequestHeaders::forLocalUpstream([
            'Host' => 'beacon.test',
        ], 'beacon.test', 80);

        $this->assertArrayNotHasKey('X-Forwarded-Host', $out);
    }
}
