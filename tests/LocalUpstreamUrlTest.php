<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\LocalUpstreamUrl;
use PHPUnit\Framework\TestCase;

final class LocalUpstreamUrlTest extends TestCase
{
    public function test_http_default_port_omitted(): void
    {
        $this->assertSame('http://beacon.test', LocalUpstreamUrl::baseForCurl('beacon.test', 80));
    }

    public function test_https_default_port_omitted(): void
    {
        $this->assertSame('https://beacon.test', LocalUpstreamUrl::baseForCurl('beacon.test', 443));
    }

    public function test_nonstandard_port_in_url(): void
    {
        $this->assertSame('http://127.0.0.1:8000', LocalUpstreamUrl::baseForCurl('127.0.0.1', 8000));
    }
}
