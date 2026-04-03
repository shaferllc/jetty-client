<?php

declare(strict_types=1);

namespace JettyCli;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;

use function Amp\async;
use function Amp\delay;
use function Amp\Websocket\Client\connect;

// Note: Revolt's EventLoop::run() accepts no callback — do not wrap the agent in EventLoop::run(fn () => …).
// Use Amp\async(…)->await() so the WebSocket work actually runs on the event loop.

/**
 * WebSocket tunnel agent: registers with jetty-edge and forwards http_request frames to local HTTP (same protocol as Go agent).
 */
final class EdgeAgent
{
    private const TYPE_REGISTER = 'register';

    private const TYPE_REGISTERED = 'registered';

    private const TYPE_ERROR = 'error';

    private const TYPE_HTTP_REQUEST = 'http_request';

    private const TYPE_HTTP_RESPONSE = 'http_response';

    /** Unix time of last forwarded HTTP request (for idle auto-close in {@see Application::runShareHeartbeatLoop}). */
    private static ?int $httpActivityUnix = null;

    public static function initHttpActivity(int $unixTime): void
    {
        self::$httpActivityUnix = $unixTime;
    }

    public static function markHttpActivity(): void
    {
        self::$httpActivityUnix = time();
    }

    public static function lastHttpActivityUnix(): ?int
    {
        return self::$httpActivityUnix;
    }

