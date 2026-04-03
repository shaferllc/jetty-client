<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\UpdateConfig;
use PHPUnit\Framework\TestCase;

final class UpdateConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_SKIP_UPDATE_NOTICE');
        putenv('JETTY_LOCAL_PHAR_URL');
    }

    // --- isNoticeSkipped ---

    public function test_notice_not_skipped_by_default(): void
    {
        putenv('JETTY_SKIP_UPDATE_NOTICE');

        $this->assertFalse(UpdateConfig::isNoticeSkipped());
    }

    public function test_notice_skipped_when_set_to_one(): void
    {
        putenv('JETTY_SKIP_UPDATE_NOTICE=1');

        $this->assertTrue(UpdateConfig::isNoticeSkipped());
    }

    public function test_notice_not_skipped_for_other_values(): void
    {
        putenv('JETTY_SKIP_UPDATE_NOTICE=0');

        $this->assertFalse(UpdateConfig::isNoticeSkipped());
    }

    // --- localPharUrl ---

    public function test_local_phar_url_null_by_default(): void
    {
        putenv('JETTY_LOCAL_PHAR_URL');

        $this->assertNull(UpdateConfig::localPharUrl());
    }

    public function test_local_phar_url_returns_value_when_set(): void
    {
        putenv('JETTY_LOCAL_PHAR_URL=https://example.com/jetty.phar');

        $this->assertSame('https://example.com/jetty.phar', UpdateConfig::localPharUrl());
    }

    public function test_local_phar_url_trims_whitespace(): void
    {
        putenv('JETTY_LOCAL_PHAR_URL=  https://example.com/jetty.phar  ');

        $this->assertSame('https://example.com/jetty.phar', UpdateConfig::localPharUrl());
    }

    public function test_local_phar_url_null_when_empty(): void
    {
        putenv('JETTY_LOCAL_PHAR_URL=');

        $this->assertNull(UpdateConfig::localPharUrl());
    }

    public function test_local_phar_url_null_when_only_whitespace(): void
    {
        putenv('JETTY_LOCAL_PHAR_URL=   ');

        $this->assertNull(UpdateConfig::localPharUrl());
    }
}
