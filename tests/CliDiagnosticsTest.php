<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\CliDiagnostics;
use PHPUnit\Framework\TestCase;

final class CliDiagnosticsTest extends TestCase
{
    public function test_http_500_includes_api_tail_in_suggestions(): void
    {
        $e = new \RuntimeException(
            'create tunnel: HTTP 500: {"exception":"Symfony\\\\Component\\\\Routing\\\\Exception\\\\RouteNotFoundException"}',
        );
        $diag = CliDiagnostics::diagnose($e);
        $this->assertNotNull($diag);
        $this->assertStringStartsWith(
            'Bridge/API response',
            $diag['suggestions'][0],
        );
        $this->assertStringContainsString('RouteNotFoundException', $diag['suggestions'][0]);
    }

    public function test_http_500_without_tail_still_has_generic_suggestions(): void
    {
        $e = new \RuntimeException('attach tunnel: HTTP 500');
        $diag = CliDiagnostics::diagnose($e);
        $this->assertNotNull($diag);
        $this->assertCount(3, $diag['suggestions']);
        $this->assertStringContainsString('temporary', $diag['suggestions'][0]);
    }
}
