<?php

declare(strict_types=1);

namespace JettyCli;

final class ApiClient
{
    public const VERSION = '0.1.9';

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
            throw new \RuntimeException('create tunnel: HTTP '.$res['status'].': '.$res['body']);
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
}
