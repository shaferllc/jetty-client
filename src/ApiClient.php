<?php

declare(strict_types=1);

namespace JettyCli;

final class ApiClient
{
    public const VERSION = '0.1.60';

    /** Default GitHub owner/repo for PHAR `jetty update` / `self-update` when JETTY_*_REPO env is unset (matches Bridge config/jetty.php cli_github_repo). */
    public const DEFAULT_PHAR_RELEASES_REPO = 'shaferllc/jetty';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
    ) {}

    public function apiBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function createTunnel(string $localHost, int $localPort, ?string $subdomain = null, ?string $tunnelServer = null): array
    {
        $body = ['local_host' => $localHost, 'local_port' => $localPort];
        if ($subdomain !== null && trim($subdomain) !== '') {
            $body['subdomain'] = trim($subdomain);
        }
        if ($tunnelServer !== null && trim($tunnelServer) !== '') {
            $body['server'] = trim($tunnelServer);
        }
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $res = $this->request('POST', '/api/tunnels', $payload);

        if ($res['status'] !== 201) {
            throw new \RuntimeException(self::formatTunnelMutationError('create tunnel', $res['status'], $res['body']));
        }

        $json = json_decode($res['body'], true, flags: JSON_THROW_ON_ERROR);

        return is_array($json['data'] ?? null) ? $json['data'] : throw new \RuntimeException('Invalid API response');
    }

    /**
     * Reconnect the edge agent to an existing tunnel (new agent_token; same response shape as create).
     *
     * @return array<string, mixed>
     */
    public function attachTunnel(int $tunnelId, string $localHost, int $localPort, ?string $tunnelServer = null): array
    {
        $body = [
            'local_host' => $localHost,
            'local_port' => $localPort,
            'server' => ($tunnelServer !== null && trim($tunnelServer) !== '') ? trim($tunnelServer) : null,
        ];
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $res = $this->request('POST', '/api/tunnels/'.$tunnelId.'/attach', $payload);

        if ($res['status'] !== 200) {
            throw new \RuntimeException(self::formatTunnelMutationError('attach tunnel', $res['status'], $res['body']));
        }

        $json = json_decode($res['body'], true, flags: JSON_THROW_ON_ERROR);

        return is_array($json['data'] ?? null) ? $json['data'] : throw new \RuntimeException('Invalid API response');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTunnels(): array
    {
        $res = $this->request('GET', '/api/tunnels', null);

        if ($res['status'] !== 200) {
            throw new \RuntimeException('list tunnels: HTTP '.$res['status'].': '.$res['body']);
        }

        $json = json_decode($res['body'], true, flags: JSON_THROW_ON_ERROR);
        $data = $json['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta?: array<string, mixed>}
     */
    public function listReservedSubdomains(): array
    {
        $res = $this->request('GET', '/api/reserved-subdomains', null);

        if ($res['status'] !== 200) {
            throw new \RuntimeException('list reserved subdomains: HTTP '.$res['status'].': '.$res['body']);
        }

        $json = json_decode($res['body'], true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($json)) {
            throw new \RuntimeException('Invalid API response');
        }

        $data = $json['data'] ?? [];
        $data = is_array($data) ? $data : [];

        return [
            'data' => $data,
            'meta' => is_array($json['meta'] ?? null) ? $json['meta'] : [],
        ];
    }

    public function deleteTunnel(int $id): void
    {
        $res = $this->request('DELETE', '/api/tunnels/'.$id, null);

        if (! in_array($res['status'], [200, 204], true)) {
            throw new \RuntimeException('delete tunnel: HTTP '.$res['status'].': '.$res['body']);
        }
    }

    public function heartbeat(int $id): void
    {
        $res = $this->request('POST', '/api/tunnels/'.$id.'/heartbeat', null);

        if ($res['status'] !== 200) {
            throw new \RuntimeException('heartbeat: HTTP '.$res['status'].': '.$res['body']);
        }
    }

    /**
     * @param  array<string, mixed>  $sample
     */
    public function postRequestSample(int $tunnelId, array $sample): void
    {
        $body = [
            'method' => $sample['method'] ?? 'GET',
            'path' => $sample['path'] ?? '/',
            'query' => $sample['query'] ?? null,
            'status' => (int) ($sample['status'] ?? 502),
            'bytes_in' => (int) ($sample['bytes_in'] ?? 0),
            'bytes_out' => (int) ($sample['bytes_out'] ?? 0),
            'headers' => is_array($sample['headers'] ?? null) ? $sample['headers'] : [],
        ];
        $payload = json_encode($body, JSON_THROW_ON_ERROR);
        $res = $this->request('POST', '/api/tunnels/'.$tunnelId.'/request-samples', $payload);

        if (! in_array($res['status'], [200, 201], true)) {
            throw new \RuntimeException('request sample: HTTP '.$res['status'].': '.$res['body']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getRequestSample(int $sampleId): array
    {
        $res = $this->request('GET', '/api/tunnel-request-samples/'.$sampleId, null);

        if ($res['status'] !== 200) {
            throw new \RuntimeException('get request sample: HTTP '.$res['status'].': '.$res['body']);
        }

        $json = json_decode($res['body'], true, flags: JSON_THROW_ON_ERROR);

        return is_array($json['data'] ?? null) ? $json['data'] : throw new \RuntimeException('Invalid API response');
    }

    /**
     * Human-readable API failure for tunnel create/attach (parses JSON {@code message} / {@code hint}).
     */
    public static function formatTunnelMutationError(string $operation, int $httpStatus, string $body): string
    {
        $decoded = json_decode($body, true);
        $msg = '';
        $hint = '';
        if (is_array($decoded)) {
            if (isset($decoded['message']) && is_string($decoded['message'])) {
                $msg = trim($decoded['message']);
            }
            if (isset($decoded['hint']) && is_string($decoded['hint'])) {
                $hint = trim($decoded['hint']);
            }
        }
        if ($msg === '') {
            $trim = trim($body);
            if (strlen($trim) > 400) {
                $trim = substr($trim, 0, 400).'…';
            }

            return $operation.': HTTP '.$httpStatus.($trim !== '' ? ': '.$trim : '');
        }
        $out = $operation.': '.$msg;
        if ($hint !== '') {
            $out .= "\n\n".$hint;
        } elseif ($httpStatus === 422 && self::bodyLooksLikeTunnelTeamLimit($msg)) {
            $out .= "\n\n".self::tunnelTeamLimitCliHint();
        }

        return $out;
    }

    private static function bodyLooksLikeTunnelTeamLimit(string $message): bool
    {
        $m = strtolower($message);

        return str_contains($m, 'tunnel limit')
            || str_contains($m, 'remove a tunnel')
            || str_contains($m, 'upgrade your plan');
    }

    private static function tunnelTeamLimitCliHint(): string
    {
        return "Your team already has the maximum number of tunnel rows Bridge allows.\n"
            ."• List them: jetty list\n"
            ."• Remove one you no longer need: jetty delete <id> (or delete in the Jetty app)\n"
            ."• Same site, new TLS port: recent CLIs resume beacon.test:80 when you share beacon.test:443 (and vice versa) so you usually do not need a second row.\n"
            .'Tunnels are not removed automatically when you exit `jetty share` — they stay registered until you delete them (or use JETTY_SHARE_DELETE_ON_EXIT=1).';
    }

    /**
     * @return array{status: int, body: string}
     */
    private function request(string $method, string $path, ?string $body): array
    {
        $url = $this->baseUrl.$path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $headers = [
            'Authorization: Bearer '.$this->token,
            'Accept: application/json',
            'User-Agent: jetty-client/'.self::VERSION,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        if ($responseBody === false) {
            $err = curl_error($ch);
            throw new \RuntimeException('HTTP request failed: '.$err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return ['status' => $status, 'body' => (string) $responseBody];
    }

    /**
     * Anonymous telemetry when the edge WebSocket handshake fails (e.g. nginx 502). Fire-and-forget; never throws.
     * Opt out with JETTY_SHARE_TELEMETRY=0.
     */
    public function postEdgeWsFailureTelemetry(int $httpStatus, ?string $reason = null): void
    {
        if (getenv('JETTY_SHARE_TELEMETRY') === '0') {
            return;
        }
        if ($httpStatus < 100 || $httpStatus > 599) {
            return;
        }

        $url = rtrim($this->baseUrl, '/').'/api/telemetry/edge-ws-failure';
        $body = json_encode(array_filter([
            'http_status' => $httpStatus,
            'reason' => $reason !== null && $reason !== '' ? substr($reason, 0, 200) : null,
            'client' => 'jetty-php/'.self::VERSION,
        ], static fn ($v) => $v !== null && $v !== ''), JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: jetty-client/'.self::VERSION,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
