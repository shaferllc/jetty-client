<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Reads update-notice environment variables.
 *
 * Env vars:
 *  - JETTY_SKIP_UPDATE_NOTICE=1   suppress "update available" hint
 *  - JETTY_LOCAL_PHAR_URL          force PHAR downloads from this URL instead of GitHub
 */
final class UpdateConfig
{
    /**
     * Whether the user has opted out of the post-command update notice.
     */
    public static function isNoticeSkipped(): bool
    {
        return getenv('JETTY_SKIP_UPDATE_NOTICE') === '1';
    }

    /**
     * A local/custom PHAR download URL, or null when unset/empty.
     */
    public static function localPharUrl(): ?string
    {
        $u = getenv('JETTY_LOCAL_PHAR_URL');
        if (! is_string($u)) {
            return null;
        }
        $u = trim($u);
        if ($u === '') {
            return null;
        }

        return $u;
    }
}
