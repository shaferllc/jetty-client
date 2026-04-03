<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Optional allowlist for {@code jetty share} local upstream hostnames / IPs.
 *
 * Set {@code JETTY_SHARE_UPSTREAM_ALLOW_HOSTS} to a comma-separated list. When non-empty, only
 * matching hosts are allowed (checked at share start and on each proxied request).
 *
 * Patterns:
 * - Literal host: {@code beacon.test}, {@code 127.0.0.1}, {@code localhost}
 * - Wildcard suffix: {@code *.test} matches {@code foo.test} and {@code a.b.test}; also matches {@code test} alone
 */
final class ShareUpstreamHostPolicy
{
    private const ENV = 'JETTY_SHARE_UPSTREAM_ALLOW_HOSTS';

    /** @var list<string> lower-case literals */
    private array $literals = [];

    /** @var list<string> lower-case suffixes (without leading dot) for *.suffix patterns */
    private array $suffixWildcards = [];

    private function __construct() {}

    public static function fromEnvironment(): self
    {
        $p = new self;
        $raw = getenv(self::ENV);
        if (! is_string($raw)) {
            return $p;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return $p;
        }
        foreach (explode(',', $raw) as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if (str_starts_with($part, '*.')) {
                $suffix = substr($part, 2);
                if ($suffix !== '') {
                    $p->suffixWildcards[] = $suffix;
                }

                continue;
            }
            $p->literals[] = $part;
        }

        return $p;
    }

    /**
     * True when the env is unset or empty — all hosts allowed.
     */
    public function isOpen(): bool
    {
        return $this->literals === [] && $this->suffixWildcards === [];
    }

    public function allows(string $host): bool
    {
        if ($this->isOpen()) {
            return true;
        }
        $h = strtolower(trim($host));
        if ($h === '') {
            return false;
        }
        foreach ($this->literals as $lit) {
            if ($h === $lit) {
                return true;
            }
        }
        foreach ($this->suffixWildcards as $suffix) {
            if ($h === $suffix || str_ends_with($h, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    public function denyMessage(string $host): string
    {
        return 'Upstream host '.($host !== '' ? '"'.$host.'"' : '(empty)').' is not allowed. Set '.self::ENV.' to a comma-separated allowlist (e.g. `127.0.0.1,localhost,*.test`).';
    }
}
