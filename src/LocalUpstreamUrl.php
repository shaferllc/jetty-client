<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Origin URL for curl toward the developer's local server (share / edge agent).
 *
 * Port 443 uses HTTPS so Valet/Herd nginx TLS matches; HTTP on :80 often 301s to https://local
 * and causes redirect loops through the public tunnel.
 */
final class LocalUpstreamUrl
{
    /**
     * Base URL without path/query (e.g. {@code https://app.test} or {@code http://127.0.0.1:8000}).
     */
    public static function baseForCurl(string $host, int $port): string
    {
        $host = trim($host);
        $useTls = $port === 443;
        $scheme = $useTls ? 'https' : 'http';
        $defaultPort = $useTls ? 443 : 80;

        if ($port === $defaultPort) {
            return $scheme.'://'.$host;
        }

        return $scheme.'://'.$host.':'.$port;
    }
}
