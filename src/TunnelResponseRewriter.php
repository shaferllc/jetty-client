<?php

declare(strict_types=1);

namespace JettyCli;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Rewrites absolute URLs pointing at local / canonical dev hosts to the public tunnel host
 * (headers + HTML + CSS url() + quoted JS strings).
 */
final class TunnelResponseRewriter
{
    /** Bumps when tunnel rewrite / NDJSON diagnostics change (verify you are not on a stale PHAR). */
    public const REWRITE_DEBUG_REV = 4;

    /** @var list<string> */
    private const DATA_URL_ATTRS = ['data-url', 'data-href', 'data-src', 'data-action'];

    /** @var list<string>|null */
    private static ?array $walkUpAdjacentAppUrlHostsCache = null;

    private static string $walkUpAdjacentAppUrlHostsCacheKey = '';

    /**
     * Logs one line per tunneled HTTP request when debug is enabled (env JETTY_SHARE_DEBUG_REWRITE=1).
     *
     * @param  array<string, string>  $requestHeaders  Browser → edge request (includes Host)
     */
    public static function debugRewriteRequestContext(
        string $requestId,
        string $method,
        string $path,
        string $localHost,
        int $localPort,
        array $requestHeaders,
    ): void {
        if (self::debugNdjsonLogPath() !== null) {
            $ge = getenv('JETTY_SHARE_DEBUG_REWRITE');
            self::agentDebugNdjson('H1', 'TunnelResponseRewriter::debugRewriteRequestContext', [
                'request_id' => $requestId,
                'getenv_JETTY_SHARE_DEBUG_REWRITE' => $ge === false ? '(false)' : $ge,
                'debugRewriteEnabled' => self::debugRewriteEnabled(),
                'method' => $method,
                'path' => $path,
            ]);
        }
        if (! self::debugRewriteEnabled()) {
            return;
        }
        $tunnelHost = self::requestHostLower($requestHeaders);
        self::debugRewriteLine(
            'http '.$method.' '.$path
            .' request_id='.($requestId !== '' ? $requestId : '(none)')
            .' tunnel_Host='.$tunnelHost
            .' upstream=http://'.$localHost.':'.$localPort
        );
        $lookupPreview = self::debugRewriteLookupPreview($localHost);
        self::debugRewriteLine('rewrite_host_lookup: '.$lookupPreview);
    }

    /**
     * @param  array<string, string>  $responseHeaders
     * @param  array<string, string>  $requestHeaders
     * @param  array<string, true>  $rewriteHostsLookup
     * @return array<string, string>
     */
    public static function rewriteRedirectHeaders(
        array $responseHeaders,
        array $requestHeaders,
        array $rewriteHostsLookup,
    ): array {
        if (getenv('JETTY_SHARE_NO_LOCATION_REWRITE') === '1') {
            if (self::debugRewriteEnabled()) {
                self::debugRewriteLine('redirect_headers: skipped (JETTY_SHARE_NO_LOCATION_REWRITE=1)');
            }

            return $responseHeaders;
        }

        $publicHost = self::requestHostLower($requestHeaders);
        if (self::debugNdjsonLogPath() !== null) {
            $locForLog = '';
            foreach ($responseHeaders as $nk => $nv) {
                if (strcasecmp((string) $nk, 'Location') === 0) {
                    $locForLog = (string) $nv;
                    break;
                }
            }
            $locHost = '';
            if ($locForLog !== '') {
                $pu = parse_url($locForLog);
                $locHost = is_array($pu) && isset($pu['host']) ? strtolower((string) $pu['host']) : '';
            }
            $lookupKeys = array_keys($rewriteHostsLookup);
            sort($lookupKeys);
            self::agentDebugNdjson('H2-H4-H5', 'TunnelResponseRewriter::rewriteRedirectHeaders:pre', [
                'publicHost' => $publicHost,
                'no_location_rewrite' => getenv('JETTY_SHARE_NO_LOCATION_REWRITE') === '1',
                'lookup_size' => count($rewriteHostsLookup),
                'lookup_host_sample' => array_slice($lookupKeys, 0, 12),
                'location_host' => $locHost,
                'location_in_lookup' => $locHost !== '' && isset($rewriteHostsLookup[$locHost]),
                'location_preview' => self::truncateForLog($locForLog, 120),
                'invocation_cwd' => self::shareInvocationDirectoryForAppUrl(),
                'project_root_env_set' => getenv('JETTY_SHARE_PROJECT_ROOT') !== false,
            ]);
        }
        if ($publicHost === '') {
            if (self::debugRewriteEnabled()) {
                self::debugRewriteLine('redirect_headers: skipped (no tunnel Host on request)');
            }

            return $responseHeaders;
        }

        foreach (['Location', 'X-Inertia-Location', 'Refresh'] as $headerName) {
            foreach ($responseHeaders as $name => $value) {
                if (strcasecmp($name, $headerName) !== 0) {
                    continue;
                }
                $before = (string) $value;
                if (strcasecmp($headerName, 'Refresh') === 0) {
                    $rewritten = self::rewriteRefreshHeaderValue((string) $value, $rewriteHostsLookup, $publicHost, true);
                } else {
                    $rewritten = self::rewriteAbsoluteUrlToTunnel((string) $value, $rewriteHostsLookup, $publicHost, true);
                }
                if ($rewritten !== null) {
                    $responseHeaders[$name] = $rewritten;
                    if (self::debugRewriteEnabled()) {
                        self::debugRewriteLine($headerName.': '.$before.' -> '.$rewritten);
                    }
                } elseif (self::debugRewriteEnabled()) {
                    self::debugRewriteLine($headerName.': unchanged '.self::truncateForLog($before).' (not rewritten — see prior absolute_url lines)');
                }
            }
        }

        return $responseHeaders;
    }

