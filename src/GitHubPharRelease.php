<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Finds the latest cli-v* / cli-auto-* GitHub release that ships a Jetty PHAR (jetty.phar / jetty-php.phar; same rules as the Jetty app dashboard).
 */
final class GitHubPharRelease
{
    /**
     * @return array{tag_name: string, browser_download_url: string, html_url: string}|null
     */
    public static function latest(string $ownerRepo, ?string $githubToken = null): ?array
    {
        $ownerRepo = trim($ownerRepo);
        if ($ownerRepo === '' || ! preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $ownerRepo)) {
            return null;
        }

        $url = 'https://api.github.com/repos/'.$ownerRepo.'/releases?per_page=100';
        $json = self::httpGet($url, $githubToken);
        if ($json === null) {
            return null;
        }

        try {
            $releases = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($releases)) {
            return null;
        }

        /** @var list<array<string, mixed>> $releases */
        $release = self::selectPreferred($releases);
        if (! is_array($release)) {
            return null;
        }

        $tagName = (string) ($release['tag_name'] ?? '');
        $htmlUrl = (string) ($release['html_url'] ?? '');
        /** @var list<array<string, mixed>> $assets */
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : [];
        $browserUrl = self::matchPharAsset($assets);
        if ($tagName === '' || $browserUrl === null) {
            return null;
        }

        return [
            'tag_name' => $tagName,
            'browser_download_url' => $browserUrl,
            'html_url' => $htmlUrl,
        ];
    }

    private static function httpGet(string $url, ?string $token): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: jetty/'.ApiClient::VERSION,
        ];
        if (is_string($token) && $token !== '') {
            $headers[] = 'Authorization: Bearer '.$token;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }

        return (string) $body;
    }

    /**
     * @param  list<array<string, mixed>>  $releases
     * @return ?array<string, mixed>
     */
    private static function selectPreferred(array $releases): ?array
    {
        $candidates = [];
        foreach ($releases as $r) {
            if (! is_array($r) || ! empty($r['draft'])) {
                continue;
            }
            $tag = isset($r['tag_name']) ? (string) $r['tag_name'] : '';
            if ($tag === '' || preg_match('/^cli-v|^cli-auto-/i', $tag) !== 1) {
                continue;
            }
            $candidates[] = $r;
        }

        if ($candidates === []) {
            return null;
        }

        $withPhar = array_values(array_filter($candidates, static function (array $r): bool {
            /** @var list<array<string, mixed>> $assets */
            $assets = isset($r['assets']) && is_array($r['assets']) ? $r['assets'] : [];

            return self::matchPharAsset($assets) !== null;
        }));

        $pool = $withPhar !== [] ? $withPhar : $candidates;

        usort($pool, static function (array $a, array $b): int {
            /** @var list<array<string, mixed>> $aa */
            $aa = isset($a['assets']) && is_array($a['assets']) ? $a['assets'] : [];
            /** @var list<array<string, mixed>> $bb */
            $bb = isset($b['assets']) && is_array($b['assets']) ? $b['assets'] : [];
            $pharA = self::matchPharAsset($aa) !== null ? 0 : 1;
            $pharB = self::matchPharAsset($bb) !== null ? 0 : 1;
            if ($pharA !== $pharB) {
                return $pharA <=> $pharB;
            }
            $tagA = isset($a['tag_name']) ? (string) $a['tag_name'] : '';
            $tagB = isset($b['tag_name']) ? (string) $b['tag_name'] : '';
            $cliVA = ($tagA !== '' && preg_match('/^cli-v/i', $tagA) === 1) ? 0 : 1;
            $cliVB = ($tagB !== '' && preg_match('/^cli-v/i', $tagB) === 1) ? 0 : 1;
            if ($cliVA !== $cliVB) {
                return $cliVA <=> $cliVB;
            }
            $preA = ! empty($a['prerelease']) ? 1 : 0;
            $preB = ! empty($b['prerelease']) ? 1 : 0;
            if ($preA !== $preB) {
                return $preA <=> $preB;
            }
            // Compare by semver (highest version first) before falling back to publish date.
            $semA = self::tagToSemver($tagA);
            $semB = self::tagToSemver($tagB);
            $semCmp = version_compare($semB, $semA);
            if ($semCmp !== 0) {
                return $semCmp;
            }
            $ta = strtotime((string) ($a['published_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['published_at'] ?? '')) ?: 0;

            return $tb <=> $ta;
        });

        return $pool[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     */
    private static function matchPharAsset(array $assets): ?string
    {
        foreach (['jetty.phar', 'jetty-php.phar'] as $prefer) {
            foreach ($assets as $asset) {
                $name = isset($asset['name']) ? strtolower((string) $asset['name']) : '';
                if ($name === $prefer) {
                    $u = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                    return $u !== '' ? $u : null;
                }
            }
        }
        foreach ($assets as $asset) {
            $name = isset($asset['name']) ? strtolower((string) $asset['name']) : '';
            if ($name === '' || ! str_ends_with($name, '.phar')) {
                continue;
            }
            if (str_contains($name, 'jetty')) {
                $u = isset($asset['browser_download_url']) ? (string) $asset['browser_download_url'] : '';

                return $u !== '' ? $u : null;
            }
        }

        return null;
    }

    /**
     * Strip cli-v / cli-auto- prefix for semver compare.
     */
    public static function tagToSemver(string $tag): string
    {
        $t = preg_replace('/^cli-v/i', '', $tag) ?? $tag;
        $t = preg_replace('/^cli-auto-/i', '', $t) ?? $t;

        return $t;
    }

    public static function downloadFile(string $url, string $destinationPath, ?string $githubToken = null): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $out = fopen($destinationPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot open '.$destinationPath.' for writing');
        }

        $headers = [
            'Accept: application/octet-stream',
            'User-Agent: jetty/'.ApiClient::VERSION,
        ];
        if (is_string($githubToken) && $githubToken !== '') {
            $headers[] = 'Authorization: Bearer '.$githubToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        fclose($out);

        if ($ok === false || $code < 200 || $code >= 300) {
            @unlink($destinationPath);
            throw new \RuntimeException('Download failed (HTTP '.$code.')');
        }
    }
}
