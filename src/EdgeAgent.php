<?php

declare(strict_types=1);

namespace JettyCli;

use Amp\CancelledException;
use Amp\DeferredCancellation;
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

        async(function () use ($wsUrl, $tunnelId, $agentToken, $localHost, $localPort, $apiClient, $heartbeatTunnelId, $stderr, $v, $verbose, $box, $rewriteOpts): void {
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

            $cancelRead = new DeferredCancellation;
            $onStop = function () use ($state, $cancelRead, $v, $verbose): void {
                if ($verbose) {
                    $v('stop: signal or cancellation');
                }
                $state->userRequestedStop = true;
                $state->running = false;
                $cancelRead->cancel();
            };

            $sigIds = [];
            try {
                $sigIds[] = EventLoop::onSignal(\SIGINT, $onStop);
                $sigIds[] = EventLoop::onSignal(\SIGTERM, $onStop);
                $v('registered SIGINT/SIGTERM handlers');
            } catch (UnsupportedFeatureException $e) {
                $stderr('edge: signal handlers unavailable: '.$e->getMessage());
            }

            try {
                $v('connecting WebSocket: '.self::redactWsUrlForLog($wsUrl));
                try {
                    $conn = connect($wsUrl);
                } catch (\Throwable $e) {
                    $stderr('edge WebSocket connect failed: '.$e->getMessage());
                    $v('connect exception: '.$e::class.' '.$e->getMessage());

                    return;
                }

                if (! $conn instanceof WebsocketConnection) {
                    $stderr('edge: unexpected connection type');

                    return;
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

                    return;
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

                    return;
                }
                if ($type !== self::TYPE_REGISTERED) {
                    $stderr('edge: unexpected first message: '.$rawFirst);
                    if ($verbose) {
                        $stderr('[jetty:edge] full first message: '.$rawFirst);
                    }

                    return;
                }

                $run->registeredOk = true;
                $stderr('Edge agent connected; forwarding HTTP to local upstream.');
                $v('registration acknowledged; starting heartbeat task + receive loop');

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
                        $res = self::handleHttpRequest($localHost, $localPort, $req, $rewriteOpts);
                        $conn->sendText(json_encode($res, JSON_THROW_ON_ERROR));
                        $v('http_response sent for request_id='.(string) ($req['request_id'] ?? ''));
                    }
                } catch (\Throwable $e) {
                    $stderr('edge receive loop error: '.$e->getMessage());
                    $v('receive loop exception: '.$e::class.' '.$e->getMessage());
                } finally {
                    $state->running = false;
                    $conn->close();
                    $v('WebSocket connection closed');
                }

                if ($run->registeredOk) {
                    if ($state->userRequestedStop) {
                        $box->result = EdgeAgentResult::Finished;
                    } else {
                        $box->result = EdgeAgentResult::Disconnected;
                        $stderr('edge: WebSocket closed (idle, proxy, or server); HTTP forwarding paused. Heartbeats will keep the tunnel until you exit.');
                    }
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
     * @return array<string, mixed>
     */
    private static function handleHttpRequest(string $localHost, int $localPort, array $req, TunnelRewriteOptions $rewriteOptions): array
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
            return self::errorResponse($requestId, 502, 'invalid body_b64');
        }

        $target = 'http://'.$localHost.':'.$localPort.$path;
        if ($query !== '') {
            $target .= '?'.$query;
        }

        $ch = curl_init($target);
        if ($ch === false) {
            return self::errorResponse($requestId, 502, 'curl_init failed');
        }

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            if (self::isHopHeader((string) $k)) {
                continue;
            }
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

            return self::errorResponse($requestId, 502, $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $headerBlock = substr($response, 0, $headerSize);
        $respBody = substr($response, $headerSize);

        $outHeaders = self::parseResponseHeaders($headerBlock);
        $lookup = TunnelResponseRewriter::tunnelRewriteHostLookup($localHost);
        $outHeaders = TunnelResponseRewriter::rewriteRedirectHeaders($outHeaders, $headers, $lookup);
        $respBody = TunnelResponseRewriter::maybeRewriteBody($respBody, $outHeaders, $headers, $localHost, $rewriteOptions);

        return [
            'type' => self::TYPE_HTTP_RESPONSE,
            'request_id' => $requestId,
            'status' => $status,
            'headers' => $outHeaders,
            'body_b64' => base64_encode($respBody),
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
}
