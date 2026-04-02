<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Public Jetty Bridge host. Default API base is https://usejetty.online; optional region uses a subdomain.
 */
final class DefaultBridge
{
    public const HOST = 'usejetty.online';

    public const HTTPS_BASE = 'https://'.self::HOST;

    /**
     * Canonical Bridge API base URL (no trailing slash).
     * With a region (e.g. "eu", "us-east-1"): https://{region}.usejetty.online
     * Without: https://usejetty.online
     */
    public static function baseUrl(?string $region): string
    {
        if ($region === null || trim($region) === '') {
            return self::HTTPS_BASE;
        }

        $r = self::normalizeRegion($region);

        return 'https://'.$r.'.'.self::HOST;
    }

    public static function normalizeRegion(?string $region): string
    {
        if ($region === null) {
            return '';
        }
        $r = strtolower(trim($region));
        if ($r === '') {
            return '';
        }
        if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $r)) {
            throw new \InvalidArgumentException(
                'Invalid --region / JETTY_REGION: use letters, digits, hyphens (e.g. eu, us-east-1).'
            );
        }

        return $r;
    }

    public static function isProbablyLocalBridge(string $url): bool
    {
        $u = strtolower($url);

        return str_contains($u, 'localhost') || str_contains($u, '127.0.0.1') || str_contains($u, '0.0.0.0');
    }

    public static function allowLocalBridgeCandidates(): bool
    {
        $v = getenv('JETTY_ALLOW_LOCAL_BRIDGE');

        return $v === '1' || strtolower((string) $v) === 'true' || strtolower((string) $v) === 'yes';
    }
}
