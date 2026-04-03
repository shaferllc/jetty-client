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

    private const MAX_AGENT_TOKEN_REFRESH_PER_RUN = 8;

    /**
     * Default seconds between WebSocket ping frames after the post-registration ping.
     * Aggressive proxies sometimes idle-close around 10–15s; 25s was too sparse for those paths.
     */
    private const DEFAULT_WS_PING_INTERVAL_SECONDS = 8.0;

    private const MIN_WS_PING_INTERVAL_SECONDS = 2.0;

    private const MAX_WS_PING_INTERVAL_SECONDS = 120.0;

    /** Unix time of last forwarded HTTP request (for idle auto-close in {@see Application::runShareHeartbeatLoop}). */
    private static ?int $httpActivityUnix = null;

    /** Traffic counters — accumulated since last heartbeat flush. */
    private static int $deltaRequests = 0;

    private static int $deltaBytesIn = 0;

    private static int $deltaBytesOut = 0;

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

    public static function recordTraffic(int $bytesIn, int $bytesOut): void
    {
        self::$deltaRequests++;
        self::$deltaBytesIn += $bytesIn;
        self::$deltaBytesOut += $bytesOut;
    }

    /**
     * Return and reset accumulated traffic deltas for the next heartbeat.
     *
     * @return array{requests: int, bytes_in: int, bytes_out: int}
     */
    public static function flushTrafficDeltas(): array
    {
        $d = [
            'requests' => self::$deltaRequests,
            'bytes_in' => self::$deltaBytesIn,
            'bytes_out' => self::$deltaBytesOut,
        ];
        self::$deltaRequests = 0;
        self::$deltaBytesIn = 0;
        self::$deltaBytesOut = 0;

        return $d;
    }

    /**
     * Seconds to wait after each successful WebSocket ping before the next ping.
     * Override with {@code JETTY_SHARE_WS_PING_INTERVAL} (2–120). Ignored when {@code JETTY_SHARE_NO_WS_PING=1}.
     */
    /**
     * Seconds for {@see CURLOPT_CONNECTTIMEOUT} to the local upstream (not total transfer time).
     * Env: {@code JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT} (1–120), default {@code 10}.
     */
    public static function upstreamConnectTimeoutSeconds(): int
    {
        $raw = getenv('JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT');
        if (! is_string($raw) || trim($raw) === '' || ! is_numeric(trim($raw))) {
            return 10;
        }
        $v = (int) trim($raw);

        return max(1, min(120, $v));
    }

    public static function websocketPingIntervalSeconds(): float
    {
        if (getenv('JETTY_SHARE_NO_WS_PING') === '1') {
            return self::DEFAULT_WS_PING_INTERVAL_SECONDS;
        }
        $raw = getenv('JETTY_SHARE_WS_PING_INTERVAL');
        if (! is_string($raw)) {
            return self::DEFAULT_WS_PING_INTERVAL_SECONDS;
        }
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return self::DEFAULT_WS_PING_INTERVAL_SECONDS;
        }
        $v = (float) $raw;
        if ($v < self::MIN_WS_PING_INTERVAL_SECONDS || $v > self::MAX_WS_PING_INTERVAL_SECONDS) {
            return self::DEFAULT_WS_PING_INTERVAL_SECONDS;
        }

        return $v;
    }

    /**
     * Blocks until the edge connection closes or the user stops (signal / cancellation).
     * Runs heartbeats concurrently (same interval as {@see Application} share loop).
     *
     * @param  callable(string): void  $stderr  Always receives errors and important status lines
     * @param  string|null  $publicTunnelHostForRewrite  Host from Bridge public_url; used when edge frames omit Host so redirects/HTML can rewrite to the tunnel
     * @param  (callable(string, array<string, mixed>): void)|null  $agentDebug  Structured events (stderr JSON lines); enable with JETTY_SHARE_DEBUG_AGENT=1 or jetty share --debug-agent
     * @param  (callable(): string)|null  $refreshAgentToken  When edge rejects registration (stale token), call Bridge attach and return new plaintext agent_token
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
        ?callable $agentDebug = null,
        ?callable $refreshAgentToken = null,
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
        $heartbeatAgentDebug = $agentDebug !== null && EdgeAgentDebug::heartbeatEventsFromEnvironment();

        async(function () use ($wsUrl, $tunnelId, &$agentToken, $localHost, $localPort, $apiClient, $heartbeatTunnelId, $stderr, $v, $verbose, $box, $rewriteOpts, $publicTunnelHostForRewrite, $agentDebug, $heartbeatAgentDebug, $refreshAgentToken): void {
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
            $agentTokenRefreshCount = 0;

            async(function () use ($state, $apiClient, $heartbeatTunnelId, $stderr, $v, $verbose, $agentDebug, $heartbeatAgentDebug): void {
                $n = 0;
                while ($state->running) {
                    delay(25.0);
                    if (! $state->running) {
                        break;
                    }
                    try {
                        $deltas = self::flushTrafficDeltas();
                        $apiClient->heartbeat($heartbeatTunnelId, $deltas);
                        $n++;
                        if ($verbose) {
                            $v('heartbeat #'.$n.' OK (tunnel id '.$heartbeatTunnelId.') deltas: '.json_encode($deltas));
                        }
                        if ($heartbeatAgentDebug) {
                            self::agentEmit($agentDebug, 'heartbeat_ok', ['n' => $n, 'tunnel_id' => $heartbeatTunnelId]);
                        }
                    } catch (\Throwable $e) {
                        $stderr('heartbeat failed: '.$e->getMessage());
                        $v('heartbeat exception: '.$e::class.' '.$e->getMessage());
                        if ($heartbeatAgentDebug) {
                            self::agentEmit($agentDebug, 'heartbeat_error', [
                                'n' => $n,
                                'tunnel_id' => $heartbeatTunnelId,
                                'error_class' => $e::class,
                                'error_message' => $e->getMessage(),
                            ]);
                        }
                    }
                }
                $v('heartbeat task exiting');
            })->ignore();

            try {
                $session = 0;
                while (! $state->userRequestedStop) {
                    $session++;
                    self::agentEmit($agentDebug, 'session_tick', [
                        'session' => $session,
                        'edge_reconnect' => $edgeReconnect,
                        'user_stop' => $state->userRequestedStop,
                    ]);
                    if ($session > 1 && $edgeReconnect) {
                        $base = min(60, 2 ** min($session - 2, 6));
                        $jitter = random_int(0, 750) / 1000.0;
                        $wait = $base + $jitter;
                        self::agentEmit($agentDebug, 'ws_reconnect_backoff', [
                            'session' => $session,
                            'wait_seconds' => round($wait, 3),
                            'backoff_base' => $base,
                        ]);
                        $stderr(sprintf('edge: reconnecting in %.1fs (attempt %d)…', $wait, $session));
                        delay($wait);
                    } elseif ($session > 1 && ! $edgeReconnect) {
                        $box->result = EdgeAgentResult::Disconnected;
                        $stderr('edge: WebSocket closed; HTTP forwarding paused (JETTY_SHARE_NO_EDGE_RECONNECT=1). Heartbeats continue until you exit.');

                        break;
                    }

                    $run->registeredOk = false;
                    $cancelHolder->read = new DeferredCancellation;
                    $cancelRead = $cancelHolder->read;

                    $v('connecting WebSocket: '.self::redactWsUrlForLog($wsUrl));
                    self::agentEmit($agentDebug, 'ws_connect_attempt', [
                        'session' => $session,
                        'ws_url' => self::redactWsUrlForLog($wsUrl),
                    ]);
                    try {
                        $conn = connect($wsUrl);
                    } catch (\Throwable $e) {
                        self::agentEmit($agentDebug, 'ws_connect_failed', [
                            'session' => $session,
                            'error_class' => $e::class,
                            'error_detail' => self::formatEdgeWsConnectErrorDetail($e),
                        ]);
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
                        self::agentEmit($agentDebug, 'ws_connected_bad_type', [
                            'session' => $session,
                            'type' => is_object($conn) ? $conn::class : gettype($conn),
                        ]);
                        $stderr('edge: unexpected connection type');
                        $box->result = EdgeAgentResult::FailedEarly;

                        break;
                    }

                    self::agentEmit($agentDebug, 'ws_connected', [
                        'session' => $session,
                        'tunnel_id' => $tunnelId,
                    ]);
                    $v('WebSocket connected; sending register (tunnel_id='.$tunnelId.', agent_token len='.strlen($agentToken).')');
                    $reg = json_encode([
                        'type' => self::TYPE_REGISTER,
                        'tunnel_id' => $tunnelId,
                        'agent_token' => $agentToken,
                    ], JSON_THROW_ON_ERROR);
                    $conn->sendText($reg);
                    self::agentEmit($agentDebug, 'register_sent', [
                        'session' => $session,
                        'tunnel_id' => $tunnelId,
                        'agent_token_len' => strlen($agentToken),
                    ]);
                    $v('register frame sent');

                    $first = $conn->receive();
                    if ($first === null) {
                        self::agentEmit($agentDebug, 'register_ack_missing', [
                            'session' => $session,
                            'reason' => 'receive_null_before_ack',
                        ]);
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
                    self::agentEmit($agentDebug, 'ws_first_frame', [
                        'session' => $session,
                        'bytes' => strlen($rawFirst),
                        'frame_type' => $type !== '' ? $type : '(unparsed)',
                    ]);
                    $v('first frame type='.$type);
                    if ($type === self::TYPE_ERROR) {
                        /** @var array<string, mixed> $err */
                        $err = json_decode($rawFirst, true, 512, JSON_THROW_ON_ERROR);
                        $errMsg = (string) ($err['message'] ?? $rawFirst);
                        self::agentEmit($agentDebug, 'edge_registration_error', [
                            'session' => $session,
                            'message' => $errMsg,
                            'code' => $err['code'] ?? null,
                        ]);
                        $stderr('edge error: '.$errMsg);
                        if ($verbose) {
                            $stderr('[jetty:edge] error payload: '.$rawFirst);
                        }
                        $conn->close();
                        $stale = self::registrationErrorLooksLikeStaleCredentials($errMsg);
                        if ($stale && $refreshAgentToken !== null && $agentTokenRefreshCount < self::MAX_AGENT_TOKEN_REFRESH_PER_RUN) {
                            try {
                                $stderr('edge: refreshing agent_token via Bridge attach…');
                                $agentToken = ($refreshAgentToken)();
                                $agentTokenRefreshCount++;
                                self::agentEmit($agentDebug, 'agent_token_refreshed', [
                                    'session' => $session,
                                    'refresh_count' => $agentTokenRefreshCount,
                                    'new_token_len' => strlen($agentToken),
                                ]);
                            } catch (\Throwable $e) {
                                $stderr('edge: could not refresh agent_token: '.$e->getMessage());
                            }
                        }
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
                    if ($type !== self::TYPE_REGISTERED) {
                        self::agentEmit($agentDebug, 'edge_unexpected_first_frame', [
                            'session' => $session,
                            'frame_type' => $type,
                            'preview' => self::debugStringPreview($rawFirst, 400),
                        ]);
                        $stderr('edge: unexpected first message: '.$rawFirst);
                        if ($verbose) {
                            $stderr('[jetty:edge] full first message: '.$rawFirst);
                        }
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

                    $run->registeredOk = true;
                    self::agentEmit($agentDebug, 'registered', [
                        'session' => $session,
                        'tunnel_id' => $tunnelId,
                        'local_upstream' => $localHost.':'.$localPort,
                        'public_tunnel_host_fallback' => $publicTunnelHostForRewrite,
                    ]);
                    if ($session === 1) {
                        $stderr('Edge agent connected; forwarding HTTP to local upstream.');
                    } else {
                        $stderr('Edge agent reconnected; forwarding HTTP to local upstream.');
                    }
                    $v('registration acknowledged; starting ws ping + receive loop');

                    $pingEvery = self::websocketPingIntervalSeconds();
                    async(function () use ($conn, $state, $v, $verbose, $agentDebug, $pingEvery): void {
                        while ($state->running) {
                            if (! $state->running) {
                                break;
                            }
                            if (getenv('JETTY_SHARE_NO_WS_PING') === '1') {
                                delay(25.0);

                                continue;
                            }
                            try {
                                // Ping immediately after registration, then on a short interval so strict
                                // proxies (often ~10–60s idle) do not close /agent before the next frame.
                                $conn->ping();
                                if ($verbose) {
                                    $v('websocket ping sent');
                                }
                            } catch (\Throwable $e) {
                                $v('websocket ping failed: '.$e->getMessage());
                                self::agentEmit($agentDebug, 'ws_ping_failed', [
                                    'error_class' => $e::class,
                                    'error_message' => $e->getMessage(),
                                ]);

                                break;
                            }
                            delay($pingEvery);
                        }
                    })->ignore();

                    try {
                        $msgNum = 0;
                        self::agentEmit($agentDebug, 'ws_receive_loop_start', [
                            'session' => $session,
                        ]);
                        while ($state->running) {
                            try {
                                $msg = $conn->receive($cancelRead->getCancellation());
                            } catch (CancelledException $e) {
                                self::agentEmit($agentDebug, 'ws_receive_cancelled', [
                                    'message' => $e->getMessage(),
                                ]);
                                $v('receive cancelled: '.$e->getMessage());

                                break;
                            }
                            if ($msg === null) {
                                self::agentEmit($agentDebug, 'ws_receive_null', [
                                    'msg_num' => $msgNum,
                                ]);
                                $v('receive() returned null — connection closed');

                                break;
                            }
                            $raw = $msg->buffer();
                            $msgNum++;
                            $ft = self::parseType($raw);
                            if ($ft !== self::TYPE_HTTP_REQUEST) {
                                self::agentEmit($agentDebug, 'ws_frame_ignored', [
                                    'msg_num' => $msgNum,
                                    'frame_type' => $ft !== '' ? $ft : '(unparsed)',
                                    'raw_bytes' => strlen($raw),
                                    'preview' => self::debugStringPreview($raw, 240),
                                ]);
                                $v('frame #'.$msgNum.' type='.$ft.' (ignored, not http_request)');

                                continue;
                            }
                            TunnelResponseRewriter::emitDebugNdjson('edge.ws_http_request_frame', [
                                'msg_num' => $msgNum,
                                'raw_bytes' => strlen($raw),
                            ]);
                            try {
                                /** @var array<string, mixed> $req */
                                $req = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                            } catch (\JsonException $e) {
                                self::agentEmit($agentDebug, 'http_request_json_error', [
                                    'msg_num' => $msgNum,
                                    'error' => $e->getMessage(),
                                    'raw_bytes' => strlen($raw),
                                    'preview' => self::debugStringPreview($raw, 240),
                                ]);
                                TunnelResponseRewriter::emitDebugNdjson('edge.http_request_json_invalid', [
                                    'msg_num' => $msgNum,
                                    'error' => self::debugStringPreview($e->getMessage(), 300),
                                    'raw_bytes' => strlen($raw),
                                ]);

                                continue;
                            }
                            $method = strtoupper((string) ($req['method'] ?? '?'));
                            $path = (string) ($req['path'] ?? '/');
                            $requestStartMs = (int) round(microtime(true) * 1000);
                            $v('http_request #'.$msgNum.' '.$method.' '.$path.' → '.LocalUpstreamUrl::baseForCurl($localHost, $localPort).$path);
                            try {
                                $handled = self::handleHttpRequest($localHost, $localPort, $req, $rewriteOpts, $publicTunnelHostForRewrite, $agentDebug);
                            } catch (\Throwable $e) {
                                TunnelResponseRewriter::emitDebugNdjson('edge.http_request_handler_error', [
                                    'msg_num' => $msgNum,
                                    'request_id' => (string) ($req['request_id'] ?? ''),
                                    'error_class' => $e::class,
                                    'error_message' => self::debugStringPreview($e->getMessage(), 500),
                                ]);
                                throw $e;
                            }
                            $outJson = json_encode($handled['response'], JSON_THROW_ON_ERROR);
                            $conn->sendText($outJson);
                            $respStatus = (int) ($handled['response']['status'] ?? 0);
                            self::agentEmit($agentDebug, 'http_response_sent', [
                                'request_id' => (string) ($req['request_id'] ?? ''),
                                'msg_num' => $msgNum,
                                'status' => $respStatus,
                                'response_wire_bytes' => strlen($outJson),
                            ]);
                            $v('http_response sent for request_id='.(string) ($req['request_id'] ?? ''));
                            // Always-on traffic log line
                            $stderr(self::formatTrafficLine($method, $path, $respStatus, strlen($outJson), $requestStartMs ?? null));
                            // Accumulate traffic for the next heartbeat
                            $reqBodyLen = strlen((string) ($req['body_b64'] ?? ''));
                            self::recordTraffic($reqBodyLen, strlen($outJson));
                            if (($handled['sample'] ?? null) !== null && getenv('JETTY_SHARE_CAPTURE_SAMPLES') !== '0') {
                                $sample = $handled['sample'];
                                self::agentEmit($agentDebug, 'bridge_sample_queued', [
                                    'tunnel_id' => $heartbeatTunnelId,
                                    'sample_method' => $sample['method'] ?? null,
                                    'sample_path' => $sample['path'] ?? null,
                                    'sample_status' => $sample['status'] ?? null,
                                ]);
                                async(function () use ($apiClient, $heartbeatTunnelId, $sample): void {
                                    try {
                                        $apiClient->postRequestSample($heartbeatTunnelId, $sample);
                                    } catch (\Throwable) {
                                    }
                                })->ignore();
                            }
                        }
                    } catch (\Throwable $e) {
                        self::agentEmit($agentDebug, 'ws_receive_loop_error', [
                            'error_class' => $e::class,
                            'error_message' => $e->getMessage(),
                        ]);
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
                        self::agentEmit($agentDebug, 'session_user_stop', ['session' => $session]);
                        $box->result = EdgeAgentResult::Finished;

                        break;
                    }
                    if (! $edgeReconnect) {
                        self::agentEmit($agentDebug, 'session_disconnected_no_reconnect', ['session' => $session]);
                        $box->result = EdgeAgentResult::Disconnected;

                        break;
                    }
                    self::agentEmit($agentDebug, 'ws_session_closed_will_reconnect', [
                        'session' => $session,
                        'registered_ok' => $run->registeredOk,
                    ]);
                    $stderr('edge: WebSocket dropped; will retry (Ctrl+C to stop).');
                }

                if ($state->userRequestedStop) {
                    self::agentEmit($agentDebug, 'run_finished_user_stop', []);
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

    private static function registrationErrorLooksLikeStaleCredentials(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'verify rejected')
            || str_contains($m, 'invalid tunnel credentials')
            || str_contains($m, 'laravel verify');
    }

    /**
     * @param  array<string, mixed>  $req
     * @param  (callable(string, array<string, mixed>): void)|null  $agentDebug
     * @return array{response: array<string, mixed>, sample: array<string, mixed>|null}
     */
    private static function handleHttpRequest(
        string $localHost,
        int $localPort,
        array $req,
        TunnelRewriteOptions $rewriteOptions,
        ?string $publicTunnelHostForRewrite,
        ?callable $agentDebug,
    ): array {
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

        $edgeHost = self::headerValueCi($headers, 'Host');
        self::agentEmit($agentDebug, 'http_request_in', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'query_len' => strlen($query),
            'body_b64_len' => strlen($bodyB64),
            'edge_header_count' => count($headers),
            'edge_host' => $edgeHost ?? '',
            'edge_headers_redacted' => EdgeAgentDebug::redactSensitiveRequestHeaders($headers),
        ]);
        TunnelResponseRewriter::emitDebugNdjson('edge.http_request_from_edge', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'query_len' => strlen($query),
            'edge_host' => $edgeHost ?? '',
            'local_upstream' => $localHost.':'.$localPort,
        ]);

        $hostPolicy = ShareUpstreamHostPolicy::fromEnvironment();
        if (! $hostPolicy->allows($localHost)) {
            self::agentEmit($agentDebug, 'http_upstream_error', [
                'request_id' => $requestId,
                'stage' => 'upstream_host_policy',
                'message' => $hostPolicy->denyMessage($localHost),
            ]);
            TunnelResponseRewriter::emitDebugNdjson('edge.http_upstream_skipped', [
                'request_id' => $requestId,
                'stage' => 'upstream_host_policy',
                'local_upstream' => $localHost.':'.$localPort,
            ]);

            return [
                'response' => self::errorResponse($requestId, 502, 'upstream host not allowed by JETTY_SHARE_UPSTREAM_ALLOW_HOSTS'),
                'sample' => self::samplePayload($method, $path, $query, $headers, 502, strlen($bodyB64), 0),
            ];
        }

        $rawBody = base64_decode($bodyB64, true);
        if ($rawBody === false) {
            self::agentEmit($agentDebug, 'http_upstream_error', [
                'request_id' => $requestId,
                'stage' => 'body_b64_decode',
                'message' => 'invalid body_b64',
            ]);
            TunnelResponseRewriter::emitDebugNdjson('edge.http_upstream_skipped', [
                'request_id' => $requestId,
                'stage' => 'body_b64_decode',
            ]);

            return [
                'response' => self::errorResponse($requestId, 502, 'invalid body_b64'),
                'sample' => self::samplePayload($method, $path, $query, $headers, 502, strlen($bodyB64), 0),
            ];
        }

        $target = LocalUpstreamUrl::baseForCurl($localHost, $localPort).$path;
        if ($query !== '') {
            $target .= '?'.$query;
        }

        TunnelResponseRewriter::emitDebugNdjson('edge.http_upstream_attempt', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'target' => self::debugStringPreview($target, 768),
            'localHost' => $localHost,
            'localPort' => $localPort,
        ]);

        $ch = curl_init($target);
        if ($ch === false) {
            self::agentEmit($agentDebug, 'http_upstream_error', [
                'request_id' => $requestId,
                'stage' => 'curl_init',
            ]);
            TunnelResponseRewriter::emitDebugNdjson('edge.http_upstream_skipped', [
                'request_id' => $requestId,
                'stage' => 'curl_init',
                'target' => self::debugStringPreview($target, 768),
            ]);

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

        self::agentEmit($agentDebug, 'http_upstream_begin', [
            'request_id' => $requestId,
            'target' => $target,
            'upstream_host_header' => TunnelUpstreamRequestHeaders::upstreamHostHeaderValue($localHost, $localPort),
            'curl_header_lines' => count($curlHeaders),
            'request_body_bytes' => strlen($rawBody),
        ]);

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => self::upstreamConnectTimeoutSeconds(),
            CURLOPT_TIMEOUT => 60,
        ];

        // For local HTTPS (port 443), skip SSL verification — Valet/Herd/local dev certs are self-signed.
        if ($localPort === 443) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        if ($method === 'GET' || $method === 'HEAD') {
            $opts[CURLOPT_HTTPGET] = true;
        } else {
            $opts[CURLOPT_POSTFIELDS] = $rawBody;
        }

        curl_setopt_array($ch, $opts);

        $t0 = microtime(true);
        $response = curl_exec($ch);
        $upstreamMs = (int) round((microtime(true) - $t0) * 1000);
        if ($response === false) {
            $err = curl_error($ch);
            $errno = curl_errno($ch);
            self::agentEmit($agentDebug, 'http_upstream_error', [
                'request_id' => $requestId,
                'stage' => 'curl_exec',
                'curl_errno' => $errno,
                'curl_error' => $err,
                'upstream_ms' => $upstreamMs,
            ]);
            $lookupFail = TunnelResponseRewriter::tunnelRewriteHostLookup($localHost);
            $invCwdLog = getenv('JETTY_SHARE_INVOCATION_CWD');
            $cliLog = getenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
            TunnelResponseRewriter::emitDebugNdjson('edge.http_upstream_curl_failed', [
                'request_id' => $requestId,
                'method' => $method,
                'path' => $path,
                'target' => self::debugStringPreview($target, 768),
                'curl_errno' => $errno,
                'curl_error' => self::debugStringPreview($err, 400),
                'upstream_ms' => $upstreamMs,
                'lookup_size' => count($lookupFail),
                'invocation_cwd' => is_string($invCwdLog) && $invCwdLog !== '' ? $invCwdLog : null,
                'cli_upstream_hostname' => is_string($cliLog) && trim($cliLog) !== '' ? trim($cliLog) : null,
            ]);

            return [
                'response' => self::errorResponse($requestId, 502, $err),
                'sample' => self::samplePayload($method, $path, $query, $headers, 502, strlen($rawBody), 0),
            ];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $headerBlock = substr($response, 0, $headerSize);
        $respBody = substr($response, $headerSize);

        $parsedHeaders = self::parseResponseHeaders($headerBlock);
        // Strip Content-Encoding — we asked the upstream for uncompressed (no Accept-Encoding)
        // but some servers still send it. The edge can re-compress on the way out.
        $parsedHeaders = self::removeHeaderCi($parsedHeaders, 'Content-Encoding');
        // Strip Content-Length — body rewriting may change the size; the edge will set it
        // from the actual response body. A stale Content-Length causes HTTP/2 stream errors.
        $parsedHeaders = self::removeHeaderCi($parsedHeaders, 'Content-Length');
        $beforeRedirectRewrite = $parsedHeaders;
        $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup($localHost);
        $rewriteRequestHeaders = TunnelResponseRewriter::requestHeadersWithRewriteTunnelHostFallback($headers, $publicTunnelHostForRewrite);
        TunnelResponseRewriter::debugRewriteRequestContext($requestId, $method, $path, $localHost, $localPort, $rewriteRequestHeaders);
        $outHeaders = TunnelResponseRewriter::rewriteRedirectHeaders($parsedHeaders, $rewriteRequestHeaders, $lookup, $localHost);
        $invCwdLog = getenv('JETTY_SHARE_INVOCATION_CWD');
        $cliLog = getenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
        $locBefore = self::headerValueCi($parsedHeaders, 'Location')
            ?? self::headerValueCi($parsedHeaders, 'X-Inertia-Location');
        $locAfter = self::headerValueCi($outHeaders, 'Location')
            ?? self::headerValueCi($outHeaders, 'X-Inertia-Location');
        TunnelResponseRewriter::emitDebugNdjson('edge.http_upstream_lookup', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'http_status' => $status,
            'localHost' => $localHost,
            'localPort' => $localPort,
            'lookup_size' => count($lookup),
            'invocation_cwd' => is_string($invCwdLog) && $invCwdLog !== '' ? $invCwdLog : null,
            'cli_upstream_hostname' => is_string($cliLog) && trim($cliLog) !== '' ? trim($cliLog) : null,
            'location_before' => $locBefore !== null ? self::debugStringPreview($locBefore, 512) : null,
            'location_after' => $locAfter !== null ? self::debugStringPreview($locAfter, 512) : null,
        ]);
        $bodyBeforeTunnelRewrite = $respBody;
        $respBody = TunnelResponseRewriter::maybeRewriteBody($respBody, $outHeaders, $rewriteRequestHeaders, $localHost, $rewriteOptions);

        // Detect Vite / dev-server URLs that won't load through the tunnel.
        $ct = self::headerValueCi($outHeaders, 'Content-Type') ?? '';
        if (str_contains($ct, 'text/html')) {
            $viteHits = TunnelResponseRewriter::detectViteDevServerUrls($bodyBeforeTunnelRewrite, $lookup, $localPort);
            $effectiveTunnelHost = self::headerValueCi($rewriteRequestHeaders, 'Host') ?? $publicTunnelHostForRewrite;
            TunnelResponseRewriter::emitViteDevServerWarning($viteHits, $requestId, $localHost, $localPort, $effectiveTunnelHost);
            if ($viteHits !== []) {
                $respBody = TunnelResponseRewriter::injectViteDevServerBanner($respBody, $viteHits, $localHost, $localPort);
            }
        }

        $contentType = self::headerValueCi($outHeaders, 'Content-Type') ?? '';
        self::agentEmit($agentDebug, 'http_upstream_response', [
            'request_id' => $requestId,
            'status' => $status,
            'upstream_ms' => $upstreamMs,
            'resp_header_block_bytes' => $headerSize,
            'resp_body_bytes' => strlen($bodyBeforeTunnelRewrite),
            'content_type' => $contentType,
            'response_headers_redacted' => EdgeAgentDebug::redactSensitiveResponseHeaders($outHeaders),
        ]);
        self::agentEmit($agentDebug, 'http_tunnel_rewrite', [
            'request_id' => $requestId,
            'tunnel_host_effective' => self::headerValueCi($rewriteRequestHeaders, 'Host') ?? '',
            'public_tunnel_host_fallback' => $publicTunnelHostForRewrite,
            'rewrite_host_lookup_size' => count($lookup),
            'redirect_header_changes' => self::redirectHeaderChanges($beforeRedirectRewrite, $outHeaders),
            'body_bytes_before' => strlen($bodyBeforeTunnelRewrite),
            'body_bytes_after' => strlen($respBody),
            'rewrite_options' => [
                'body' => $rewriteOptions->bodyRewrite,
                'js' => $rewriteOptions->jsRewrite,
                'css' => $rewriteOptions->cssRewrite,
                'max_body_bytes' => $rewriteOptions->maxBodyBytes,
            ],
        ]);

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

    /**
     * @param  (callable(string, array<string, mixed>): void)|null  $cb
     * @param  array<string, mixed>  $data
     */
    private static function agentEmit(?callable $cb, string $event, array $data = []): void
    {
        if ($cb === null) {
            return;
        }
        try {
            $cb($event, $data);
        } catch (\Throwable) {
            // Never break tunnel forwarding on debug sink failures.
        }
    }

    private static function debugStringPreview(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max).'…';
    }

    /**
     * Format a traffic log line with an embedded category tag for filtering.
     * Format: "[category]  METHOD /path STATUS SIZE ELAPSED"
     */
    private static function formatTrafficLine(string $method, string $path, int $status, int $bytes, ?int $startMs): string
    {
        $view = new ShareTrafficView;
        $category = $view->categorize($path, $status);
        $method = str_pad($method, 4);
        $pathDisplay = strlen($path) > 60 ? substr($path, 0, 57).'...' : $path;
        $size = self::humanBytes($bytes);
        $elapsed = '';
        if ($startMs !== null) {
            $ms = (int) round(microtime(true) * 1000) - $startMs;
            $elapsed = $ms >= 1000 ? round($ms / 1000, 1).'s' : $ms.'ms';
        }

        // Embed category as a parseable prefix: "[page]  GET /path ..."
        return '['.$category.']  '.$method.' '.$pathDisplay.' '.$status.' '.$size.($elapsed !== '' ? ' '.$elapsed : '');
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.'B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1).'kB';
        }

        return round($bytes / 1048576, 1).'MB';
    }

    private static function headerValueCi(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return (string) $v;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function removeHeaderCi(array $headers, string $name): array
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                unset($headers[$k]);
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     * @return list<array{name: string, before: ?string, after: ?string}>
     */
    private static function redirectHeaderChanges(array $before, array $after): array
    {
        $names = ['Location', 'X-Inertia-Location', 'Refresh'];
        $out = [];
        foreach ($names as $n) {
            $b = self::headerValueCi($before, $n);
            $a = self::headerValueCi($after, $n);
            if ($b !== $a) {
                $out[] = ['name' => $n, 'before' => $b, 'after' => $a];
            }
        }

        return $out;
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
