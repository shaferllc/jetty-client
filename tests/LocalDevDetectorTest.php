<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\LocalDevDetector;
use PHPUnit\Framework\TestCase;

final class LocalDevDetectorTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            // Recursively clean up temp files.
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function makeTmpDir(): string
    {
        $dir = sys_get_temp_dir().'/jetty-localdev-test-'.getmypid().'-'.mt_rand();
        mkdir($dir, 0755, true);
        $this->tmpDir = $dir;

        return $dir;
    }

    // ── hostsFromAppUrlRaw() ──

    public function test_hosts_from_app_url_raw_extracts_host(): void
    {
        $hosts = LocalDevDetector::hostsFromAppUrlRaw('http://mysite.test');
        $this->assertContains('mysite.test', $hosts);
    }

    public function test_hosts_from_app_url_raw_adds_www_variant(): void
    {
        $hosts = LocalDevDetector::hostsFromAppUrlRaw('http://mysite.test');
        $this->assertContains('www.mysite.test', $hosts);
    }

    public function test_hosts_from_app_url_raw_no_duplicate_www(): void
    {
        $hosts = LocalDevDetector::hostsFromAppUrlRaw('http://www.mysite.test');
        $this->assertContains('www.mysite.test', $hosts);
        // Should not have www.www.mysite.test.
        $this->assertCount(1, $hosts);
    }

    public function test_hosts_from_app_url_raw_empty_string(): void
    {
        $this->assertSame([], LocalDevDetector::hostsFromAppUrlRaw(''));
    }

    public function test_hosts_from_app_url_raw_without_scheme(): void
    {
        $hosts = LocalDevDetector::hostsFromAppUrlRaw('mysite.test');
        $this->assertContains('mysite.test', $hosts);
    }

    public function test_hosts_from_app_url_raw_https(): void
    {
        $hosts = LocalDevDetector::hostsFromAppUrlRaw('https://secure.test:8443/path');
        $this->assertContains('secure.test', $hosts);
        $this->assertContains('www.secure.test', $hosts);
    }

    public function test_hosts_from_app_url_raw_lowercases(): void
    {
        $hosts = LocalDevDetector::hostsFromAppUrlRaw('http://MySite.Test');
        $this->assertContains('mysite.test', $hosts);
    }

    // ── appUrlHostsForTunnelRewrite() with temp .env ──

    public function test_app_url_hosts_for_tunnel_rewrite_reads_dot_env(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir.'/.env', "APP_NAME=myapp\nAPP_URL=http://mysite.test\nDB_HOST=127.0.0.1\n");
        $hosts = LocalDevDetector::appUrlHostsForTunnelRewrite($dir);
        $this->assertContains('mysite.test', $hosts);
        $this->assertContains('www.mysite.test', $hosts);
    }

    public function test_app_url_hosts_for_tunnel_rewrite_empty_when_no_env(): void
    {
        $dir = $this->makeTmpDir();
        $hosts = LocalDevDetector::appUrlHostsForTunnelRewrite($dir);
        $this->assertSame([], $hosts);
    }

    public function test_app_url_hosts_for_tunnel_rewrite_handles_quoted_url(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir.'/.env', "APP_URL=\"http://quoted.test\"\n");
        $hosts = LocalDevDetector::appUrlHostsForTunnelRewrite($dir);
        $this->assertContains('quoted.test', $hosts);
    }

    public function test_app_url_hosts_for_tunnel_rewrite_handles_inline_comment(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir.'/.env', "APP_URL=http://commented.test # my comment\n");
        $hosts = LocalDevDetector::appUrlHostsForTunnelRewrite($dir);
        $this->assertContains('commented.test', $hosts);
    }

    public function test_app_url_hosts_for_tunnel_rewrite_no_app_url_key(): void
    {
        $dir = $this->makeTmpDir();
        file_put_contents($dir.'/.env', "DB_HOST=127.0.0.1\n");
        $hosts = LocalDevDetector::appUrlHostsForTunnelRewrite($dir);
        $this->assertSame([], $hosts);
    }
}