    /**
     * Blocks until the edge connection closes or the user stops (signal / cancellation).
     * Runs heartbeats concurrently (same interval as {@see Application} share loop).
     *
     * @param  callable(string): void  $stderr  Always receives errors and important status lines
     * @param  string|null  $publicTunnelHostForRewrite  Host from Bridge public_url; used when edge frames omit Host so redirects/HTML can rewrite to the tunnel
     */
    public static function run(
        string $wsUrl,
        int $tunnelId,
        string $agentToken,
        string $localHost,
        int $localPort,
        ApiClient $apiClient,
        int $heartbeatTunnelId,
        callable $stderr,
        bool $verbose = false,
        ?TunnelRewriteOptions $rewriteOptions = null,
        ?string $publicTunnelHostForRewrite = null,
    ): EdgeAgentResult {
        $v = function (string $m) use ($verbose, $stderr): void {
            if ($verbose) {
                $stderr('[jetty:edge] '.$m);
            }
        };

        $box = new class
        {
            public EdgeAgentResult $result = EdgeAgentResult::FailedEarly;
        };

        $rewriteOpts = $rewriteOptions ?? TunnelRewriteOptions::fromEnvironment();

        async(function () use ($wsUrl, $tunnelId, $agentToken, $localHost, $localPort, $apiClient, $heartbeatTunnelId, $stderr, $v, $verbose, $box, $rewriteOpts, $publicTunnelHostForRewrite): void {
            $state = new class
            {
                public bool $running = true;

                /** True after SIGINT/SIGTERM (user wants to end the session). */
                public bool $userRequestedStop = false;
            };

            $run = new class
            {
                public bool $registeredOk = false;
            };

            $cancelHolder = new class
            {
                public ?DeferredCancellation $read = null;
            };
            $onStop = function () use ($state, $cancelHolder, $v, $verbose): void {
                if ($verbose) {
                    $v('stop: signal or cancellation');
                }
                $state->userRequestedStop = true;
                $state->running = false;
                $cancelHolder->read?->cancel();
            };

            $sigIds = [];
            try {
                $sigIds[] = EventLoop::onSignal(\SIGINT, $onStop);
                $sigIds[] = EventLoop::onSignal(\SIGTERM, $onStop);
                $v('registered SIGINT/SIGTERM handlers');
            } catch (UnsupportedFeatureException $e) {
                $stderr('edge: signal handlers unavailable: '.$e->getMessage());
            }

            $edgeReconnect = getenv('JETTY_SHARE_NO_EDGE_RECONNECT') !== '1';

            async(function () use ($state, $apiClient, $heartbeatTunnelId, $stderr, $v, $verbose): void {
                $n = 0;
                while ($state->running) {
                    delay(25.0);
                    if (! $state->running) {
                        break;
                    }
                    try {
                        $apiClient->heartbeat($heartbeatTunnelId);
                        $n++;
                        if ($verbose) {
                            $v('heartbeat #'.$n.' OK (tunnel id '.$heartbeatTunnelId.')');
                        }
                    } catch (\Throwable $e) {
                        $stderr('heartbeat failed: '.$e->getMessage());
                        $v('heartbeat exception: '.$e::class.' '.$e->getMessage());
                    }
                }
                $v('heartbeat task exiting');
            })->ignore();

            try {
                $session = 0;
                while (! $state->userRequestedStop) {
                    $session++;
                    if ($session > 1 && $edgeReconnect) {
                        $base = min(60, 2 ** min($session - 2, 6));
                        $jitter = random_int(0, 750) / 1000.0;
                        $stderr(sprintf('edge: reconnecting in %.1fs (attempt %d)…', $base + $jitter, $session));
                        delay($base + $jitter);
                    } elseif ($session > 1 && ! $edgeReconnect) {
                        $box->result = EdgeAgentResult::Disconnected;
                        $stderr('edge: WebSocket closed; HTTP forwarding paused (JETTY_SHARE_NO_EDGE_RECONNECT=1). Heartbeats continue until you exit.');

                        break;
                    }

                    $run->registeredOk = false;
                    $cancelHolder->read = new DeferredCancellation;
                    $cancelRead = $cancelHolder->read;

                    $v('connecting WebSocket: '.self::redactWsUrlForLog($wsUrl));
                    try {
                        $conn = connect($wsUrl);
                    } catch (\Throwable $e) {
                        $stderr('edge WebSocket connect failed: '.self::formatEdgeWsConnectErrorDetail($e));
                        self::maybeReportWsFailureTelemetry($apiClient, $e);
                        $v('connect exception: '.$e::class.' '.$e->getMessage());
                        if ($state->userRequestedStop) {
                            $box->result = EdgeAgentResult::Finished;

                            break;
                        }
                        if (! $edgeReconnect) {
                            $box->result = EdgeAgentResult::FailedEarly;

                            break;
                        }

                        continue;
                    }

                    if (! $conn instanceof WebsocketConnection) {
                        $stderr('edge: unexpected connection type');
                        $box->result = EdgeAgentResult::FailedEarly;

                        break;
                    }

                    $v('WebSocket connected; sending register (tunnel_id='.$tunnelId.', agent_token len='.strlen($agentToken).')');
                    $reg = json_encode([
                        'type' => self::TYPE_REGISTER,
                        'tunnel_id' => $tunnelId,
                        'agent_token' => $agentToken,
                    ], JSON_THROW_ON_ERROR);
                    $conn->sendText($reg);
                    $v('register frame sent');

                    $first = $conn->receive();
                    if ($first === null) {
                        $stderr('edge: closed before registration ack');
                        $v('first receive() returned null');
                        $conn->close();
                        if ($state->userRequestedStop) {
                            $box->result = EdgeAgentResult::Finished;

                            break;
                        }
                        if (! $edgeReconnect) {
                            $box->result = EdgeAgentResult::FailedEarly;

                            break;
                        }

                        continue;
                    }

                    $rawFirst = $first->buffer();
                    $v('first frame length='.strlen($rawFirst).' bytes');
                    $type = self::parseType($rawFirst);
                    $v('first frame type='.$type);
                    if ($type === self::TYPE_ERROR) {
                        /** @var array<string, mixed> $err */
                        $err = json_decode($rawFirst, true, 512, JSON_THROW_ON_ERROR);
                        $stderr('edge error: '.(string) ($err['message'] ?? $rawFirst));
                        if ($verbose) {
                            $stderr('[jetty:edge] error payload: '.$rawFirst);
                        }
                        $conn->close();
                        $box->result = EdgeAgentResult::FailedEarly;

                        break;
                    }
                    if ($type !== self::TYPE_REGISTERED) {
                        $stderr('edge: unexpected first message: '.$rawFirst);
                        if ($verbose) {
                            $stderr('[jetty:edge] full first message: '.$rawFirst);
                        }
                        $conn->close();
                        $box->result = EdgeAgentResult::FailedEarly;

                        break;
                    }

                    $run->registeredOk = true;
                    if ($session === 1) {
                        $stderr('Edge agent connected; forwarding HTTP to local upstream.');
                    } else {
                        $stderr('Edge agent reconnected; forwarding HTTP to local upstream.');
                    }
                    $v('registration acknowledged; starting ws ping + receive loop');

                    async(function () use ($conn, $state, $v, $verbose): void {
                        while ($state->running) {
                            delay(25.0);
                            if (! $state->running) {
                                break;
                            }
                            if (getenv('JETTY_SHARE_NO_WS_PING') === '1') {
                                continue;
                            }
                            try {
                                $conn->ping();
                                if ($verbose) {
                                    $v('websocket ping sent');
                                }
                            } catch (\Throwable $e) {
                                $v('websocket ping failed: '.$e->getMessage());

                                break;
                            }
                        }
                    })->ignore();

                    try {
                        $msgNum = 0;
                        while ($state->running) {
                            try {
                                $msg = $conn->receive($cancelRead->getCancellation());
                            } catch (CancelledException $e) {
                                $v('receive cancelled: '.$e->getMessage());

                                break;
                            }
                            if ($msg === null) {
                                $v('receive() returned null — connection closed');

                                break;
                            }
                            $raw = $msg->buffer();
                            $msgNum++;
                            $ft = self::parseType($raw);
                            if ($ft !== self::TYPE_HTTP_REQUEST) {
                                $v('frame #'.$msgNum.' type='.$ft.' (ignored, not http_request)');

                                continue;
                            }
                            /** @var array<string, mixed> $req */
                            $req = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                            $method = strtoupper((string) ($req['method'] ?? '?'));
                            $path = (string) ($req['path'] ?? '/');
                            $v('http_request #'.$msgNum.' '.$method.' '.$path.' → http://'.$localHost.':'.$localPort.$path);
                            $handled = self::handleHttpRequest($localHost, $localPort, $req, $rewriteOpts, $publicTunnelHostForRewrite);
                            $conn->sendText(json_encode($handled['response'], JSON_THROW_ON_ERROR));
                            $v('http_response sent for request_id='.(string) ($req['request_id'] ?? ''));
                            if (($handled['sample'] ?? null) !== null && getenv('JETTY_SHARE_CAPTURE_SAMPLES') !== '0') {
                                $sample = $handled['sample'];
                                async(function () use ($apiClient, $heartbeatTunnelId, $sample): void {
                                    try {
                                        $apiClient->postRequestSample($heartbeatTunnelId, $sample);
                                    } catch (\Throwable) {
                                    }
                                })->ignore();
                            }
                        }
                    } catch (\Throwable $e) {
                        $stderr('edge receive loop error: '.$e->getMessage());
                        $v('receive loop exception: '.$e::class.' '.$e->getMessage());
                    } finally {
                        try {
                            $conn->close();
                        } catch (\Throwable) {
                        }
                        $v('WebSocket connection closed');
                    }

                    if ($state->userRequestedStop) {
                        $box->result = EdgeAgentResult::Finished;

                        break;
                    }
                    if (! $edgeReconnect) {
                        $box->result = EdgeAgentResult::Disconnected;

                        break;
                    }
                    $stderr('edge: WebSocket dropped; will retry (Ctrl+C to stop).');
                }

                if ($state->userRequestedStop) {
                    $box->result = EdgeAgentResult::Finished;
                }
            } finally {
                foreach ($sigIds as $id) {
                    EventLoop::cancel($id);
                }
                $v('EventLoop closure finished');
            }
        })->await();

        return $box->result;
    }

