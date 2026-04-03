<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgent;
use JettyCli\TunnelResponseRewriter;
use JettyCli\TunnelRewriteOptions;
use JettyCli\TunnelUpstreamRequestHeaders;
use PHPUnit\Framework\TestCase;

/**
 * Integration-level tests that exercise the full tunnel HTTP request/response
 * pipeline (header building, response rewriting, traffic counting, Vite detection)
 * without a real WebSocket connection or edge server.
 */
final class MockTunnelIntegrationTest extends TestCase
{
    private const LOCAL_HOST = 'myapp.test';

    private const LOCAL_PORT = 80;

    private const TUNNEL_HOST = 'demo-abc123.tunnels.usejetty.online';

    protected function setUp(): void
    {
        // Reset static traffic counters so tests are independent.
        EdgeAgent::flushTrafficDeltas();
    }

    protected function tearDown(): void
    {
        TunnelResponseRewriter::resetWalkUpAdjacentAppUrlCacheForTesting();

        // Clean up env vars that individual tests may have set.
        putenv('JETTY_SHARE_REWRITE_HOSTS');
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN');
        putenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
        putenv('JETTY_SHARE_NO_EXPOSE_STYLE_LOCATION');
        putenv('JETTY_SHARE_PROJECT_ROOT');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Scenario 1: Full rewrite pipeline (unit-level, no HTTP)
    // ------------------------------------------------------------------

    public function test_full_pipeline_upstream_headers_strip_accept_encoding(): void
    {
        $edgeHeaders = [
            'Host' => self::TUNNEL_HOST,
            'Accept' => 'text/html',
            'Accept-Encoding' => 'gzip, deflate, br',
            'User-Agent' => 'Mozilla/5.0',
        ];

        $upstream = TunnelUpstreamRequestHeaders::forLocalUpstream(
            $edgeHeaders,
            self::LOCAL_HOST,
            self::LOCAL_PORT,
        );

        $this->assertArrayNotHasKey('Accept-Encoding', $upstream, 'Accept-Encoding must be stripped for body rewriting');
        $this->assertSame(self::LOCAL_HOST, $upstream['Host']);
        $this->assertSame(self::TUNNEL_HOST, $upstream['X-Forwarded-Host']);
        $this->assertSame('https', $upstream['X-Forwarded-Proto']);
        $this->assertSame('text/html', $upstream['Accept']);
    }

    public function test_full_pipeline_html_body_rewrite(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $htmlBody = <<<HTML
        <!DOCTYPE html>
        <html>
        <head><link href="https://{$localHost}/css/app.css" rel="stylesheet"></head>
        <body>
            <a href="https://{$localHost}/dashboard">Dashboard</a>
            <img src="https://{$localHost}/images/logo.png">
            <script src="https://{$localHost}/js/app.js"></script>
        </body>
        </html>
        HTML;

        $requestHeaders = ['Host' => $tunnelHost];
        $responseHeaders = ['Content-Type' => 'text/html; charset=utf-8'];

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: true,
        );

        $rewritten = TunnelResponseRewriter::maybeRewriteBody(
            $htmlBody,
            $responseHeaders,
            $requestHeaders,
            $localHost,
            $options,
        );

        $this->assertStringContainsString("https://{$tunnelHost}/css/app.css", $rewritten);
        $this->assertStringContainsString("https://{$tunnelHost}/dashboard", $rewritten);
        $this->assertStringContainsString("https://{$tunnelHost}/images/logo.png", $rewritten);
        $this->assertStringContainsString("https://{$tunnelHost}/js/app.js", $rewritten);

        // Original local URLs should be gone.
        $this->assertStringNotContainsString("https://{$localHost}/css/app.css", $rewritten);
        $this->assertStringNotContainsString("https://{$localHost}/dashboard", $rewritten);
    }

    public function test_full_pipeline_redirect_header_rewrite(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $lookup = [$localHost => true, 'www.'.$localHost => true];
        $requestHeaders = ['Host' => $tunnelHost];

        $responseHeaders = [
            'Location' => "https://{$localHost}/login",
            'X-Custom' => 'preserved',
        ];

        $rewritten = TunnelResponseRewriter::rewriteRedirectHeaders(
            $responseHeaders,
            $requestHeaders,
            $lookup,
            $localHost,
        );

        $this->assertSame("https://{$tunnelHost}/login", $rewritten['Location']);
        $this->assertSame('preserved', $rewritten['X-Custom']);
    }

