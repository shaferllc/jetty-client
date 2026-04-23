<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Structured CLI error diagnostics with suggested fixes.
 *
 * Maps common error patterns to human-friendly messages with actionable suggestions.
 */
final class CliDiagnostics
{
    /**
     * Analyze an exception and return a structured diagnostic with suggestions.
     *
     * @return array{error: string, cause: string, suggestions: list<string>}|null
     */
    public static function diagnose(\Throwable $e): ?array
    {
        $msg = $e->getMessage();

        // Connection refused to Bridge API
        if (preg_match('/connection refused|connect\(\) failed|curl.*error 7/i', $msg)) {
            return [
                'error' => 'Cannot connect to the Jetty API server.',
                'cause' => 'The Bridge server is not reachable. This usually means the API URL is wrong or the server is down.',
                'suggestions' => [
                    'Check your API URL: jetty config get api-url',
                    'Verify the server is running and accessible from your network.',
                    'If self-hosting, ensure your Bridge is started: php artisan serve',
                    'Try: jetty setup (to reconfigure the API URL)',
                ],
            ];
        }

        // SSL/TLS certificate errors
        if (preg_match('/SSL|certificate|CERT|tls/i', $msg)) {
            return [
                'error' => 'TLS/SSL certificate error.',
                'cause' => 'The server certificate could not be verified. This can happen with self-signed certs or expired certificates.',
                'suggestions' => [
                    'If self-hosting with a self-signed cert, check your CA bundle.',
                    'Verify the server certificate is valid: curl -v https://your-api-url/api/cli/bootstrap',
                    'Check system clock is correct (certificate validation is time-sensitive).',
                ],
            ];
        }

        // DNS resolution failure
        if (preg_match('/resolve host|name.*resolution|getaddrinfo|DNS/i', $msg)) {
            return [
                'error' => 'DNS resolution failed.',
                'cause' => 'The hostname in your API URL cannot be resolved.',
                'suggestions' => [
                    'Check your internet connection.',
                    'Verify the API URL hostname: jetty config get api-url',
                    'Try: nslookup <hostname> or dig <hostname>',
                    'If on VPN or corporate network, check DNS settings.',
                ],
            ];
        }

        // HTTP 401 Unauthorized
        if (preg_match('/HTTP 401|unauthorized|unauthenticated/i', $msg)) {
            return [
                'error' => 'Authentication failed.',
                'cause' => 'Your API token is invalid, expired, or missing.',
                'suggestions' => [
                    'Re-authenticate: jetty login',
                    'Check your token: jetty config get token',
                    'If using an API token directly, verify it has not been revoked in the dashboard.',
                    'Create a new token in Bridge > Tokens if needed.',
                ],
            ];
        }

        // HTTP 403 Forbidden
        if (preg_match('/HTTP 403|forbidden/i', $msg)) {
            return [
                'error' => 'Access denied.',
                'cause' => 'Your account does not have permission for this action.',
                'suggestions' => [
                    'Check your team role in Bridge > Members. You may need Developer or Manager role.',
                    'Ask a Team Owner to upgrade your role.',
                    'Verify you are in the correct organization: jetty config get',
                ],
            ];
        }

        // HTTP 404 - tunnel not found
        if (preg_match('/HTTP 404|not found|Tunnel not found/i', $msg)) {
            return [
                'error' => 'Resource not found.',
                'cause' => 'The tunnel or resource does not exist, or you do not have access to it.',
                'suggestions' => [
                    'List your tunnels: jetty list',
                    'The tunnel may have been deleted by another team member or expired.',
                    'If resuming, try creating a fresh tunnel: jetty share <port>',
                ],
            ];
        }

        // Tunnel limit reached
        if (preg_match('/tunnel limit|remove a tunnel|upgrade your plan/i', $msg)) {
            return [
                'error' => 'Tunnel limit reached.',
                'cause' => 'Your plan does not allow more concurrent tunnels.',
                'suggestions' => [
                    'Delete unused tunnels: jetty list, then jetty delete <id>',
                    'Tunnels stay registered after you stop jetty share until explicitly deleted.',
                    'Upgrade your plan in Bridge > Billing for more tunnels.',
                ],
            ];
        }

        // Rate limited
        if (preg_match('/HTTP 429|rate limit|too many requests/i', $msg)) {
            return [
                'error' => 'Rate limited.',
                'cause' => 'Too many requests in a short period.',
                'suggestions' => [
                    'Wait a few seconds and retry.',
                    'If this persists, reduce request frequency.',
                    'Per-tunnel rate limits can be configured in Bridge > Tunnel Settings.',
                ],
            ];
        }

        // WebSocket connection failure
        if (preg_match('/websocket|ws:\/\/|wss:\/\/|upgrade/i', $msg)) {
            return [
                'error' => 'WebSocket connection failed.',
                'cause' => 'Could not establish a WebSocket connection to the edge server.',
                'suggestions' => [
                    'Check that the edge server is running and accessible.',
                    'Verify firewall/proxy allows WebSocket upgrades (HTTP 101).',
                    'If behind a corporate proxy, it may block WebSocket connections.',
                    'Try: curl -v -H "Upgrade: websocket" https://your-tunnel-host/agent',
                    'Check JETTY_EDGE_WS_URL in your config.',
                ],
            ];
        }

        // Timeout
        if (preg_match('/timeout|timed out|ETIMEDOUT/i', $msg)) {
            return [
                'error' => 'Connection timed out.',
                'cause' => 'The server did not respond in time.',
                'suggestions' => [
                    'Check your internet connection.',
                    'The API or edge server may be temporarily overloaded.',
                    'If your local app is slow, increase the timeout: JETTY_SHARE_UPSTREAM_CONNECT_TIMEOUT=30',
                    'Try again in a few moments.',
                ],
            ];
        }

        // Port in use / EADDRINUSE
        if (preg_match('/address already in use|EADDRINUSE|port.*in use/i', $msg)) {
            return [
                'error' => 'Port already in use.',
                'cause' => 'Another process is using the specified port.',
                'suggestions' => [
                    'Check what is using the port: lsof -i :<port> (macOS/Linux)',
                    'Kill the conflicting process or choose a different port.',
                    'If another jetty share is running, stop it first or use --force.',
                ],
            ];
        }

        // Upstream connection refused (local app not running)
        if (preg_match('/upstream.*refused|localhost.*refused|127\.0\.0\.1.*refused/i', $msg)) {
            return [
                'error' => 'Local application not reachable.',
                'cause' => 'jetty share cannot connect to your local app.',
                'suggestions' => [
                    'Make sure your local app is running on the specified port.',
                    'Check: curl http://localhost:<port> (from the same machine)',
                    'If using Docker, ensure ports are mapped to the host.',
                    'If using --site=hostname, verify the hostname resolves to localhost.',
                ],
            ];
        }

        // Body too large
        if (preg_match('/body too large|413|entity too large/i', $msg)) {
            return [
                'error' => 'Request body too large.',
                'cause' => 'The request exceeds the maximum body size for this tunnel.',
                'suggestions' => [
                    'Default limit is 4MB (varies by plan).',
                    'Configure per-tunnel: set max_body_bytes in Bridge > Tunnel Settings.',
                    'For file uploads, consider using a direct upload URL instead of tunneling.',
                ],
            ];
        }

        // Generic HTTP error with status code
        if (preg_match('/HTTP (\d{3})/', $msg, $m)) {
            $code = (int) $m[1];
            if ($code >= 500) {
                $tail = self::extractHttpResponseTail($msg);
                $suggestions = [];
                if ($tail !== '') {
                    $suggestions[] =
                        'Bridge/API response (share this with support): '.$tail;
                }
                $suggestions = array_merge(
                    $suggestions,
                    [
                        'This is usually temporary. Try again in a few moments.',
                        'If the error persists, check https://status.usejetty.online for outages.',
                        'Contact support@usejetty.online with the full error output.',
                    ],
                );

                return [
                    'error' => 'Server error (HTTP '.$code.').',
                    'cause' => 'The Jetty server encountered an internal error.',
                    'suggestions' => $suggestions,
                ];
            }
        }

        return null;
    }

    /**
     * Text after {@code HTTP xxx:} in an API client exception (truncated).
     */
    private static function extractHttpResponseTail(string $msg): string
    {
        if (preg_match('/HTTP \d{3}:\s*(.+)/s', $msg, $m)) {
            $tail = trim((string) $m[1]);
            if ($tail === '') {
                return '';
            }
            if (strlen($tail) > 400) {
                return substr($tail, 0, 400).'…';
            }

            return $tail;
        }

        return '';
    }

    /**
     * Format a diagnostic as a styled CLI output string.
     *
     * @param  array{error: string, cause: string, suggestions: list<string>}  $diag
     */
    public static function format(array $diag): string
    {
        $lines = [];
        $lines[] = '';
        $lines[] = '  Error: '.$diag['error'];
        $lines[] = '  Cause: '.$diag['cause'];
        $lines[] = '';
        $lines[] = '  Suggested fixes:';
        foreach ($diag['suggestions'] as $i => $suggestion) {
            $lines[] = '    '.($i + 1).'. '.$suggestion;
        }
        $lines[] = '';

        return implode("\n", $lines);
    }
}
