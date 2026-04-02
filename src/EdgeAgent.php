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

    /**
     * Blocks until the edge connection closes or the user stops (signal / cancellation).
     * Runs heartbeats concurrently (same interval as {@see Application} share loop).
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
    ): void {
        EventLoop::run(function () use ($wsUrl, $tunnelId, $agentToken, $localHost, $localPort, $apiClient, $heartbeatTunnelId, $stderr): void {
            $state = new class
            {
                public bool $running = true;
            };

            $cancelRead = new DeferredCancellation;
            $onStop = function () use ($state, $cancelRead): void {
                $state->running = false;
                $cancelRead->cancel();
            };

            $sigIds = [];
            try {
                $sigIds[] = EventLoop::onSignal(\SIGINT, $onStop);
                $sigIds[] = EventLoop::onSignal(\SIGTERM, $onStop);
            } catch (UnsupportedFeatureException) {
            }

            try {
                try {
                    $conn = connect($wsUrl);
                } catch (\Throwable $e) {
                    $stderr('edge WebSocket connect failed: '.$e->getMessage());

                    return;
                }

                if (! $conn instanceof WebsocketConnection) {
                    $stderr('edge: unexpected connection type');

                    return;
                }

                $reg = json_encode([
                    'type' => self::TYPE_REGISTER,
                    'tunnel_id' => $tunnelId,
                    'agent_token' => $agentToken,
                ], JSON_THROW_ON_ERROR);
                $conn->sendText($reg);

                $first = $conn->receive();
                if ($first === null) {
                    $stderr('edge: closed before registration ack');

                    return;
                }

                $rawFirst = $first->buffer();
                $type = self::parseType($rawFirst);
                if ($type === self::TYPE_ERROR) {
                    /** @var array<string, mixed> $err */
                    $err = json_decode($rawFirst, true, 512, JSON_THROW_ON_ERROR);
                    $stderr('edge error: '.(string) ($err['message'] ?? $rawFirst));

                    return;
                }
                if ($type !== self::TYPE_REGISTERED) {
                    $stderr('edge: unexpected first message: '.$rawFirst);

                    return;
                }

                $stderr('Edge agent connected; forwarding HTTP to local upstream.');

                async(function () use ($state, $apiClient, $heartbeatTunnelId, $stderr): void {
                    while ($state->running) {
                        delay(25.0);
                        if (! $state->running) {
                            break;
                        }
                        try {
                            $apiClient->heartbeat($heartbeatTunnelId);
                        } catch (\Throwable $e) {
                            $stderr('heartbeat failed: '.$e->getMessage());
                        }
                    }
                })->ignore();

                try {
                    while ($state->running) {
                        try {
                            $msg = $conn->receive($cancelRead->getCancellation());
                        } catch (CancelledException) {
                            break;
                        }
                        if ($msg === null) {
                            break;
                        }
                        $raw = $msg->buffer();
                        if (self::parseType($raw) !== self::TYPE_HTTP_REQUEST) {
                            continue;
                        }
                        /** @var array<string, mixed> $req */
                        $req = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                        $res = self::handleHttpRequest($localHost, $localPort, $req);
                        $conn->sendText(json_encode($res, JSON_THROW_ON_ERROR));
                    }
                } finally {
                    $state->running = false;
                    $conn->close();
                }
            } finally {
                foreach ($sigIds as $id) {
                    EventLoop::cancel($id);
                }
            }
        });
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
    private static function handleHttpRequest(string $localHost, int $localPort, array $req): array
    {
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
            curl_close($ch);

            return self::errorResponse($requestId, 502, $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlock = substr($response, 0, $headerSize);
        $respBody = substr($response, $headerSize);

        $outHeaders = self::parseResponseHeaders($headerBlock);

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
