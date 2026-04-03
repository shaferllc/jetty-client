<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Builds HTTP request headers for the local upstream curl in {@see EdgeAgent}.
 *
 * The edge forwards the public tunnel hostname as {@code Host}. Valet, Herd, and typical nginx
 * vhosts match {@code server_name} against {@code Host}; leaving the tunnel host in place yields
 * the wrong vhost (often 404). We send {@code Host} for the configured local site and preserve
 * the original hostname in {@code X-Forwarded-Host} for frameworks that care.
 */
final class TunnelUpstreamRequestHeaders
{
    /**
     * @param  array<string, string>  $edgeHeaders  Headers from the edge {@code http_request} frame
     * @return array<string, string>  Headers to pass to curl toward {@code http://localHost:localPort}
     */
    public static function forLocalUpstream(array $edgeHeaders, string $localHost, int $localPort): array
    {
        $originalHost = self::firstHeaderValue($edgeHeaders, 'Host');
        $upstreamHost = self::upstreamHostHeaderValue($localHost, $localPort);

        $out = [];
        foreach ($edgeHeaders as $k => $v) {
            if (self::isHopByHopHeader((string) $k)) {
                continue;
            }
            if (strcasecmp((string) $k, 'Host') === 0) {
                continue;
            }
            $out[$k] = $v;
        }

        $out['Host'] = $upstreamHost;

        if ($originalHost !== '' && strcasecmp($originalHost, $upstreamHost) !== 0) {
            if (! self::hasHeaderKey($out, 'X-Forwarded-Host')) {
                $out['X-Forwarded-Host'] = $originalHost;
            }
        }

        if (! self::hasHeaderKey($out, 'X-Forwarded-Proto')) {
            $out['X-Forwarded-Proto'] = 'https';
        }

        return $out;
    }

    public static function upstreamHostHeaderValue(string $localHost, int $localPort): string
    {
        if ($localPort === 80) {
            return $localHost;
        }

        return $localHost.':'.$localPort;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function firstHeaderValue(array $headers, string $name): string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return trim((string) $v);
            }
        }

        return '';
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function hasHeaderKey(array $headers, string $name): bool
    {
        foreach ($headers as $k => $_) {
            if (strcasecmp((string) $k, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function isHopByHopHeader(string $k): bool
    {
        $k = strtolower($k);

        return in_array($k, [
            'connection', 'keep-alive', 'proxy-connection', 'transfer-encoding', 'upgrade', 'te', 'trailer',
        ], true);
    }
}
