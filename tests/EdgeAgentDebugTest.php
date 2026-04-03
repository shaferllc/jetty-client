<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgentDebug;
use PHPUnit\Framework\TestCase;

final class EdgeAgentDebugTest extends TestCase
{
    public function test_redact_sensitive_request_headers(): void
    {
        $out = EdgeAgentDebug::redactSensitiveRequestHeaders([
            'Host' => 'x.test',
            'Cookie' => 'secret=1',
            'Authorization' => 'Bearer x',
        ]);
        $this->assertSame('x.test', $out['Host']);
        $this->assertSame('[redacted]', $out['Cookie']);
        $this->assertSame('[redacted]', $out['Authorization']);
    }

    public function test_enabled_from_environment(): void
    {
        $key = 'JETTY_SHARE_DEBUG_AGENT';
        $prev = getenv($key);
        putenv($key.'=1');
        $this->assertTrue(EdgeAgentDebug::enabledFromEnvironment());
        putenv($key.'=0');
        $this->assertFalse(EdgeAgentDebug::enabledFromEnvironment());
        if ($prev === false) {
            putenv($key);
        } else {
            putenv($key.'='.$prev);
        }
    }
}
