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
    /** @var list<string> */
    private const DATA_URL_ATTRS = ['data-url', 'data-href', 'data-src', 'data-action'];

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
            return $responseHeaders;
        }

        $publicHost = self::requestHostLower($requestHeaders);
        if ($publicHost === '') {
            return $responseHeaders;
        }

        foreach (['Location', 'X-Inertia-Location', 'Refresh'] as $headerName) {
            foreach ($responseHeaders as $name => $value) {
                if (strcasecmp($name, $headerName) !== 0) {
                    continue;
                }
                if (strcasecmp($headerName, 'Refresh') === 0) {
                    $rewritten = self::rewriteRefreshHeaderValue((string) $value, $rewriteHostsLookup, $publicHost);
                } else {
                    $rewritten = self::rewriteAbsoluteUrlToTunnel((string) $value, $rewriteHostsLookup, $publicHost);
                }
                if ($rewritten !== null) {
                    $responseHeaders[$name] = $rewritten;
                }
            }
        }

        return $responseHeaders;
    }

    /**
     * @param  array<string, true>  $rewriteHostsLookup
     */
    public static function rewriteRefreshHeaderValue(string $value, array $rewriteHostsLookup, string $tunnelHostLower): ?string
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
        $rewritten = self::rewriteAbsoluteUrlToTunnel($urlPart, $rewriteHostsLookup, $tunnelHostLower);
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
        $extra = getenv('JETTY_SHARE_REWRITE_HOSTS');
        if (is_string($extra) && $extra !== '') {
            foreach (explode(',', $extra) as $part) {
                $add(trim($part));
            }
        }
        foreach (LocalDevDetector::appUrlHostsForTunnelRewrite() as $h) {
            $add($h);
        }
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
     */
    public static function rewriteAbsoluteUrlToTunnel(string $value, array $rewriteHostsLookup, string $tunnelHostLower): ?string
    {
        $value = trim($value);
        if ($value === '' || ($value[0] ?? '') === '/') {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        if (! isset($rewriteHostsLookup[strtolower((string) $parts['host'])])) {
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
}
