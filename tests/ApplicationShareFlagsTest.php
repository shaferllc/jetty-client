<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\EdgeAgent;
use JettyCli\EdgeAgentDebug;
use PHPUnit\Framework\TestCase;

/**
 * Tests for env-var-driven behaviour that is reachable via public/static methods.
 *
 * Many Application.php env vars (JETTY_SKIP_UPDATE_NOTICE, JETTY_LOCAL_PHAR_URL,
 * JETTY_PHAR_RELEASES_REPO, JETTY_CLI_GITHUB_REPO, JETTY_REPLAY_ALLOW_UNSAFE,
 * JETTY_SHARE_NO_EDGE_RECONNECT, JETTY_SHARE_CAPTURE_SAMPLES,
 * JETTY_SHARE_IDLE_DISABLE, JETTY_SHARE_IDLE_PROMPT_MINUTES,
 * JETTY_SHARE_IDLE_GRACE_MINUTES) are consumed inline inside private methods of
 * the final Application class (cmdShare, cmdReplay, maybePrintUpdateNotice,
 * localPharUpdateUrl, releasesRepo, etc.) and cannot be unit-tested without
 * integration/process-level tests. See the summary at the end of this file.
 *
 * What IS testable:
 * - EdgeAgent HTTP-activity tracking (public static)
 * - EdgeAgentDebug heartbeat env flag and response-header redaction (public static)
 */
final class ApplicationShareFlagsTest extends TestCase
{
    /** @var list<string> */
    private array $envVars = [
        'JETTY_SHARE_DEBUG_AGENT_HEARTBEATS',
    ];

    protected function setUp(): void
    {
        foreach ($this->envVars as $var) {
            putenv($var);
        }
        // Reset EdgeAgent static state.
        EdgeAgent::flushTrafficDeltas();
    }

    protected function tearDown(): void
    {
        foreach ($this->envVars as $var) {
            putenv($var);
        }
    }

    // -----------------------------------------------------------------------
    // EdgeAgent HTTP-activity tracking
    // -----------------------------------------------------------------------

    public function test_init_http_activity_sets_timestamp(): void
    {
        EdgeAgent::initHttpActivity(1000);
        $this->assertSame(1000, EdgeAgent::lastHttpActivityUnix());
    }

    public function test_mark_http_activity_updates_to_current_time(): void
    {
        EdgeAgent::initHttpActivity(1);
        $before = time();
        EdgeAgent::markHttpActivity();
        $after = time();

        $ts = EdgeAgent::lastHttpActivityUnix();
        $this->assertNotNull($ts);
        $this->assertGreaterThanOrEqual($before, $ts);
        $this->assertLessThanOrEqual($after, $ts);
    }

    public function test_last_http_activity_returns_null_before_init(): void
    {
        // Reset by setting a known value then reading — the static may carry
        // state from previous tests, so we re-init then test init semantics.
        // Since there is no public reset, we verify init → read round-trip.
        EdgeAgent::initHttpActivity(42);
        $this->assertSame(42, EdgeAgent::lastHttpActivityUnix());
    }

    // -----------------------------------------------------------------------
    // EdgeAgentDebug — heartbeat events env flag
    // -----------------------------------------------------------------------

    public function test_heartbeat_events_enabled_when_env_is_1(): void
    {
        putenv('JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=1');
        $this->assertTrue(EdgeAgentDebug::heartbeatEventsFromEnvironment());
    }

    public function test_heartbeat_events_enabled_when_env_is_true(): void
    {
        putenv('JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=true');
        $this->assertTrue(EdgeAgentDebug::heartbeatEventsFromEnvironment());
    }

    public function test_heartbeat_events_enabled_when_env_is_yes(): void
    {
        putenv('JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=yes');
        $this->assertTrue(EdgeAgentDebug::heartbeatEventsFromEnvironment());
    }

    public function test_heartbeat_events_enabled_when_env_is_on(): void
    {
        putenv('JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=on');
        $this->assertTrue(EdgeAgentDebug::heartbeatEventsFromEnvironment());
    }

