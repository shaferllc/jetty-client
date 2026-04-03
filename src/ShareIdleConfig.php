<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Reads idle-timeout environment variables for `jetty share` heartbeat loop.
 *
 * Env vars:
 *  - JETTY_SHARE_IDLE_DISABLE=1       disable idle prompt entirely
 *  - JETTY_SHARE_IDLE_PROMPT_MINUTES   minutes of idle before prompt (default 120; <=0 disables)
 *  - JETTY_SHARE_IDLE_GRACE_MINUTES    grace minutes after prompt (default 60, min 1)
 */
final class ShareIdleConfig
{
    public function __construct(
        public readonly bool $disabled,
        public readonly int $promptMinutes,
        public readonly int $graceMinutes,
    ) {}

    public static function fromEnvironment(): self
    {
        $disabled = getenv('JETTY_SHARE_IDLE_DISABLE') === '1';
        $promptMinutes = (int) (getenv('JETTY_SHARE_IDLE_PROMPT_MINUTES') ?: '120');
        if ($promptMinutes <= 0) {
            $disabled = true;
        }
        $graceMinutes = max(1, (int) (getenv('JETTY_SHARE_IDLE_GRACE_MINUTES') ?: '60'));

        return new self($disabled, $promptMinutes, $graceMinutes);
    }
}
