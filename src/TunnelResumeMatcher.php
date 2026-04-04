<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Picks an existing tunnel row for {@code jetty share} resume (POST …/attach).
 *
 * Exact match uses {@code local_target} {@code host:port}. When {@code $allowStandardHttpHttpsPortFallback}
 * is true (Valet-style hostnames), {@code host:80} and {@code host:443} are treated as the same site so
 * switching TLS does not force a new tunnel (and hit per-team limits).
 */
final class TunnelResumeMatcher
{
    /**
     * @param  list<array<string, mixed>>  $tunnels
     * @return array<string, mixed>|null
     */
    public static function findResumableTunnel(
        array $tunnels,
        string $localHost,
        int $port,
        ?string $subdomain,
        ?string $tunnelServer,
        bool $allowStandardHttpHttpsPortFallback,
    ): ?array {
        $exact = self::findExactMatch($tunnels, $localHost, $port, $subdomain, $tunnelServer);
        if ($exact !== null) {
            return $exact;
        }
        if (! $allowStandardHttpHttpsPortFallback) {
            return null;
        }
        if ($port !== 80 && $port !== 443) {
            return null;
        }

        return self::findStandardWebPortFallback($tunnels, $localHost, $port, $subdomain, $tunnelServer);
    }

    /**
     * @param  list<array<string, mixed>>  $tunnels
     * @return array<string, mixed>|null
     */
    private static function findExactMatch(
        array $tunnels,
        string $localHost,
        int $port,
        ?string $subdomain,
        ?string $tunnelServer,
    ): ?array {
        $target = $localHost.':'.$port;
        $wantServer = self::normalizeTunnelServer($tunnelServer);
        $wantSub = $subdomain !== null ? trim($subdomain) : '';

        $candidates = [];
        foreach ($tunnels as $t) {
            if (! is_array($t)) {
                continue;
            }
            if ((string) ($t['local_target'] ?? '') !== $target) {
                continue;
            }
            $gotServer = self::normalizeTunnelServer(
                isset($t['server']) && is_string($t['server']) ? $t['server'] : null
            );
            if ($gotServer !== $wantServer) {
                continue;
            }
            if ($wantSub !== '' && trim((string) ($t['subdomain'] ?? '')) !== $wantSub) {
                continue;
            }
            $candidates[] = $t;
        }

        return self::pickBestCandidate($candidates, $wantSub);
    }

    /**
     * @param  list<array<string, mixed>>  $tunnels
     * @return array<string, mixed>|null
     */
    private static function findStandardWebPortFallback(
        array $tunnels,
        string $localHost,
        int $port,
        ?string $subdomain,
        ?string $tunnelServer,
    ): ?array {
        $wantServer = self::normalizeTunnelServer($tunnelServer);
        $wantSub = $subdomain !== null ? trim($subdomain) : '';
        $otherPort = $port === 443 ? 80 : 443;

        $candidates = [];
        foreach ($tunnels as $t) {
            if (! is_array($t)) {
                continue;
            }
            $parsed = self::parseLocalTarget((string) ($t['local_target'] ?? ''));
            if ($parsed === null) {
                continue;
            }
            if (strcasecmp($parsed['host'], $localHost) !== 0) {
                continue;
            }
            if ($parsed['port'] !== $otherPort) {
                continue;
            }
            $gotServer = self::normalizeTunnelServer(
                isset($t['server']) && is_string($t['server']) ? $t['server'] : null
            );
            if ($gotServer !== $wantServer) {
                continue;
            }
            if ($wantSub !== '' && trim((string) ($t['subdomain'] ?? '')) !== $wantSub) {
                continue;
            }
            $candidates[] = $t;
        }

        return self::pickBestCandidate($candidates, $wantSub);
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>|null
     */
    private static function pickBestCandidate(array $candidates, string $wantSub): ?array
    {
        if ($candidates === []) {
            return null;
        }

        if ($wantSub !== '') {
            return $candidates[0];
        }

        usort($candidates, fn ($a, $b): int => ((string) ($b['id'] ?? '')) <=> ((string) ($a['id'] ?? '')));

        return $candidates[0];
    }

    public static function normalizeTunnelServer(?string $server): ?string
    {
        if ($server === null) {
            return null;
        }
        $t = trim($server);

        return $t === '' ? null : $t;
    }

    /**
     * @return array{host: string, port: int}|null
     */
    public static function parseLocalTarget(string $localTarget): ?array
    {
        $i = strrpos($localTarget, ':');
        if ($i === false) {
            return null;
        }
        $portStr = substr($localTarget, $i + 1);
        if ($portStr === '' || ! ctype_digit($portStr)) {
            return null;
        }
        $host = substr($localTarget, 0, $i);
        if ($host === '') {
            return null;
        }
        $port = (int) $portStr;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return ['host' => $host, 'port' => $port];
    }
}