    // ------------------------------------------------------------------
    // Scenario 2: Traffic counting
    // ------------------------------------------------------------------

    public function test_traffic_counting_accumulates_and_flushes(): void
    {
        EdgeAgent::recordTraffic(512, 1024);
        EdgeAgent::recordTraffic(256, 2048);
        EdgeAgent::recordTraffic(128, 512);

        $deltas = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(3, $deltas['requests']);
        $this->assertSame(512 + 256 + 128, $deltas['bytes_in']);
        $this->assertSame(1024 + 2048 + 512, $deltas['bytes_out']);

        // Second flush should return zeros.
        $second = EdgeAgent::flushTrafficDeltas();
        $this->assertSame(0, $second['requests']);
        $this->assertSame(0, $second['bytes_in']);
        $this->assertSame(0, $second['bytes_out']);
    }

    public function test_traffic_counting_interleaved_flushes(): void
    {
        EdgeAgent::recordTraffic(100, 200);
        $first = EdgeAgent::flushTrafficDeltas();

        EdgeAgent::recordTraffic(300, 400);
        EdgeAgent::recordTraffic(500, 600);
        $second = EdgeAgent::flushTrafficDeltas();

        $this->assertSame(1, $first['requests']);
        $this->assertSame(100, $first['bytes_in']);

        $this->assertSame(2, $second['requests']);
        $this->assertSame(800, $second['bytes_in']);
        $this->assertSame(1000, $second['bytes_out']);
    }

    // ------------------------------------------------------------------
    // Scenario 3: Vite detection in pipeline
    // ------------------------------------------------------------------

    public function test_vite_detection_finds_vite_client_urls(): void
    {
        $localHost = self::LOCAL_HOST;
        $lookup = [$localHost => true, 'www.'.$localHost => true];

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <script type="module" src="https://{$localHost}:5174/@vite/client"></script>
            <link href="https://{$localHost}:5174/resources/css/app.css" rel="stylesheet">
        </head>
        <body><h1>Hello</h1></body>
        </html>
        HTML;

        $hits = TunnelResponseRewriter::detectViteDevServerUrls($html, $lookup, self::LOCAL_PORT);

        $this->assertNotEmpty($hits, 'Should detect Vite dev server URLs');
        $this->assertSame(5174, $hits[0]['port']);
        $this->assertTrue($hits[0]['vite_hint']);
        $this->assertSame($localHost, $hits[0]['host']);
    }

    public function test_vite_banner_injection(): void
    {
        $hits = [
            [
                'url' => 'https://myapp.test:5174/@vite/client',
                'host' => self::LOCAL_HOST,
                'port' => 5174,
                'vite_hint' => true,
            ],
        ];

        $html = '<html><body><h1>Hello</h1></body></html>';
        $result = TunnelResponseRewriter::injectViteDevServerBanner(
            $html,
            $hits,
            self::LOCAL_HOST,
            self::LOCAL_PORT,
        );

        $this->assertStringContainsString('jetty-vite-banner', $result);
        $this->assertStringContainsString('5174', $result);
        $this->assertStringContainsString('npm run build', $result);
        // Banner should be before </body>.
        $bannerPos = strpos($result, 'jetty-vite-banner');
        $bodyEndPos = strpos($result, '</body>');
        $this->assertNotFalse($bannerPos);
        $this->assertNotFalse($bodyEndPos);
        $this->assertLessThan($bodyEndPos, $bannerPos, 'Banner should appear before </body>');
    }

    public function test_vite_detection_ignores_same_port_as_upstream(): void
    {
        $localHost = self::LOCAL_HOST;
        $lookup = [$localHost => true];

        // URLs on port 80 (same as upstream) should NOT be flagged.
        $html = '<script src="https://'.$localHost.':80/app.js"></script>';
        $hits = TunnelResponseRewriter::detectViteDevServerUrls($html, $lookup, 80);

        $this->assertEmpty($hits, 'Same port as upstream should not be flagged');
    }

    // ------------------------------------------------------------------
    // Scenario 4: JavaScript rewriting
    // ------------------------------------------------------------------

    public function test_js_body_rewrite(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $jsBody = <<<JS
        var apiBase = "https://{$localHost}/api/v1";
        var wsUrl = 'https://{$localHost}/ws/connect';
        var external = "https://cdn.example.com/lib.js";
        JS;

        $requestHeaders = ['Host' => $tunnelHost];
        $responseHeaders = ['Content-Type' => 'application/javascript'];

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: true,
        );