    private static function redactWsUrlForLog(string $wsUrl): string
    {
        $parts = parse_url($wsUrl);
        if (! is_array($parts)) {
            return $wsUrl;
        }
        $scheme = (string) ($parts['scheme'] ?? 'ws');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = (string) ($parts['path'] ?? '/');
        $q = isset($parts['query']) ? '?…' : '';

        return $scheme.'://'.$host.$port.$path.$q;
    }

    private static function parseType(string $raw): string
    {
        $probe = json_decode($raw, true);
        if (! is_array($probe)) {
            return '';
        }

        return isset($probe['type']) ? (string) $probe['type'] : '';
    }

    /**
     * @param  array<string, mixed>  $req
     * @return array{response: array<string, mixed>, sample: array<string, mixed>|null}
     */
    private static function handleHttpRequest(string $localHost, int $localPort, array $req, TunnelRewriteOptions $rewriteOptions, ?string $publicTunnelHostForRewrite = null): array
    {
        self::markHttpActivity();

        $requestId = (string) ($req['request_id'] ?? '');
        $method = strtoupper((string) ($req['method'] ?? 'GET'));
        $path = (string) ($req['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = (string) ($req['query'] ?? '');
        /** @var array<string, string> $headers */
        $headers = is_array($req['headers'] ?? null) ? $req['headers'] : [];
        $bodyB64 = (string) ($req['body_b64'] ?? '');

        $rawBody = base64_decode($bodyB64, true);
        if ($rawBody === false) {
            return [
                'response' => self::errorResponse($requestId, 502, 'invalid body_b64'),
                'sample' => self::samplePayload($method, $path, $query, $headers, 502, strlen($bodyB64), 0),
            ];
        }

        $target = 'http://'.$localHost.':'.$localPort.$path;
        if ($query !== '') {
            $target .= '?'.$query;
        }

        $ch = curl_init($target);
        if ($ch === false) {
            return [
                'response' => self::errorResponse($requestId, 502, 'curl_init failed'),
                'sample' => self::samplePayload($method, $path, $query, $headers, 502, strlen($rawBody), 0),
            ];
        }

        $upstreamHeaders = TunnelUpstreamRequestHeaders::forLocalUpstream($headers, $localHost, $localPort);
        $curlHeaders = [];
        foreach ($upstreamHeaders as $k => $v) {
            $curlHeaders[] = $k.': '.$v;
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 60,
        ];

        if ($method === 'GET' || $method === 'HEAD') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_POSTFIELDS] = $rawBody;
        }

        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);

