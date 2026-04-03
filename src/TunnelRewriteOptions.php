<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Controls tunnel URL rewriting for {@see TunnelResponseRewriter} and {@see EdgeAgent}.
 * CLI flags override environment for a single `jetty share` run.
 */
final class TunnelRewriteOptions
{
    private const DEFAULT_MAX_BYTES = 4194304; // 4 MiB

    public function __construct(
        public bool $bodyRewrite = true,
        public bool $jsRewrite = true,
        public bool $cssRewrite = true,
        public int $maxBodyBytes = self::DEFAULT_MAX_BYTES,
    ) {}

    /**
     * Read from environment. Defaults: body, JS, and CSS rewriting ON.
     * For `jetty share` CLI overrides, {@see Application::shareTunnelRewriteOptionsFromCli} builds an instance explicitly.
     */
    public static function fromEnvironment(): self
    {
        // Prefer JETTY_SHARE_NO_*=1; JETTY_SHARE_*_REWRITE=0 kept for backward compatibility.
        $bodyOff = self::envShareDisabled('JETTY_SHARE_NO_BODY_REWRITE', 'JETTY_SHARE_BODY_REWRITE');
        $bodyOn = ! $bodyOff;

        $jsOff = self::envShareDisabled('JETTY_SHARE_NO_JS_REWRITE', 'JETTY_SHARE_JS_REWRITE');
        $jsOn = $bodyOn && ! $jsOff;

        $cssOff = self::envShareDisabled('JETTY_SHARE_NO_CSS_REWRITE', 'JETTY_SHARE_CSS_REWRITE');
        $cssOn = $bodyOn && ! $cssOff;

        $max = self::DEFAULT_MAX_BYTES;
        $rawMax = getenv('JETTY_SHARE_BODY_REWRITE_MAX_BYTES');
        if (is_string($rawMax) && $rawMax !== '' && ctype_digit($rawMax)) {
            $max = max(1024, (int) $rawMax);
        }

        return new self(
            bodyRewrite: $bodyOn,
            jsRewrite: $jsOn,
            cssRewrite: $cssOn,
            maxBodyBytes: $max,
        );
    }

    private static function envShareDisabled(string $noKey, string $legacyZeroKey): bool
    {
        return getenv($noKey) === '1' || getenv($legacyZeroKey) === '0';
    }
}
