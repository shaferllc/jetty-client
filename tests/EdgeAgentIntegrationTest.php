<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use Amp\Socket\Socket;
use JettyCli\ApiClient;
use JettyCli\EdgeAgent;
use JettyCli\EdgeAgentResult;
use JettyCli\TunnelRewriteOptions;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration test for EdgeAgent::run().
 *
 * Spins up:
 *  - A fake WebSocket server (raw TCP + manual WS handshake via amphp/socket)
 *  - A fake HTTP upstream (PHP built-in server)
 *
 * Then calls EdgeAgent::run() and verifies the full pipeline:
 *  WS connect -> register -> receive http_request frame -> curl upstream ->
 *  rewrite response -> send http_response frame -> traffic counting.
 */
final class EdgeAgentIntegrationTest extends TestCase
{
    private const LOCAL_HOST = '127.0.0.1';

    private const TUNNEL_HOST = 'test-tunnel.tunnels.usejetty.online';

    private const AGENT_TOKEN = 'test-agent-token-abc123';

    private const TUNNEL_ID = 42;

    /** @var resource|null PHP built-in server process */
    private $upstreamProc = null;

    /** @var string|null Path to upstream document root tmp dir */
    private ?string $upstreamDocRoot = null;

    private int $upstreamPort = 0;

    /** @var array<string, string> Env vars to restore after test */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        EdgeAgent::flushTrafficDeltas();

