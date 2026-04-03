<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\ShareTrafficView;
use PHPUnit\Framework\TestCase;

final class ShareTrafficViewTest extends TestCase
{
    private ShareTrafficView $view;

    protected function setUp(): void
    {
        $this->view = new ShareTrafficView;
    }

    // --- categorize() ---

    public function test_categorize_page_path_returns_page(): void
    {
        $this->assertSame('page', $this->view->categorize('/dashboard', 200));
        $this->assertSame('page', $this->view->categorize('/', 200));
        $this->assertSame('page', $this->view->categorize('/users/42', 200));
    }

    public function test_categorize_asset_by_extension(): void
    {
        $this->assertSame('asset', $this->view->categorize('/style.css', 200));
        $this->assertSame('asset', $this->view->categorize('/app.js', 200));
        $this->assertSame('asset', $this->view->categorize('/logo.png', 200));
        $this->assertSame('asset', $this->view->categorize('/image.jpg', 200));
        $this->assertSame('asset', $this->view->categorize('/icon.svg', 200));
        $this->assertSame('asset', $this->view->categorize('/font.woff2', 200));
    }

    public function test_categorize_asset_by_path_prefix(): void
    {
        $this->assertSame('asset', $this->view->categorize('/build/app.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/assets/main.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/vendor/lib.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/dist/bundle.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/static/file.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/fonts/roboto.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/_next/static/chunk.whatever', 200));
        $this->assertSame('asset', $this->view->categorize('/_nuxt/entry.whatever', 200));
    }

    public function test_categorize_api_paths(): void
    {
        $this->assertSame('api', $this->view->categorize('/api/users', 200));
        $this->assertSame('api', $this->view->categorize('/graphql', 200));
        $this->assertSame('api', $this->view->categorize('/webhook', 200));
        $this->assertSame('api', $this->view->categorize('/_debugbar/open', 200));
    }

    public function test_categorize_error_for_4xx_status(): void
    {
        $this->assertSame('error', $this->view->categorize('/page', 404));
        $this->assertSame('error', $this->view->categorize('/page', 422));
        $this->assertSame('error', $this->view->categorize('/page', 403));
    }

    public function test_categorize_error_for_5xx_status(): void
    {
        $this->assertSame('error', $this->view->categorize('/page', 500));
        $this->assertSame('error', $this->view->categorize('/page', 502));
        $this->assertSame('error', $this->view->categorize('/page', 503));
    }

    public function test_categorize_error_overrides_asset_path(): void
    {
        $this->assertSame('error', $this->view->categorize('/style.css', 404));
        $this->assertSame('error', $this->view->categorize('/build/app.js', 500));
    }

    public function test_categorize_error_overrides_api_path(): void
    {
        $this->assertSame('error', $this->view->categorize('/api/users', 500));
        $this->assertSame('error', $this->view->categorize('/graphql', 422));
    }

    public function test_categorize_redirect_for_3xx_status(): void
    {
        $this->assertSame('redirect', $this->view->categorize('/old-page', 301));
        $this->assertSame('redirect', $this->view->categorize('/login', 302));
        $this->assertSame('redirect', $this->view->categorize('/temp', 307));
    }

    // --- record() and counts ---

    public function test_record_increments_total_requests(): void
    {
        $this->view->record('page');
        $this->view->record('asset');
        $this->view->record('api');

        // statusLine contains the total count; verify via reflection
        $r = new \ReflectionProperty($this->view, 'totalRequests');
        $this->assertSame(3, $r->getValue($this->view));
    }

    public function test_record_increments_category_counts(): void
    {
        $this->view->record('page');
        $this->view->record('page');
        $this->view->record('asset');
        $this->view->record('api');
        $this->view->record('error');
        $this->view->record('error');
        $this->view->record('redirect');

        $r = fn (string $prop) => (new \ReflectionProperty($this->view, $prop))->getValue($this->view);

        $this->assertSame(7, $r('totalRequests'));
        $this->assertSame(2, $r('pages'));
        $this->assertSame(1, $r('assets'));
        $this->assertSame(1, $r('api'));
        $this->assertSame(2, $r('errors'));
        $this->assertSame(1, $r('redirects'));
    }

