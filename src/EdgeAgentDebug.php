<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Structured stderr logging for {@see EdgeAgent} when share debugging is enabled.
 *
 * Enable with {@code JETTY_SHARE_DEBUG_AGENT=1} or {@code jetty share --debug-agent}.
 * Optional noisy heartbeat lines: {@code JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=1}.
 */
final class EdgeAgentDebug
{
    public static function enabledFromEnvironment(): bool
    {
        return self::envTruthyString(self::envRawFirstNonEmpty(['JETTY_SHARE_DEBUG_AGENT']));
    }

    public static function heartbeatEventsFromEnvironment(): bool
    {
        return self::envTruthyString(self::envRawFirstNonEmpty(['JETTY_SHARE_DEBUG_AGENT_HEARTBEATS']));
    }

    /**
     * @param  array<string, mixed>  $base  Merged into every event (tunnel_id, upstream, …)
     * @return callable(string, array<string, mixed>): void
     */
    public static function stderrJsonSink(array $base): callable
    {
        return function (string $event, array $context) use ($base): void {
            $payload = array_merge($base, $context, [
                'event' => $event,
                'ts_ms' => (int) round(microtime(true) * 1000),
            ]);
            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
            try {
                $json = json_encode($payload, $flags | JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $payload = self::utf8SafePayload($payload);
                $json = json_encode($payload, $flags) ?: '{"event":"json_encode_failed"}';
            }
            if (\defined('STDERR') && \is_resource(STDERR)) {
                @fwrite(STDERR, '[jetty:agent-debug] '.$json."\n");
            } else {
                error_log('[jetty:agent-debug] '.$json);
            }
        };
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function redactSensitiveRequestHeaders(array $headers): array
    {
        $redact = [
            'cookie' => true,
            'authorization' => true,
            'proxy-authorization' => true,
        ];
        $out = [];
        foreach ($headers as $k => $v) {
            $kl = strtolower((string) $k);
            $out[$k] = isset($redact[$kl]) ? '[redacted]' : (string) $v;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public static function redactSensitiveResponseHeaders(array $headers): array
    {
        $redact = [
            'set-cookie' => true,
            'cookie' => true,
        ];
        $out = [];
        foreach ($headers as $k => $v) {
            $kl = strtolower((string) $k);
            $out[$k] = isset($redact[$kl]) ? '[redacted len='.strlen((string) $v).']' : (string) $v;
        }

        return $out;
    }

    /**
     * @param  list<non-empty-string>  $keys
     */
    private static function envRawFirstNonEmpty(array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = getenv($key);
            if (is_string($v) && trim($v) !== '') {
                return $v;
            }
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && trim($_SERVER[$key]) !== '') {
                return $_SERVER[$key];
            }
            if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
                return $_ENV[$key];
            }
        }

        return null;
    }

    private static function envTruthyString(?string $raw): bool
    {
        if ($raw === null) {
            return false;
        }
        $v = strtolower(trim($raw));

        return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function utf8SafePayload(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (is_string($v)) {
                $out[$k] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v) ?? $v;
            } elseif (is_array($v)) {
                /** @var array<string, mixed> $v */
                $out[$k] = self::utf8SafePayload($v);
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