        // Save and set env vars.
        $envVars = [
            'JETTY_SHARE_NO_EDGE_RECONNECT' => '1',
            'JETTY_SHARE_NO_WS_PING' => '1',
            'JETTY_SHARE_CAPTURE_SAMPLES' => '0',
            'JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN' => '1',
            'JETTY_SHARE_UPSTREAM_ALLOW_HOSTS' => '',
        ];
        foreach ($envVars as $key => $value) {
            $old = getenv($key);
            $this->savedEnv[$key] = $old === false ? '__UNSET__' : $old;
            putenv("{$key}={$value}");
        }
    }

    protected function tearDown(): void
    {
        // Stop the upstream PHP server.
        if ($this->upstreamProc !== null && is_resource($this->upstreamProc)) {
            $status = proc_get_status($this->upstreamProc);
            if ($status['running']) {
                $pid = $status['pid'];
                @exec("kill {$pid} 2>/dev/null");
                @exec("kill -9 {$pid} 2>/dev/null");
            }
            @proc_close($this->upstreamProc);
            $this->upstreamProc = null;
        }

        // Clean up tmp doc root.
        if ($this->upstreamDocRoot !== null && is_dir($this->upstreamDocRoot)) {
            @array_map('unlink', glob($this->upstreamDocRoot.'/*') ?: []);
            @rmdir($this->upstreamDocRoot);
            $this->upstreamDocRoot = null;
        }

        // Restore env vars.
        foreach ($this->savedEnv as $key => $old) {
            if ($old === '__UNSET__') {
                putenv($key);
            } else {
                putenv("{$key}={$old}");
            }
        }
        $this->savedEnv = [];

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Main integration test
    // ------------------------------------------------------------------

    public function test_full_ws_http_rewrite_response_pipeline(): void
    {
        // ---- 1. Start fake HTTP upstream ----
        $upstreamPort = $this->startFakeUpstream();

        // ---- 2. Start fake WebSocket server (amphp TCP) ----
        $wsServer = \Amp\Socket\listen('127.0.0.1:0');
        $wsAddr = $wsServer->getAddress();
        $wsPort = $wsAddr->getPort();
        $wsUrl = "ws://127.0.0.1:{$wsPort}/agent";

        // Captured data from the fake WS server side.
        $captured = new class
        {
            public ?array $registerFrame = null;

            public ?array $responseFrame = null;

            public bool $serverDone = false;

            public ?string $serverError = null;
        };

        // Buffered reader shared across readWsFrame calls for a single connection.
        $readBuf = new class
        {
            public string $buffer = '';
        };

        // Run the fake WS server as an async fiber.
        $serverFuture = \Amp\async(function () use ($wsServer, $captured, $readBuf): void {
            try {
                $client = $wsServer->accept();
                if ($client === null) {
                    $captured->serverError = 'accept returned null';

                    return;
                }

                try {
                    // Read the HTTP upgrade request.
                    $upgradeRequest = '';
                    while (! str_contains($upgradeRequest, "\r\n\r\n")) {
                        $chunk = $client->read();
                        if ($chunk === null) {
                            $captured->serverError = 'client disconnected during upgrade';

                            return;
                        }
                        $upgradeRequest .= $chunk;
                    }

                    // If the read included bytes after the header, push them into the buffer.
                    $headerEnd = strpos($upgradeRequest, "\r\n\r\n");
                    $readBuf->buffer = substr($upgradeRequest, $headerEnd + 4);
                    $upgradeRequest = substr($upgradeRequest, 0, $headerEnd + 4);

                    // Extract Sec-WebSocket-Key from the upgrade request.
                    $wsKey = $this->extractHeader($upgradeRequest, 'Sec-WebSocket-Key');
                    if ($wsKey === null) {
                        $captured->serverError = 'missing Sec-WebSocket-Key';
                        $client->close();

                        return;
                    }

                    // Send 101 Switching Protocols.
                    $acceptKey = base64_encode(sha1($wsKey.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
                    $response = "HTTP/1.1 101 Switching Protocols\r\n"
                        ."Upgrade: websocket\r\n"
                        ."Connection: Upgrade\r\n"
                        ."Sec-WebSocket-Accept: {$acceptKey}\r\n"
                        ."\r\n";
                    $client->write($response);

                    // ---- Read register frame from client ----
                    $registerPayload = $this->readWsFrame($client, $readBuf);
                    if ($registerPayload !== null) {
                        $captured->registerFrame = json_decode($registerPayload, true);
                    }

                    // ---- Send registered ack ----
                    $this->writeWsTextFrame($client, json_encode(['type' => 'registered']));

                    // ---- Send http_request frame ----
                    $httpRequest = [
                        'type' => 'http_request',
                        'request_id' => 'test-req-001',
                        'method' => 'GET',
                        'path' => '/',
                        'headers' => [
                            'Host' => self::TUNNEL_HOST,
                            'Accept' => 'text/html',
                            'User-Agent' => 'JettyTest/1.0',
                        ],
                        'body_b64' => '',
                        'query' => '',
                    ];
                    $this->writeWsTextFrame($client, json_encode($httpRequest));

                    // ---- Read http_response frame ----
                    $responsePayload = $this->readWsFrame($client, $readBuf);
                    if ($responsePayload !== null) {
                        $captured->responseFrame = json_decode($responsePayload, true);
                    }

                    // ---- Close the WebSocket (send close frame) ----
                    $this->writeWsCloseFrame($client);

                    // Read close ack briefly.
                    try {
                        $client->read();
                    } catch (\Throwable) {
                    }

                    $captured->serverDone = true;
                } catch (\Throwable $e) {
                    $captured->serverError = $e->getMessage();
                    $captured->serverDone = true;
                } finally {
                    $client->close();
                    $wsServer->close();
                }
            } catch (\Throwable $e) {
                $captured->serverError = 'outer: '.$e->getMessage();
                $captured->serverDone = true;
            }
        });

        // ---- 3. Create a stub ApiClient (heartbeat will fail, but that's OK) ----
        $apiClient = new ApiClient('http://127.0.0.1:1', 'fake-token');

        // ---- 4. Call EdgeAgent::run() ----
        $stderrLines = [];
        $stderr = function (string $msg) use (&$stderrLines): void {
            $stderrLines[] = $msg;
        };

        $rewriteOptions = new TunnelRewriteOptions(
            bodyRewrite: true,
            jsRewrite: true,
            cssRewrite: true,
        );

        $result = EdgeAgent::run(
            wsUrl: $wsUrl,
            tunnelId: self::TUNNEL_ID,
            agentToken: self::AGENT_TOKEN,
            localHost: self::LOCAL_HOST,
            localPort: $upstreamPort,
            apiClient: $apiClient,
            heartbeatTunnelId: self::TUNNEL_ID,
            stderr: $stderr,
            verbose: false,
            rewriteOptions: $rewriteOptions,
            publicTunnelHostForRewrite: self::TUNNEL_HOST,
        );

        // Wait for the server fiber to finish.
        $serverFuture->await();

        // ---- 5. Assertions ----

        // If the server had an error, report it.
        if ($captured->serverError !== null) {
            $this->fail('Fake WS server error: '.$captured->serverError);
        }

        // EdgeAgent should return Disconnected (WS closed, no reconnect).
        $this->assertSame(EdgeAgentResult::Disconnected, $result, 'EdgeAgent should return Disconnected when WS closes with NO_EDGE_RECONNECT=1');

        // The fake WS server should have completed.
        $this->assertTrue($captured->serverDone, 'Fake WS server should have completed the exchange');

        // Validate the register frame.
        $this->assertNotNull($captured->registerFrame, 'Should have received a register frame');
        $this->assertSame('register', $captured->registerFrame['type']);
        $this->assertSame(self::TUNNEL_ID, $captured->registerFrame['tunnel_id']);
        $this->assertSame(self::AGENT_TOKEN, $captured->registerFrame['agent_token']);

        // Validate the http_response frame.
        $this->assertNotNull($captured->responseFrame, 'Should have received an http_response frame');
        $this->assertSame('http_response', $captured->responseFrame['type']);
        $this->assertSame('test-req-001', $captured->responseFrame['request_id']);
        $this->assertSame(200, $captured->responseFrame['status']);
        $this->assertNotEmpty($captured->responseFrame['body_b64'], 'Response body should not be empty');

        // Decode the response body and verify upstream content was forwarded.
        $body = base64_decode($captured->responseFrame['body_b64']);
        $this->assertStringContainsString('<h1>Hello from upstream</h1>', $body, 'Response body should contain upstream HTML');

        // The upstream serves URLs with 127.0.0.1:PORT which should be rewritten
        // to the tunnel host.
        $this->assertStringContainsString(
            'https://'.self::TUNNEL_HOST.'/dashboard',
            $body,
            'Local upstream URLs should be rewritten to tunnel host'
        );
        $this->assertStringNotContainsString(
            "http://127.0.0.1:{$upstreamPort}/dashboard",
            $body,
            'Original local URLs should no longer appear'
        );

        // Verify response headers are present.
        $this->assertIsArray($captured->responseFrame['headers']);

        // Verify traffic deltas accumulated.
        $deltas = EdgeAgent::flushTrafficDeltas();
        $this->assertSame(1, $deltas['requests'], 'Should have counted 1 request');
        $this->assertGreaterThan(0, $deltas['bytes_out'], 'bytes_out should be > 0');

        // Verify stderr had the "Edge agent connected" message.
        $allStderr = implode("\n", $stderrLines);
        $this->assertStringContainsString('Edge agent connected', $allStderr, 'Should log connection message');
    }

    // ------------------------------------------------------------------
    // Helpers: Fake HTTP upstream
    // ------------------------------------------------------------------

    /**
     * Start a PHP built-in server serving HTML with local URLs that need rewriting.
     */
    private function startFakeUpstream(): int
    {
        $this->upstreamDocRoot = sys_get_temp_dir().'/jetty_test_upstream_'.getmypid().'_'.mt_rand();
        @mkdir($this->upstreamDocRoot, 0755, true);

        // Find a free port.
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);
        $this->upstreamPort = $port;

        // Write index.php that serves HTML containing local URLs.
        $indexContent = <<<'PHP'
<?php
$port = $_SERVER['SERVER_PORT'];
$host = '127.0.0.1';
header('Content-Type: text/html; charset=utf-8');
echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <link href="http://{$host}:{$port}/css/app.css" rel="stylesheet">
</head>
<body>
    <h1>Hello from upstream</h1>
    <a href="http://{$host}:{$port}/dashboard">Dashboard</a>
    <script src="http://{$host}:{$port}/js/app.js"></script>
</body>
</html>
HTML;
PHP;
        file_put_contents($this->upstreamDocRoot.'/index.php', $indexContent);

        // Start PHP built-in server.
        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s',
            $this->upstreamPort,
            escapeshellarg($this->upstreamDocRoot)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->upstreamProc = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($this->upstreamProc, 'Failed to start upstream PHP server');

        // Close stdin.
        fclose($pipes[0]);
        // Make stdout/stderr non-blocking so we don't hang.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Wait for the server to start by polling it.
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $check = @fsockopen('127.0.0.1', $this->upstreamPort, $errno, $errstr, 0.1);
            if ($check !== false) {
                fclose($check);

                return $this->upstreamPort;
            }
            usleep(50_000);
        }

        $this->fail("Upstream PHP server did not start on port {$this->upstreamPort} within 5 seconds");
    }

    // ------------------------------------------------------------------
    // Helpers: WebSocket frame reading/writing (RFC 6455 minimal)
    // ------------------------------------------------------------------

    private function extractHeader(string $request, string $name): ?string
    {
        foreach (explode("\r\n", $request) as $line) {
            if (stripos($line, $name.':') === 0) {
                return trim(substr($line, strlen($name) + 1));
            }
        }

        return null;
    }

    /**
     * Read exactly $len bytes from the socket, using a shared buffer object
     * so leftover bytes from previous reads are not lost.
     *
     * @param  object{buffer: string}  $readBuf  Shared buffer state
     */
    private function readExactBuffered(Socket $socket, int $len, object $readBuf): ?string
    {
        $deadline = microtime(true) + 10.0;
        while (strlen($readBuf->buffer) < $len) {
            if (microtime(true) > $deadline) {
                return null;
            }
            $chunk = $socket->read();
            if ($chunk === null) {
                return null;
            }
            $readBuf->buffer .= $chunk;
        }
        $result = substr($readBuf->buffer, 0, $len);
        $readBuf->buffer = substr($readBuf->buffer, $len);

        return $result;
    }

    /**
     * Read a single WebSocket text frame from a client connection.
     * Handles client-to-server masking as per RFC 6455.
     *
     * @param  object{buffer: string}  $readBuf  Shared buffer state
     */
    private function readWsFrame(Socket $socket, object $readBuf): ?string
    {
        $header = $this->readExactBuffered($socket, 2, $readBuf);
        if ($header === null || strlen($header) < 2) {
            return null;
        }

        $byte1 = ord($header[0]);
        $byte2 = ord($header[1]);

        $opcode = $byte1 & 0x0F;
        $masked = ($byte2 & 0x80) !== 0;
        $len = $byte2 & 0x7F;

        if ($len === 126) {
            $ext = $this->readExactBuffered($socket, 2, $readBuf);
            if ($ext === null) {
                return null;
            }
            $len = unpack('n', $ext)[1];
        } elseif ($len === 127) {
            $ext = $this->readExactBuffered($socket, 8, $readBuf);
            if ($ext === null) {
                return null;
            }
            $len = unpack('J', $ext)[1];
        }

        $maskKey = null;
        if ($masked) {
            $maskKey = $this->readExactBuffered($socket, 4, $readBuf);
            if ($maskKey === null) {
                return null;
            }
        }

        $payload = '';
        if ($len > 0) {
            $payload = $this->readExactBuffered($socket, $len, $readBuf);
            if ($payload === null) {
                return null;
            }
        }

        // Unmask if needed.
        if ($masked && $maskKey !== null) {
            for ($i = 0; $i < strlen($payload); $i++) {
                $payload[$i] = chr(ord($payload[$i]) ^ ord($maskKey[$i % 4]));
            }
        }

        // If opcode is ping (0x9), respond with pong and read next frame.
        if ($opcode === 0x9) {
            $this->writeWsPongFrame($socket, $payload);

            return $this->readWsFrame($socket, $readBuf);
        }

        // If opcode is close (0x8), return null.
        if ($opcode === 0x8) {
            return null;
        }

        return $payload;
    }

    /**
     * Write an unmasked WebSocket text frame (server-to-client frames are not masked).
     */
    private function writeWsTextFrame(Socket $socket, string $payload): void
    {
        $frame = $this->buildWsFrame(0x1, $payload);
        $socket->write($frame);
    }

    /**
     * Write a WebSocket close frame.
     */
    private function writeWsCloseFrame(Socket $socket): void
    {
        // Close frame with status 1000 (normal closure).
        $statusPayload = pack('n', 1000);
        $frame = $this->buildWsFrame(0x8, $statusPayload);
        $socket->write($frame);
    }

    /**
     * Write a WebSocket pong frame.
     */
    private function writeWsPongFrame(Socket $socket, string $payload): void
    {
        $frame = $this->buildWsFrame(0xA, $payload);
        $socket->write($frame);
    }

    /**
     * Build an unmasked WebSocket frame (for server-to-client).
     */
    private function buildWsFrame(int $opcode, string $payload): string
    {
        $len = strlen($payload);
        // FIN bit + opcode
        $frame = chr(0x80 | $opcode);

        if ($len <= 125) {
            $frame .= chr($len);
        } elseif ($len <= 65535) {
            $frame .= chr(126).pack('n', $len);
        } else {
            $frame .= chr(127).pack('J', $len);
        }

        $frame .= $payload;

        return $frame;
    }
}
