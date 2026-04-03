<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Reads replay-related environment variables.
 *
 * Env vars:
 *  - JETTY_REPLAY_ALLOW_UNSAFE=1   allow replaying non-GET/HEAD methods
 */
final class ReplayConfig
{
    public static function allowUnsafe(): bool
    {
        return getenv('JETTY_REPLAY_ALLOW_UNSAFE') === '1';
    }
}
