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
        $bodyOff = getenv('JETTY_SHARE_NO_BODY_REWRITE') === '1'
            || getenv('JETTY_SHARE_BODY_REWRITE') === '0';
        $bodyOn = ! $bodyOff;

        $jsOff = getenv('JETTY_SHARE_NO_JS_REWRITE') === '1'
            || getenv('JETTY_SHARE_JS_REWRITE') === '0';
        $jsOn = $bodyOn && ! $jsOff;

        $cssOff = getenv('JETTY_SHARE_NO_CSS_REWRITE') === '1'
            || getenv('JETTY_SHARE_CSS_REWRITE') === '0';
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
}