    /**
     * @param  array<string, true>  $rewriteHostsLookup
     */
    public static function rewriteRefreshHeaderValue(string $value, array $rewriteHostsLookup, string $tunnelHostLower, bool $debugRedirect = false): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/url\s*=\s*/i', $value)) {
            return null;
        }
        if (! preg_match('/url\s*=\s*(.+)$/i', $value, $m)) {
            return null;
        }
        $urlPart = trim($m[1], " \t\"'");
        $rewritten = self::rewriteAbsoluteUrlToTunnel($urlPart, $rewriteHostsLookup, $tunnelHostLower, $debugRedirect);
        if ($rewritten === null) {
            return null;
        }

        return (string) preg_replace('/url\s*=\s*.+$/i', 'url='.$rewritten, $value, 1);
    }

    /**
     * @param  array<string, string>  $requestHeaders
     */
    public static function maybeRewriteBody(
        string $body,
        array $responseHeaders,
        array $requestHeaders,
        string $localHost,
        TunnelRewriteOptions $options,
    ): string {
        if (! $options->bodyRewrite) {
            return $body;
        }
        if (strlen($body) > $options->maxBodyBytes) {
            return $body;
        }

        $publicHost = self::requestHostLower($requestHeaders);
        if ($publicHost === '') {
            return $body;
        }

        $lookup = self::tunnelRewriteHostLookup($localHost);
        $ct = self::headerLine($responseHeaders, 'Content-Type');
        $mime = self::mimeFromContentType($ct);

        if (in_array($mime, ['text/html', 'application/xhtml+xml'], true)) {
            return self::rewriteHtmlDocument($body, $lookup, $publicHost, $options);
        }

        if ($options->jsRewrite && in_array($mime, [
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
        ], true)) {
            return self::rewriteJsQuotedUrls($body, $lookup, $publicHost);
        }

        return $body;
    }

    /**
     * Directory captured at {@code jetty share} startup (see {@see Application::cmdShare}) so APP_URL
     * discovery still works when the edge agent runs under a different working directory.
     */
    private static function shareInvocationDirectoryForAppUrl(): string
    {
        $inv = getenv('JETTY_SHARE_INVOCATION_CWD');
        if (is_string($inv) && trim($inv) !== '') {
            return trim($inv);
        }
        $cw = getcwd();
        if ($cw !== false && $cw !== '') {
            return $cw;
        }

        return '.';
    }

    /**
     * Walk upward from the invocation directory and merge APP_URL hosts from Laravel apps in each
     * directory's immediate children (e.g. share run from {@code …/jetty/jetty-client} discovers
     * {@code …/dply} next to {@code …/jetty}). Cached for the lifetime of the PHP process.
     *
     * @param  callable(string): void  $add
     */
    private static function addWalkUpAdjacentArtisanAppUrlHosts(string $invocationCwd, callable $add): void
    {
        if (getenv('JETTY_SHARE_NO_ADJACENT_LARAVEL_SCAN') === '1') {
            return;
        }
        $pr = getenv('JETTY_SHARE_PROJECT_ROOT');
        $prKey = is_string($pr) ? trim($pr) : '';
        $cacheKey = trim($invocationCwd)."\0".$prKey;
        if (self::$walkUpAdjacentAppUrlHostsCache !== null && self::$walkUpAdjacentAppUrlHostsCacheKey === $cacheKey) {
            foreach (self::$walkUpAdjacentAppUrlHostsCache as $h) {
                $add($h);
            }

            return;
        }
        $seen = [];
        $dir = trim($invocationCwd);
        if ($dir === '') {
            $dir = '.';
        }
        $rp = realpath($dir);
        $dir = $rp !== false ? $rp : $dir;
        for ($i = 0; $i < 64; $i++) {
            if ($dir === '' || ! is_dir($dir)) {
                break;
            }
            foreach (LocalDevDetector::appUrlHostsFromAdjacentArtisanProjects($dir) as $h) {
                $seen[strtolower($h)] = true;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        self::$walkUpAdjacentAppUrlHostsCache = array_keys($seen);
        self::$walkUpAdjacentAppUrlHostsCacheKey = $cacheKey;
        foreach (self::$walkUpAdjacentAppUrlHostsCache as $h) {
            $add($h);
        }
    }

    /**
     * @return array<string, true>
     */
    public static function tunnelRewriteHostLookup(string $localHost): array
    {
        $lookup = [];
        $add = function (string $h) use (&$lookup): void {
            $h = strtolower(trim($h));
            if ($h === '') {
                return;
            }
            $lookup[$h] = true;
            if (! str_starts_with($h, 'www.')) {
                $lookup['www.'.$h] = true;
            }
        };
        $add($localHost);
        $cliUpstream = getenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
        if (is_string($cliUpstream) && trim($cliUpstream) !== '') {
            $add(trim($cliUpstream));
        }
        $extra = getenv('JETTY_SHARE_REWRITE_HOSTS');
        if (is_string($extra) && $extra !== '') {
            foreach (explode(',', $extra) as $part) {
                $add(trim($part));
            }
        }
        $appUrlRoots = [];
        $projectRoot = getenv('JETTY_SHARE_PROJECT_ROOT');
        if (is_string($projectRoot) && trim($projectRoot) !== '') {
            $appUrlRoots[] = trim($projectRoot);
        }
        $invocationCwd = self::shareInvocationDirectoryForAppUrl();
        $appUrlRoots[] = $invocationCwd;
        $seenRoot = [];
        foreach ($appUrlRoots as $root) {
            $root = trim($root);
            if ($root === '') {
                continue;
            }
            $rp = realpath($root);
            $key = $rp !== false ? $rp : $root;
            if (isset($seenRoot[$key])) {
                continue;
            }
            $seenRoot[$key] = true;
            $dirForEnv = $rp !== false ? $rp : $root;
            foreach (LocalDevDetector::appUrlHostsForTunnelRewrite($dirForEnv) as $h) {
                $add($h);
            }
        }
        self::addWalkUpAdjacentArtisanAppUrlHosts($invocationCwd, $add);
        $appUrlEnv = getenv('APP_URL');
        if (is_string($appUrlEnv) && $appUrlEnv !== '') {
            foreach (LocalDevDetector::hostsFromAppUrlRaw($appUrlEnv) as $h) {
                $add($h);
            }
        }

        return $lookup;
    }

    /**
     * If the URL is absolute and points at a known host, rewrite scheme/host to the tunnel.
     *
     * @param  array<string, true>  $rewriteHostsLookup
     * @param  bool  $debugRedirect  When true, log redirect decisions to stderr if JETTY_SHARE_DEBUG_REWRITE=1
     */
    public static function rewriteAbsoluteUrlToTunnel(string $value, array $rewriteHostsLookup, string $tunnelHostLower, bool $debugRedirect = false): ?string
    {
        $value = trim($value);
        if ($value === '' || ($value[0] ?? '') === '/') {
            if ($debugRedirect && self::debugRewriteEnabled()) {
                self::debugRewriteLine('absolute_url: skip empty or root-relative '.self::truncateForLog($value));
            }

            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['host'])) {
            if ($debugRedirect && self::debugRewriteEnabled()) {
                self::debugRewriteLine('absolute_url: skip parse_url missing host '.self::truncateForLog($value));
            }

            return null;
        }

        $hostLower = strtolower((string) $parts['host']);
        if (! isset($rewriteHostsLookup[$hostLower])) {
            if ($debugRedirect && self::debugRewriteEnabled()) {
                self::debugRewriteLine(
                    'absolute_url: host "'.$hostLower.'" not in rewrite lookup —'
                    .' add JETTY_SHARE_REWRITE_HOSTS='.$hostLower.' if this URL should become the current tunnel Host ('.$tunnelHostLower.')'
                );
            }

            return null;
        }

        $path = ($parts['path'] ?? '') === '' ? '/' : (string) $parts['path'];
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#'.$parts['fragment'] : '';

        return 'https://'.$tunnelHostLower.$path.$query.$fragment;
    }

    /**
     * @param  array<string, true>  $lookup
     */
    private static function rewriteHtmlDocument(string $html, array $lookup, string $tunnelHost, TunnelRewriteOptions $options): string
    {
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $loaded) {
            return $html;
        }

        $xpath = new DOMXPath($dom);

        self::rewriteDomAttributes($xpath, $lookup, $tunnelHost);

        foreach ($xpath->query('//meta') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if (strtolower($node->getAttribute('http-equiv')) !== 'refresh') {
                continue;
            }
            $content = $node->getAttribute('content');
            if ($content === '') {
                continue;
            }
            $new = self::rewriteRefreshHeaderValue($content, $lookup, $tunnelHost);
            if ($new !== null) {
                $node->setAttribute('content', $new);
            }
        }

        if ($options->cssRewrite) {
            foreach ($xpath->query('//*[@style]') as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $st = $node->getAttribute('style');
                $new = self::rewriteCssUrlsInText($st, $lookup, $tunnelHost);
                if ($new !== $st) {
                    $node->setAttribute('style', $new);
                }
            }
            foreach ($xpath->query('//style') as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $text = $node->textContent;
                if ($text === null || $text === '') {
                    continue;
                }
                $new = self::rewriteCssUrlsInText($text, $lookup, $tunnelHost);
                if ($new !== $text) {
                    while ($node->firstChild !== null) {
                        $node->removeChild($node->firstChild);
                    }
                    $node->appendChild($dom->createTextNode($new));
                }
            }
        }

        if ($options->jsRewrite) {
            foreach ($xpath->query('//script') as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $type = strtolower($node->getAttribute('type'));
                if ($type !== '' && $type !== 'text/javascript' && $type !== 'application/javascript' && $type !== 'module') {
                    continue;
                }
                if ($type === 'application/json' || str_contains($type, 'json')) {
                    continue;
                }
                if ($node->hasAttribute('src')) {
                    continue;
                }
                $text = $node->textContent;
                if ($text === null || $text === '') {
                    continue;
                }
                $new = self::rewriteJsQuotedUrls($text, $lookup, $tunnelHost);
                if ($new !== $text) {
                    while ($node->firstChild !== null) {
                        $node->removeChild($node->firstChild);
                    }
                    $node->appendChild($dom->createTextNode($new));
                }
            }
        }

        $out = $dom->saveHTML();
        if ($out === false) {
            return $html;
        }

        // Strip XML encoding declaration if libxml added it as text.
        if (str_starts_with($out, '<?xml encoding="UTF-8"?>')) {
            $out = (string) substr($out, strlen('<?xml encoding="UTF-8"?>'));

            return $out;
        }

        return $out;
    }

    /**
     * @param  array<string, true>  $lookup
     */
    private static function rewriteDomAttributes(DOMXPath $xpath, array $lookup, string $tunnelHost): void
    {
        $pairs = [
            '//a[@href]' => 'href',
            '//area[@href]' => 'href',
            '//link[@href]' => 'href',
            '//img[@src]' => 'src',
            '//script[@src]' => 'src',
            '//iframe[@src]' => 'src',
            '//embed[@src]' => 'src',
            '//audio[@src]' => 'src',
            '//video[@src]' => 'src',
            '//source[@src]' => 'src',
            '//track[@src]' => 'src',
            '//form[@action]' => 'action',
            '//button[@formaction]' => 'formaction',
            '//object[@data]' => 'data',
            '//video[@poster]' => 'poster',
            '//blockquote[@cite]' => 'cite',
        ];

        foreach ($pairs as $query => $attr) {
            foreach ($xpath->query($query) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $val = $node->getAttribute($attr);
                $new = self::rewriteAbsoluteUrlToTunnel($val, $lookup, $tunnelHost);
                if ($new !== null) {
                    $node->setAttribute($attr, $new);
                }
            }
        }

        foreach (self::DATA_URL_ATTRS as $attr) {
            $q = '//*[@'.$attr.']';
            foreach ($xpath->query($q) as $node) {
                if (! $node instanceof DOMElement) {
                    continue;
                }
                $val = $node->getAttribute($attr);
                $new = self::rewriteAbsoluteUrlToTunnel($val, $lookup, $tunnelHost);
                if ($new !== null) {
                    $node->setAttribute($attr, $new);
                }
            }
        }

        foreach ($xpath->query('//img[@srcset] | //source[@srcset]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $srcset = $node->getAttribute('srcset');
            $new = self::rewriteSrcset($srcset, $lookup, $tunnelHost);
            if ($new !== null) {
                $node->setAttribute('srcset', $new);
            }
        }
    }

    /**
     * @param  array<string, true>  $lookup
     */
    private static function rewriteSrcset(string $srcset, array $lookup, string $tunnelHost): ?string
    {
        $srcset = trim($srcset);
        if ($srcset === '') {
            return null;
        }
        $parts = preg_split('/\s*,\s*/', $srcset) ?: [];
        $out = [];
        $changed = false;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($part), 2);
            $url = $tokens[0] ?? '';
            $desc = $tokens[1] ?? '';
            $newUrl = self::rewriteAbsoluteUrlToTunnel($url, $lookup, $tunnelHost) ?? $url;
            if ($newUrl !== $url) {
                $changed = true;
            }
            $out[] = $desc !== '' ? $newUrl.' '.$desc : $newUrl;
        }

        return $changed ? implode(', ', $out) : null;
    }

    /**
     * @param  array<string, true>  $lookup
     */
    private static function rewriteCssUrlsInText(string $css, array $lookup, string $tunnelHost): string
    {
        return (string) preg_replace_callback(
            '/url\s*\(\s*(?:([\'"])(https?:\/\/[^\'"]+)\1|(https?:\/\/[^)]+))\s*\)/i',
            function (array $m) use ($lookup, $tunnelHost): string {
                $url = ($m[2] ?? '') !== '' ? $m[2] : (string) ($m[3] ?? '');
                $rewritten = self::rewriteAbsoluteUrlToTunnel($url, $lookup, $tunnelHost);
                if ($rewritten === null) {
                    return $m[0];
                }
                if ($m[1] !== '') {
                    return 'url('.$m[1].$rewritten.$m[1].')';
                }

                return 'url('.$rewritten.')';
            },
            $css
        );
    }

    /**
     * @param  array<string, true>  $lookup
     */
    private static function rewriteJsQuotedUrls(string $js, array $lookup, string $tunnelHost): string
    {
        return (string) preg_replace_callback(
            '/(["\'])(https?:\/\/[^"\']+)\1/',
            function (array $m) use ($lookup, $tunnelHost): string {
                $quote = $m[1];
                $url = $m[2];
                $rewritten = self::rewriteAbsoluteUrlToTunnel($url, $lookup, $tunnelHost);

                return $rewritten !== null ? $quote.$rewritten.$quote : $m[0];
            },
            $js
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function headerLine(array $headers, string $name): string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                return (string) $v;
            }
        }

        return '';
    }

    private static function mimeFromContentType(string $ct): string
    {
        $ct = trim($ct);
        if ($ct === '') {
            return '';
        }
        $parts = explode(';', $ct);

        return strtolower(trim($parts[0]));
    }

    /**
     * @param  array<string, string>  $requestHeaders
     */
    /**
     * When the edge omits the Host header on http_request frames, redirect/body rewrite cannot infer the public
     * tunnel hostname. The CLI passes the host from Bridge public_url as $fallbackTunnelHost.
     *
     * @param  array<string, string>  $requestHeaders
     * @return array<string, string>
     */
    public static function requestHeadersWithRewriteTunnelHostFallback(array $requestHeaders, ?string $fallbackTunnelHost): array
    {
        if ($fallbackTunnelHost === null || $fallbackTunnelHost === '') {
            return $requestHeaders;
        }
        if (self::requestHostLower($requestHeaders) !== '') {
            return $requestHeaders;
        }
        if (self::debugRewriteEnabled()) {
            self::debugRewriteLine('request Host missing on edge frame; using public tunnel host from Bridge: '.$fallbackTunnelHost);
        }
        $out = $requestHeaders;
        $out['Host'] = $fallbackTunnelHost;

        return $out;
    }

    private static function requestHostLower(array $requestHeaders): string
    {
        foreach ($requestHeaders as $k => $v) {
            if (strcasecmp((string) $k, 'Host') === 0) {
                $host = strtolower(trim((string) $v));
                if (str_contains($host, ':')) {
                    $bracket = explode(':', $host, 2);

                    return $bracket[0];
                }

                return $host;
            }
        }

        return '';
    }

    private static function debugRewriteEnabled(): bool
    {
        $raw = self::envRawFirstNonEmpty([
            'JETTY_SHARE_DEBUG_REWRITE',
        ]);
        if ($raw === null) {
            return false;
        }

        return self::envTruthyString($raw);
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

    private static function envTruthyString(string $raw): bool
    {
        $v = strtolower(trim($raw));

        return $v === '1' || $v === 'true' || $v === 'yes' || $v === 'on';
    }

    private static function debugRewriteLine(string $line): void
    {
        $msg = '[jetty share rewrite] '.$line."\n";
        if (\defined('STDERR') && \is_resource(STDERR)) {
            @fwrite(STDERR, $msg);
        } else {
            error_log(rtrim($msg));
        }
    }

    private static function truncateForLog(string $s, int $max = 240): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }

        return substr($s, 0, $max).'…';
    }

    private static function debugRewriteLookupPreview(string $localHost): string
    {
        $lookup = self::tunnelRewriteHostLookup($localHost);
        $hosts = array_keys($lookup);
        sort($hosts);
        $n = count($hosts);
        $preview = array_slice($hosts, 0, 24);
        $tail = $n > count($preview) ? ' … (+'.($n - count($preview)).' more)' : '';

        return $n.' host(s): '.implode(', ', $preview).$tail;
    }

    private static function debugNdjsonLogPath(): ?string
    {
        $raw = getenv('JETTY_SHARE_DEBUG_NDJSON_FILE');
        if (! is_string($raw)) {
            return null;
        }
        $path = trim($raw);

        return $path !== '' ? $path : null;
    }

    /**
     * Append one NDJSON line when {@code JETTY_SHARE_DEBUG_NDJSON_FILE} is set (absolute path recommended).
     * Each line: {@code event}, {@code ts_ms}, {@code rewrite_debug_rev}, {@code data}.
     *
     * @param  array<string, mixed>  $data
     */
    public static function emitDebugNdjson(string $event, array $data): void
    {
        $path = self::debugNdjsonLogPath();
        if ($path === null) {
            return;
        }
        $line = json_encode([
            'ts_ms' => (int) round(microtime(true) * 1000),
            'event' => $event,
            'rewrite_debug_rev' => self::REWRITE_DEBUG_REV,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false) {
            return;
        }
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function agentDebugNdjson(string $hypothesisId, string $location, array $data): void
    {
        $cli = getenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
        self::emitDebugNdjson('rewrite.'.$hypothesisId, array_merge($data, [
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'cli_upstream_hostname' => is_string($cli) && trim($cli) !== '' ? trim($cli) : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function debugSessionNdjson(string $hypothesisId, string $location, array $data): void
    {
        $cli = getenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');
        self::emitDebugNdjson('session.'.$hypothesisId, array_merge($data, [
            'hypothesisId' => $hypothesisId,
            'location' => $location,
            'cli_upstream_hostname' => is_string($cli) && trim($cli) !== '' ? trim($cli) : null,
        ]));
    }

    /**
     * @internal PHPUnit / deterministic tests only
     */
    public static function resetWalkUpAdjacentAppUrlCacheForTesting(): void
    {
        self::$walkUpAdjacentAppUrlHostsCache = null;
        self::$walkUpAdjacentAppUrlHostsCacheKey = '';
    }
}
