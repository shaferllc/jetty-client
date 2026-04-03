<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\TunnelResponseRewriter;
use JettyCli\TunnelRewriteOptions;
use PHPUnit\Framework\TestCase;

final class TunnelResponseRewriterTest extends TestCase
{
    public function test_rewrite_absolute_url_to_tunnel(): void
    {
        $lookup = ['127.0.0.1' => true];
        $out = TunnelResponseRewriter::rewriteAbsoluteUrlToTunnel(
            'https://127.0.0.1:8000/path?q=1#x',
            $lookup,
            'lbl.tunnels.usejetty.online'
        );
        $this->assertSame('https://lbl.tunnels.usejetty.online/path?q=1#x', $out);
    }

    public function test_rewrite_absolute_url_unknown_host_returns_null(): void
    {
        $lookup = ['127.0.0.1' => true];
        $out = TunnelResponseRewriter::rewriteAbsoluteUrlToTunnel(
            'https://evil.example/',
            $lookup,
            'lbl.tunnels.usejetty.online'
        );
        $this->assertNull($out);
    }

    public function test_refresh_header_rewrite(): void
    {
        $lookup = ['127.0.0.1' => true];
        $out = TunnelResponseRewriter::rewriteRefreshHeaderValue(
            '5; url=https://127.0.0.1:8000/dashboard',
            $lookup,
            'lbl.tunnels.usejetty.online'
        );
        $this->assertSame('5; url=https://lbl.tunnels.usejetty.online/dashboard', $out);
    }

    public function test_redirect_headers_location_and_refresh(): void
    {
        $lookup = ['127.0.0.1' => true];
        $req = ['Host' => 't.tunnels.x'];
        $res = TunnelResponseRewriter::rewriteRedirectHeaders([
            'Location' => 'https://127.0.0.1:8000/',
            'Refresh' => '0; url=https://127.0.0.1:8000/x',
        ], $req, $lookup);
        $this->assertSame('https://t.tunnels.x/', $res['Location']);
        $this->assertSame('0; url=https://t.tunnels.x/x', $res['Refresh']);
    }

    public function test_maybe_rewrite_body_html_href(): void
    {
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
            .'<a href="https://127.0.0.1:8000/page">x</a></body></html>';
        $opts = new TunnelRewriteOptions(true, false, false);
        $out = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html; charset=UTF-8'],
            ['Host' => 'lbl.tunnels.usejetty.online'],
            '127.0.0.1',
            $opts
        );
        $this->assertStringContainsString('href="https://lbl.tunnels.usejetty.online/page"', $out);
    }

    public function test_maybe_rewrite_body_skips_when_body_rewrite_off(): void
    {
        $html = '<html><body><a href="https://127.0.0.1:8000/">x</a></body></html>';
        $opts = new TunnelRewriteOptions(false, true, true);
        $out = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html'],
            ['Host' => 'lbl.tunnels.usejetty.online'],
            '127.0.0.1',
            $opts
        );
        $this->assertSame($html, $out);
    }

    public function test_maybe_rewrite_inline_script_quoted_urls(): void
    {
        $html = '<html><body><script>var u = "https://127.0.0.1:8000/api";</script></body></html>';
        $opts = new TunnelRewriteOptions(true, true, false);
        $out = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html'],
            ['Host' => 'lbl.tunnels.usejetty.online'],
            '127.0.0.1',
            $opts
        );
        $this->assertStringContainsString('"https://lbl.tunnels.usejetty.online/api"', $out);
    }

    public function test_maybe_rewrite_css_url_in_style(): void
    {
        $html = '<html><head><style>body{background:url(https://127.0.0.1:8000/a.png)}</style></head><body></body></html>';
        $opts = new TunnelRewriteOptions(true, false, true);
        $out = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html'],
            ['Host' => 'lbl.tunnels.usejetty.online'],
            '127.0.0.1',
            $opts
        );
        $this->assertStringContainsString('url(https://lbl.tunnels.usejetty.online/a.png)', $out);
    }

    public function test_maybe_rewrite_standalone_javascript_mime(): void
    {
        $js = 'export const x = "https://127.0.0.1:8000/y";';
        $opts = new TunnelRewriteOptions(true, true, false);
        $out = TunnelResponseRewriter::maybeRewriteBody(
            $js,
            ['Content-Type' => 'application/javascript'],
            ['Host' => 'lbl.tunnels.usejetty.online'],
            '127.0.0.1',
            $opts
        );
        $this->assertStringContainsString('"https://lbl.tunnels.usejetty.online/y"', $out);
    }

    public function test_tunnel_rewrite_host_lookup_includes_rewrite_hosts_env(): void
    {
        $prev = getenv('JETTY_SHARE_REWRITE_HOSTS');
        putenv('JETTY_SHARE_REWRITE_HOSTS=extra.dev,other.local');
        try {
            $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup('127.0.0.1');
            $this->assertArrayHasKey('extra.dev', $lookup);
            $this->assertArrayHasKey('other.local', $lookup);
        } finally {
            if ($prev === false) {
                putenv('JETTY_SHARE_REWRITE_HOSTS');
            } else {
                putenv('JETTY_SHARE_REWRITE_HOSTS='.$prev);
            }
        }
    }

    public function test_debug_rewrite_enabled_reads_server_when_getenv_unset(): void
    {
        $key = 'JETTY_SHARE_DEBUG_REWRITE';
        $prevGetenv = getenv($key);
        $prevServer = $_SERVER[$key] ?? null;
        putenv($key);
        $_SERVER[$key] = '1';

        try {
            $m = new \ReflectionMethod(TunnelResponseRewriter::class, 'debugRewriteEnabled');
            $this->assertTrue($m->invoke(null));
        } finally {
            unset($_SERVER[$key]);
            if ($prevServer !== null) {
                $_SERVER[$key] = $prevServer;
            }
            if ($prevGetenv !== false) {
                putenv($key.'='.$prevGetenv);
            } else {
                putenv($key);
            }
        }
    }
}
