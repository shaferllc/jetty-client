<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Tracks request categories and manages the active view filter for `jetty share` traffic output.
 *
 * Views: all | pages | assets | api | errors
 * Keys:  a=all  p=pages  s=static/assets  e=errors  j=api/json
 */
final class ShareTrafficView
{
    private const ASSET_EXTENSIONS = [
        'css', 'js', 'mjs', 'map',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'ico', 'webp', 'avif', 'bmp',
        'woff', 'woff2', 'ttf', 'eot', 'otf',
        'mp4', 'webm', 'ogg', 'mp3', 'wav',
        'pdf', 'zip', 'gz', 'tar', 'br',
    ];

    private string $view = 'all';

    private int $totalRequests = 0;

    private int $pages = 0;

    private int $assets = 0;

    private int $api = 0;

    private int $errors = 0;

    private int $redirects = 0;

    public function categorize(string $path, int $status): string
    {
        // Errors always flagged regardless of path
        $isError = $status >= 400;

        // Detect category from path
        $ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

        if (in_array($ext, self::ASSET_EXTENSIONS, true)
            || str_starts_with($path, '/build/')
            || str_starts_with($path, '/assets/')
            || str_starts_with($path, '/vendor/')
            || str_starts_with($path, '/dist/')
            || str_starts_with($path, '/static/')
            || str_starts_with($path, '/fonts/')
            || str_starts_with($path, '/_next/static/')
            || str_starts_with($path, '/_nuxt/')
        ) {
            return $isError ? 'error' : 'asset';
        }

        if (str_starts_with($path, '/api/')
            || str_starts_with($path, '/graphql')
            || str_starts_with($path, '/webhook')
            || str_starts_with($path, '/_debugbar/')
        ) {
            return $isError ? 'error' : 'api';
        }

        if ($status >= 300 && $status < 400) {
            return 'redirect';
        }

        if ($isError) {
            return 'error';
        }

        return 'page';
    }

    public function record(string $category): void
    {
        $this->totalRequests++;
        match ($category) {
            'page' => $this->pages++,
            'asset' => $this->assets++,
            'api' => $this->api++,
            'error' => $this->errors++,
            'redirect' => $this->redirects++,
            default => null,
        };
    }

    public function shouldShow(string $category): bool
    {
        return match ($this->view) {
            'all' => true,
            'pages' => $category === 'page' || $category === 'redirect',
            'assets' => $category === 'asset',
            'api' => $category === 'api',
            'errors' => $category === 'error',
            default => true,
        };
    }

    /**
     * Process a keystroke and return true if the view changed.
     */
    public function handleKey(string $key): bool
    {
        $newView = match (strtolower($key)) {
            'a' => 'all',
            'p' => 'pages',
            's' => 'assets',
            'e' => 'errors',
            'j' => 'api',
            default => null,
        };

        if ($newView !== null && $newView !== $this->view) {
            $this->view = $newView;

            return true;
        }

        return false;
    }

    public function currentView(): string
    {
        return $this->view;
    }

    public function statusLine(CliUi $u): string
    {
        $parts = [
            $this->label($u, 'all', 'All', (string) $this->totalRequests, 'a'),
            $this->label($u, 'pages', 'Pages', (string) $this->pages, 'p'),
            $this->label($u, 'assets', 'Assets', (string) $this->assets, 's'),
            $this->label($u, 'api', 'API', (string) $this->api, 'j'),
            $this->label($u, 'errors', 'Errors', (string) $this->errors, 'e'),
        ];

        return '  '.implode('    ', $parts);
    }

    private function label(CliUi $u, string $view, string $name, string $count, string $key): string
    {
        $text = $name.' '.$count;
        if ($this->view === $view) {
            return $u->bold($u->cyan('['.$key.'] '.$text));
        }

        return $u->dim('['.$key.'] '.$text);
    }

    public function categoryTag(string $category): string
    {
        return match ($category) {
            'asset' => '·',
            'api' => '→',
            'error' => '✖',
            'redirect' => '↪',
            'page' => '●',
            default => ' ',
        };
    }
}