            return [
                'response' => self::errorResponse($requestId, 502, $err),
                'sample' => self::samplePayload($method, $path, $query, $headers, 502, strlen($rawBody), 0),
            ];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $headerBlock = substr($response, 0, $headerSize);
        $respBody = substr($response, $headerSize);

        $outHeaders = self::parseResponseHeaders($headerBlock);
        $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup($localHost);
        $rewriteRequestHeaders = TunnelResponseRewriter::requestHeadersWithRewriteTunnelHostFallback($headers, $publicTunnelHostForRewrite);
        TunnelResponseRewriter::debugRewriteRequestContext($requestId, $method, $path, $localHost, $localPort, $rewriteRequestHeaders);
        $outHeaders = TunnelResponseRewriter::rewriteRedirectHeaders($outHeaders, $rewriteRequestHeaders, $lookup);
        $respBody = TunnelResponseRewriter::maybeRewriteBody($respBody, $outHeaders, $rewriteRequestHeaders, $localHost, $rewriteOptions);

        return [
            'response' => [
                'type' => self::TYPE_HTTP_RESPONSE,
                'request_id' => $requestId,
                'status' => $status,
                'headers' => $outHeaders,
                'body_b64' => base64_encode($respBody),
            ],
            'sample' => self::samplePayload($method, $path, $query, $headers, $status, strlen($rawBody), strlen($respBody)),
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private static function samplePayload(string $method, string $path, string $query, array $headers, int $status, int $reqBodyLen, int $respBodyLen): array
    {
        $bytesIn = $reqBodyLen;
        foreach ($headers as $k => $v) {
            $bytesIn += strlen((string) $k) + strlen((string) $v) + 4;
        }

        return [
            'method' => $method,
            'path' => $path,
            'query' => $query !== '' ? $query : null,
            'status' => $status,
            'bytes_in' => $bytesIn,
            'bytes_out' => $respBodyLen,
            'headers' => $headers,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseResponseHeaders(string $headerBlock): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $headerBlock) ?: [];
        $out = [];
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($name === '' || self::isHopHeader($name)) {
                continue;
            }
            $out[$name] = $value;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorResponse(string $requestId, int $status, string $msg): array
    {
        return [
            'type' => self::TYPE_HTTP_RESPONSE,
            'request_id' => $requestId,
            'status' => $status,
            'headers' => ['Content-Type' => 'text/plain; charset=utf-8'],
            'body_b64' => base64_encode($msg),
        ];
    }

    private static function isHopHeader(string $k): bool
    {
        $k = strtolower($k);

        return in_array($k, [
            'connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer',
        ], true);
    }

    private static function maybeReportWsFailureTelemetry(ApiClient $apiClient, \Throwable $e): void
    {
        if (! $e instanceof WebsocketConnectException) {
            return;
        }
        try {
            $status = $e->getResponse()->getStatus();
            $apiClient->postEdgeWsFailureTelemetry($status, substr($e->getMessage(), 0, 200));
        } catch (\Throwable) {
            // never interrupt share / reconnect
        }
    }

    /**
     * Amphp's {@see WebsocketConnectException} carries the failed HTTP response. Surface status, reason phrase,
     * and proxy-like headers so operators can tell a 502 from nginx/LB from TLS/DNS errors without guessing.
     */
    private static function formatEdgeWsConnectErrorDetail(\Throwable $e): string
    {
        $msg = $e->getMessage();
        if (! $e instanceof WebsocketConnectException) {
            return $msg;
        }

        $r = $e->getResponse();
        $status = $r->getStatus();
        $reasonPhrase = $r->getReason();

        $headerParts = [];
        foreach (['Server', 'Via', 'CF-Ray', 'X-Cache', 'X-Served-By', 'Date'] as $name) {
            $v = $r->getHeader($name);
            if ($v !== null && $v !== '') {
                $headerParts[] = $name.': '.$v;
            }
        }

        $lines = [$msg, '  HTTP '.$status.' '.$reasonPhrase];
        if ($headerParts !== []) {
            $lines[] = '  Headers: '.implode('; ', $headerParts);
        }

        if (in_array($status, [502, 503, 504], true)) {
            $lines[] = '  Hint: '.$status.' on upgrade usually means the TLS proxy could not reach jetty-edge (check edge nginx/journalctl); Laravel verify runs only after 101 Switching Protocols.';
        }

        return implode("\n", $lines);
    }
}
