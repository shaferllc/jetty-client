<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\LocalDevDetector;
use JettyCli\TunnelResponseRewriter;
use JettyCli\TunnelRewriteOptions;
use PHPUnit\Framework\TestCase;

final class TunnelResponseRewriterTest extends TestCase
{
    protected function tearDown(): void
    {
        TunnelResponseRewriter::resetWalkUpAdjacentAppUrlCacheForTesting();
        parent::tearDown();
    }

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

    public function test_rewrite_protocol_relative_url_to_tunnel(): void
    {
        $lookup = ['beacon.test' => true];
        $out = TunnelResponseRewriter::rewriteAbsoluteUrlToTunnel(
            '//beacon.test/dashboard?x=1',
            $lookup,
            'cove-kfkt2nyd.tunnels.usejetty.online'
        );
        $this->assertSame('https://cove-kfkt2nyd.tunnels.usejetty.online/dashboard?x=1', $out);
    }

    public function test_rewrite_root_relative_path_not_rewritten(): void
    {
        $lookup = ['beacon.test' => true];
        $out = TunnelResponseRewriter::rewriteAbsoluteUrlToTunnel(
            '/dashboard',
            $lookup,
            'cove-kfkt2nyd.tunnels.usejetty.online'
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
        ], $req, $lookup, '127.0.0.1');
        $this->assertSame('https://t.tunnels.x/', $res['Location']);
        $this->assertSame('0; url=https://t.tunnels.x/x', $res['Refresh']);
    }

    public function test_expose_style_location_fallback_when_host_missing_from_lookup(): void
    {
        $lookup = ['other.test' => true];
        $req = ['Host' => 'lbl.tunnels.example'];
        $res = TunnelResponseRewriter::rewriteRedirectHeaders([
            'Location' => 'https://beacon.test/dashboard',
        ], $req, $lookup, 'beacon.test');
        $this->assertSame('https://lbl.tunnels.example/dashboard', $res['Location']);
    }

    public function test_expose_style_location_fallback_respects_opt_out_env(): void
    {
        $prev = getenv('JETTY_SHARE_NO_EXPOSE_STYLE_LOCATION');
        putenv('JETTY_SHARE_NO_EXPOSE_STYLE_LOCATION=1');
        try {
            $lookup = ['other.test' => true];
            $req = ['Host' => 'lbl.tunnels.example'];
            $res = TunnelResponseRewriter::rewriteRedirectHeaders([
                'Location' => 'https://beacon.test/dashboard',
            ], $req, $lookup, 'beacon.test');
            $this->assertSame('https://beacon.test/dashboard', $res['Location']);
        } finally {
            if ($prev === false) {
                putenv('JETTY_SHARE_NO_EXPOSE_STYLE_LOCATION');
            } else {
                putenv('JETTY_SHARE_NO_EXPOSE_STYLE_LOCATION='.$prev);
            }
        }
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

    public function test_tunnel_rewrite_host_lookup_merges_jetty_share_project_root_env(): void
    {
        $prev = getenv('JETTY_SHARE_PROJECT_ROOT');
        $tmpdir = sys_get_temp_dir().'/jetty-tr-'.uniqid('', true);
        mkdir($tmpdir, 0700, true);
        file_put_contents($tmpdir.'/.env', "APP_URL=https://fixture-from-project-root.test\n");

        putenv('JETTY_SHARE_PROJECT_ROOT='.$tmpdir);
        try {
            $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup('127.0.0.1');
            $this->assertArrayHasKey('fixture-from-project-root.test', $lookup);
        } finally {
            if ($prev === false) {
                putenv('JETTY_SHARE_PROJECT_ROOT');
            } else {
                putenv('JETTY_SHARE_PROJECT_ROOT='.$prev);
            }
            unlink($tmpdir.'/.env');
            rmdir($tmpdir);
        }
    }

    public function test_tunnel_rewrite_host_lookup_walk_up_finds_peer_artisan_app(): void
    {
        $prevInv = getenv('JETTY_SHARE_INVOCATION_CWD');
        $base = sys_get_temp_dir().'/jetty-walk-'.uniqid('', true);
        mkdir($base.'/peer-app', 0700, true);
        file_put_contents($base.'/peer-app/artisan', "<?php\n");
        file_put_contents($base.'/peer-app/.env', "APP_URL=https://fixture-peer-walk.test\n");
        mkdir($base.'/z/nested/jetty-client', 0700, true);

        putenv('JETTY_SHARE_INVOCATION_CWD='.$base.'/z/nested/jetty-client');
        try {
            $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup('127.0.0.1');
            $this->assertArrayHasKey('fixture-peer-walk.test', $lookup);
        } finally {
            if ($prevInv === false) {
                putenv('JETTY_SHARE_INVOCATION_CWD');
            } else {
                putenv('JETTY_SHARE_INVOCATION_CWD='.$prevInv);
            }
            unlink($base.'/peer-app/.env');
            unlink($base.'/peer-app/artisan');
            rmdir($base.'/peer-app');
            rmdir($base.'/z/nested/jetty-client');
            rmdir($base.'/z/nested');
            rmdir($base.'/z');
            rmdir($base);
        }
    }

    public function test_adjacent_artisan_scan_includes_artisan_at_scan_root(): void
    {
        $base = sys_get_temp_dir().'/jetty-root-artisan-'.uniqid('', true);
        mkdir($base, 0700, true);
        file_put_contents($base.'/artisan', "<?php\n");
        file_put_contents($base.'/.env', "APP_URL=https://fixture-root-artisan.test\n");
        try {
            $hosts = LocalDevDetector::appUrlHostsFromAdjacentArtisanProjects($base);
            $this->assertContains('fixture-root-artisan.test', $hosts);
        } finally {
            unlink($base.'/.env');
            unlink($base.'/artisan');
            rmdir($base);
        }
    }

    public function test_tunnel_rewrite_lookup_merges_jetty_share_cli_upstream_hostname(): void
    {
        $prev = getenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
        putenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME=fixture-cli-upstream.test');
        try {
            $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup('127.0.0.1');
            $this->assertArrayHasKey('fixture-cli-upstream.test', $lookup);
        } finally {
            if ($prev === false) {
                putenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
            } else {
                putenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME='.$prev);
            }
        }
    }

    public function test_emit_debug_ndjson_appends_json_line_when_env_set(): void
    {
        $prev = getenv('JETTY_SHARE_DEBUG_NDJSON_FILE');
        $tmp = sys_get_temp_dir().'/jetty-ndj-'.uniqid('', true).'.ndjson';
        putenv('JETTY_SHARE_DEBUG_NDJSON_FILE='.$tmp);
        try {
            TunnelResponseRewriter::emitDebugNdjson('test.event', ['k' => 'v']);
            $this->assertFileExists($tmp);
            $line = trim((string) file_get_contents($tmp));
            $dec = json_decode($line, true);
            $this->assertIsArray($dec);
            $this->assertSame('test.event', $dec['event']);
            $this->assertSame('v', $dec['data']['k']);
            $this->assertArrayHasKey('ts_ms', $dec);
            $this->assertSame(TunnelResponseRewriter::REWRITE_DEBUG_REV, $dec['rewrite_debug_rev']);
        } finally {
            @unlink($tmp);
            if ($prev === false) {
                putenv('JETTY_SHARE_DEBUG_NDJSON_FILE');
            } else {
                putenv('JETTY_SHARE_DEBUG_NDJSON_FILE='.$prev);
            }
        }
    }
}
