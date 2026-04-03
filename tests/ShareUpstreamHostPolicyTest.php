<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\ShareUpstreamHostPolicy;
use PHPUnit\Framework\TestCase;

final class ShareUpstreamHostPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('JETTY_SHARE_UPSTREAM_ALLOW_HOSTS');
        parent::tearDown();
    }

    public function test_open_when_env_unset(): void
    {
        putenv('JETTY_SHARE_UPSTREAM_ALLOW_HOSTS');
        $p = ShareUpstreamHostPolicy::fromEnvironment();
        $this->assertTrue($p->isOpen());
        $this->assertTrue($p->allows('anything.example'));
    }

    public function test_literal_and_wildcard(): void
    {
        putenv('JETTY_SHARE_UPSTREAM_ALLOW_HOSTS=127.0.0.1,*.test,localhost');
        $p = ShareUpstreamHostPolicy::fromEnvironment();
        $this->assertFalse($p->isOpen());
        $this->assertTrue($p->allows('127.0.0.1'));
        $this->assertTrue($p->allows('beacon.test'));
        $this->assertTrue($p->allows('a.b.test'));
        $this->assertTrue($p->allows('localhost'));
        $this->assertFalse($p->allows('evil.com'));
    }
}