        $rewritten = TunnelResponseRewriter::maybeRewriteBody(
            $jsBody,
            $responseHeaders,
            $requestHeaders,
            $localHost,
            $options,
        );

        $this->assertStringContainsString("\"https://{$tunnelHost}/api/v1\"", $rewritten);
        $this->assertStringContainsString("'https://{$tunnelHost}/ws/connect'", $rewritten);
        // External URL should not be rewritten.
        $this->assertStringContainsString('"https://cdn.example.com/lib.js"', $rewritten);
        // Original local URLs should be gone.
        $this->assertStringNotContainsString("\"https://{$localHost}/api/v1\"", $rewritten);
    }

    public function test_js_rewrite_disabled_leaves_body_unchanged(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $jsBody = "var url = \"https://{$localHost}/api\";";

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: false,
        );

        $rewritten = TunnelResponseRewriter::maybeRewriteBody(
            $jsBody,
            ['Content-Type' => 'application/javascript'],
            ['Host' => $tunnelHost],
            $localHost,
            $options,
        );

        // JS rewrite off => body unchanged.
        $this->assertSame($jsBody, $rewritten);
    }

    // ------------------------------------------------------------------
    // Scenario 5: Redirect header rewriting
    // ------------------------------------------------------------------

    public function test_redirect_location_rewrite_with_query_and_fragment(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $lookup = [$localHost => true, 'www.'.$localHost => true];
        $requestHeaders = ['Host' => $tunnelHost];

        $responseHeaders = [
            'Location' => "https://{$localHost}/callback?code=abc123&state=xyz#section",
        ];

        $rewritten = TunnelResponseRewriter::rewriteRedirectHeaders(
            $responseHeaders,
            $requestHeaders,
            $lookup,
            $localHost,
        );

        $this->assertSame(
            "https://{$tunnelHost}/callback?code=abc123&state=xyz#section",
            $rewritten['Location'],
        );
    }

    public function test_redirect_x_inertia_location_rewrite(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $lookup = [$localHost => true, 'www.'.$localHost => true];
        $requestHeaders = ['Host' => $tunnelHost];

        $responseHeaders = [
            'X-Inertia-Location' => "https://{$localHost}/admin/users",
        ];

        $rewritten = TunnelResponseRewriter::rewriteRedirectHeaders(
            $responseHeaders,
            $requestHeaders,
            $lookup,
            $localHost,
        );

        $this->assertSame(
            "https://{$tunnelHost}/admin/users",
            $rewritten['X-Inertia-Location'],
        );
    }

    public function test_redirect_unknown_host_not_rewritten(): void
    {
        $lookup = [self::LOCAL_HOST => true];
        $requestHeaders = ['Host' => self::TUNNEL_HOST];

        $responseHeaders = [
            'Location' => 'https://external.example.com/oauth/callback',
        ];

        $rewritten = TunnelResponseRewriter::rewriteRedirectHeaders(
            $responseHeaders,
            $requestHeaders,
            $lookup,
        );

        $this->assertSame(
            'https://external.example.com/oauth/callback',
            $rewritten['Location'],
            'External hosts should not be rewritten',
        );
    }

    // ------------------------------------------------------------------
    // Scenario 6: CSS url() rewriting
    // ------------------------------------------------------------------

    public function test_css_url_rewrite_in_inline_style(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <div style="background: url('https://{$localHost}/images/bg.jpg') no-repeat center">
                Content
            </div>
        </body>
        </html>
        HTML;

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: true,
        );

        $rewritten = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html'],
            ['Host' => $tunnelHost],
            $localHost,
            $options,
        );

        $this->assertStringContainsString("https://{$tunnelHost}/images/bg.jpg", $rewritten);
        $this->assertStringNotContainsString("https://{$localHost}/images/bg.jpg", $rewritten);
    }

    public function test_css_url_rewrite_in_style_block(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <style>
            .hero { background-image: url("https://{$localHost}/images/hero.webp"); }
            .icon { background: url(https://{$localHost}/icons/star.svg) no-repeat; }
        </style>
        </head>
        <body></body>
        </html>
        HTML;

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: true,
        );

        $rewritten = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html'],
            ['Host' => $tunnelHost],
            $localHost,
            $options,
        );

        $this->assertStringContainsString("https://{$tunnelHost}/images/hero.webp", $rewritten);
        $this->assertStringContainsString("https://{$tunnelHost}/icons/star.svg", $rewritten);
        $this->assertStringNotContainsString("https://{$localHost}/images/hero.webp", $rewritten);
        $this->assertStringNotContainsString("https://{$localHost}/icons/star.svg", $rewritten);
    }

    public function test_css_rewrite_disabled_leaves_style_urls(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $tunnelHost = self::TUNNEL_HOST;

        $html = <<<HTML
        <!DOCTYPE html>
        <html>
        <body>
            <div style="background: url('https://{$localHost}/bg.jpg')">x</div>
        </body>
        </html>
        HTML;

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: false,
        );

        $rewritten = TunnelResponseRewriter::maybeRewriteBody(
            $html,
            ['Content-Type' => 'text/html'],
            ['Host' => $tunnelHost],
            $localHost,
            $options,
        );

        // CSS rewrite disabled => inline style url() should NOT be rewritten.
        $this->assertStringContainsString("https://{$localHost}/bg.jpg", $rewritten);
    }

    // ------------------------------------------------------------------
    // End-to-end pipeline: headers + body rewrite in sequence
    // ------------------------------------------------------------------

    public function test_end_to_end_pipeline_headers_then_body(): void
    {
        putenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN=1');

        $localHost = self::LOCAL_HOST;
        $localPort = self::LOCAL_PORT;
        $tunnelHost = self::TUNNEL_HOST;

        // Step 1: Build upstream request headers (as EdgeAgent would).
        $edgeHeaders = [
            'Host' => $tunnelHost,
            'Accept' => 'text/html',
            'Accept-Encoding' => 'gzip, deflate',
            'Connection' => 'keep-alive',
        ];

        $upstreamHeaders = TunnelUpstreamRequestHeaders::forLocalUpstream(
            $edgeHeaders,
            $localHost,
            $localPort,
        );

        // Hop-by-hop and Accept-Encoding stripped.
        $this->assertArrayNotHasKey('Connection', $upstreamHeaders);
        $this->assertArrayNotHasKey('Accept-Encoding', $upstreamHeaders);
        $this->assertSame($localHost, $upstreamHeaders['Host']);

        // Step 2: Simulate upstream response with redirect + HTML body.
        $responseHeaders = [
            'Content-Type' => 'text/html; charset=utf-8',
            'Location' => "https://{$localHost}/redirected",
        ];

        $lookup = [$localHost => true, 'www.'.$localHost => true];
        $requestHeaders = ['Host' => $tunnelHost];

        $rewrittenHeaders = TunnelResponseRewriter::rewriteRedirectHeaders(
            $responseHeaders,
            $requestHeaders,
            $lookup,
            $localHost,
        );
        $this->assertSame("https://{$tunnelHost}/redirected", $rewrittenHeaders['Location']);

        // Step 3: Rewrite body.
        $body = <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <link href="https://{$localHost}/css/app.css" rel="stylesheet">
        </head>
        <body>
            <a href="https://{$localHost}/profile">Profile</a>
            <script>var api = "https://{$localHost}/api";</script>
            <style>.bg { background: url("https://{$localHost}/bg.png"); }</style>
        </body>
        </html>
        HTML;

        $options = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: true,
        );

        $rewrittenBody = TunnelResponseRewriter::maybeRewriteBody(
            $body,
            $rewrittenHeaders,
            $requestHeaders,
            $localHost,
            $options,
        );

        // All local URLs replaced.
        $this->assertStringNotContainsString("https://{$localHost}/", $rewrittenBody);
        $this->assertStringContainsString("https://{$tunnelHost}/css/app.css", $rewrittenBody);
        $this->assertStringContainsString("https://{$tunnelHost}/profile", $rewrittenBody);
        $this->assertStringContainsString("https://{$tunnelHost}/api", $rewrittenBody);
        $this->assertStringContainsString("https://{$tunnelHost}/bg.png", $rewrittenBody);

        // Step 4: Record traffic.
        $bytesIn = strlen($body);
        $bytesOut = strlen($rewrittenBody);
        EdgeAgent::recordTraffic($bytesIn, $bytesOut);

        $deltas = EdgeAgent::flushTrafficDeltas();
        $this->assertSame(1, $deltas['requests']);
        $this->assertSame($bytesIn, $deltas['bytes_in']);
        $this->assertSame($bytesOut, $deltas['bytes_out']);
    }
}