    // --- shouldShow() ---

    public function test_should_show_all_shows_everything(): void
    {
        // Default view is 'all'
        $this->assertTrue($this->view->shouldShow('page'));
        $this->assertTrue($this->view->shouldShow('asset'));
        $this->assertTrue($this->view->shouldShow('api'));
        $this->assertTrue($this->view->shouldShow('error'));
        $this->assertTrue($this->view->shouldShow('redirect'));
    }

    public function test_should_show_pages_filter(): void
    {
        $this->view->handleKey('p');
        $this->assertTrue($this->view->shouldShow('page'));
        $this->assertTrue($this->view->shouldShow('redirect'));
        $this->assertFalse($this->view->shouldShow('asset'));
        $this->assertFalse($this->view->shouldShow('api'));
        $this->assertFalse($this->view->shouldShow('error'));
    }

    public function test_should_show_assets_filter(): void
    {
        $this->view->handleKey('s');
        $this->assertTrue($this->view->shouldShow('asset'));
        $this->assertFalse($this->view->shouldShow('page'));
        $this->assertFalse($this->view->shouldShow('api'));
        $this->assertFalse($this->view->shouldShow('error'));
        $this->assertFalse($this->view->shouldShow('redirect'));
    }

    public function test_should_show_errors_filter(): void
    {
        $this->view->handleKey('e');
        $this->assertTrue($this->view->shouldShow('error'));
        $this->assertFalse($this->view->shouldShow('page'));
        $this->assertFalse($this->view->shouldShow('asset'));
        $this->assertFalse($this->view->shouldShow('api'));
        $this->assertFalse($this->view->shouldShow('redirect'));
    }

    public function test_should_show_api_filter(): void
    {
        $this->view->handleKey('j');
        $this->assertTrue($this->view->shouldShow('api'));
        $this->assertFalse($this->view->shouldShow('page'));
        $this->assertFalse($this->view->shouldShow('asset'));
        $this->assertFalse($this->view->shouldShow('error'));
        $this->assertFalse($this->view->shouldShow('redirect'));
    }

    // --- handleKey() ---

    public function test_handle_key_returns_true_when_view_changes(): void
    {
        $this->assertTrue($this->view->handleKey('p'));
        $this->assertSame('pages', $this->view->currentView());
    }

    public function test_handle_key_returns_false_when_same_view(): void
    {
        $this->view->handleKey('p');
        $this->assertFalse($this->view->handleKey('p'));
    }

    public function test_handle_key_returns_false_for_unknown_key(): void
    {
        $this->assertFalse($this->view->handleKey('x'));
        $this->assertFalse($this->view->handleKey('z'));
    }

    public function test_handle_key_all_valid_keys(): void
    {
        $this->assertTrue($this->view->handleKey('p'));
        $this->assertSame('pages', $this->view->currentView());

        $this->assertTrue($this->view->handleKey('s'));
        $this->assertSame('assets', $this->view->currentView());

        $this->assertTrue($this->view->handleKey('e'));
        $this->assertSame('errors', $this->view->currentView());

        $this->assertTrue($this->view->handleKey('j'));
        $this->assertSame('api', $this->view->currentView());

        $this->assertTrue($this->view->handleKey('a'));
        $this->assertSame('all', $this->view->currentView());
    }

    public function test_handle_key_is_case_insensitive(): void
    {
        $this->assertTrue($this->view->handleKey('P'));
        $this->assertSame('pages', $this->view->currentView());
    }

    // --- categoryTag() ---

    public function test_category_tag_returns_correct_icons(): void
    {
        $this->assertSame('·', $this->view->categoryTag('asset'));
        $this->assertSame('→', $this->view->categoryTag('api'));
        $this->assertSame('✖', $this->view->categoryTag('error'));
        $this->assertSame('↪', $this->view->categoryTag('redirect'));
        $this->assertSame('●', $this->view->categoryTag('page'));
        $this->assertSame(' ', $this->view->categoryTag('unknown'));
    }
}