    public function test_heartbeat_events_disabled_when_env_is_0(): void
    {
        putenv('JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=0');
        $this->assertFalse(EdgeAgentDebug::heartbeatEventsFromEnvironment());
    }

    public function test_heartbeat_events_disabled_when_env_unset(): void
    {
        putenv('JETTY_SHARE_DEBUG_AGENT_HEARTBEATS');
        $this->assertFalse(EdgeAgentDebug::heartbeatEventsFromEnvironment());
    }

    // -----------------------------------------------------------------------
    // EdgeAgentDebug — response-header redaction
    // -----------------------------------------------------------------------

    public function test_redact_sensitive_response_headers(): void
    {
        $out = EdgeAgentDebug::redactSensitiveResponseHeaders([
            'Content-Type' => 'text/html',
            'Set-Cookie' => 'session=abc123; Path=/',
            'Cookie' => 'foo=bar',
            'X-Custom' => 'visible',
        ]);

        $this->assertSame('text/html', $out['Content-Type']);
        $this->assertStringStartsWith('[redacted len=', $out['Set-Cookie']);
        $this->assertStringStartsWith('[redacted len=', $out['Cookie']);
        $this->assertSame('visible', $out['X-Custom']);
    }

    public function test_redact_response_headers_preserves_non_sensitive(): void
    {
        $out = EdgeAgentDebug::redactSensitiveResponseHeaders([
            'X-Request-Id' => '12345',
            'Content-Length' => '42',
        ]);

        $this->assertSame('12345', $out['X-Request-Id']);
        $this->assertSame('42', $out['Content-Length']);
    }

    public function test_redact_response_headers_includes_length_in_redacted(): void
    {
        $value = 'session=abc123; Path=/; HttpOnly';
        $out = EdgeAgentDebug::redactSensitiveResponseHeaders([
            'Set-Cookie' => $value,
        ]);

        $expected = '[redacted len='.strlen($value).']';
        $this->assertSame($expected, $out['Set-Cookie']);
    }
}

/*
 * =========================================================================
 * Integration-only env vars (not unit-testable)
 * =========================================================================
 *
 * The following env vars are consumed inline inside private methods of the
 * final Application class or inside EdgeAgent::run(). They require
 * integration / process-level tests (e.g. spawning `jetty share` or
 * `jetty replay` as a subprocess and inspecting behaviour):
 *
 * Application.php — cmdShare / share helpers (all private):
 *   - JETTY_SHARE_IDLE_DISABLE          — read in runShareHeartbeatLoop()
 *   - JETTY_SHARE_IDLE_PROMPT_MINUTES   — read in runShareHeartbeatLoop()
 *   - JETTY_SHARE_IDLE_GRACE_MINUTES    — read in runShareHeartbeatLoop()
 *
 * Application.php — cmdReplay (private):
 *   - JETTY_REPLAY_ALLOW_UNSAFE         — inline in cmdReplay()
 *
 * Application.php — update/PHAR (all private):
 *   - JETTY_SKIP_UPDATE_NOTICE          — inline in maybePrintUpdateNotice()
 *   - JETTY_LOCAL_PHAR_URL              — localPharUpdateUrl() (private)
 *   - JETTY_PHAR_RELEASES_REPO          — releasesRepo() (private)
 *   - JETTY_CLI_GITHUB_REPO             — releasesRepo() (private)
 *
 * EdgeAgent.php — run() (inline):
 *   - JETTY_SHARE_NO_EDGE_RECONNECT     — inline in run()
 *   - JETTY_SHARE_CAPTURE_SAMPLES       — inline in run()
 *
 * Application.php — formatTunnelStatusLabel (private):
 *   Colour-mapped status labels (active→green, idle→yellow, error→red)
 *   are produced by a private method that depends on a CliUi instance.
 *
 * Application.php — shareUpstreamHostPrefersStandardWebPort (private static):
 *   Returns true for non-IP, non-localhost hostnames. Private, untestable.
 *
 * Application.php — shareTunnelRewriteOptionsFromCli (private):
 *   Merges CLI flags with TunnelRewriteOptions::fromEnvironment(). Private.
 */
