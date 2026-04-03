<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\ApiClient;
use PHPUnit\Framework\TestCase;

final class ApiClientFormatTunnelMutationErrorTest extends TestCase
{
    public function test_parses_message_and_hint(): void
    {
        $body = json_encode([
            'message' => 'Tunnel limit reached.',
            'hint' => 'Run jetty list.',
        ], JSON_THROW_ON_ERROR);
        $out = ApiClient::formatTunnelMutationError('create tunnel', 422, $body);
        $this->assertStringContainsString('Tunnel limit reached.', $out);
        $this->assertStringContainsString('Run jetty list.', $out);
    }

    public function test_tunnel_limit_adds_cli_hint_when_no_api_hint(): void
    {
        $body = json_encode([
            'message' => 'Tunnel limit reached for this team. Remove a tunnel or upgrade your plan under Billing.',
        ], JSON_THROW_ON_ERROR);
        $out = ApiClient::formatTunnelMutationError('create tunnel', 422, $body);
        $this->assertStringContainsString('jetty list', $out);
        $this->assertStringContainsString('jetty delete', $out);
        $this->assertStringContainsString('not removed automatically', $out);
    }

    public function test_non_json_body(): void
    {
        $out = ApiClient::formatTunnelMutationError('create tunnel', 500, 'upstream error');
        $this->assertStringContainsString('HTTP 500', $out);
        $this->assertStringContainsString('upstream error', $out);
    }
}
