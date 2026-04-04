<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\TunnelLock;
use PHPUnit\Framework\TestCase;

final class TunnelLockTest extends TestCase
{
    private string $originalHome;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/jetty-lock-test-'.getmypid().'-'.mt_rand();
        @mkdir($this->tempDir, 0o755, true);

        $home = getenv('HOME');
        $this->originalHome = is_string($home) ? $home : '';

        putenv('HOME='.$this->tempDir);
    }

    protected function tearDown(): void
    {
        putenv('HOME='.$this->originalHome);

        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function lockDir(): string
    {
        return $this->tempDir.'/.cache/jetty/locks';
    }

    // -- acquire() --

    public function test_acquire_creates_lock_file(): void
    {
        $lock = new TunnelLock('100');
        $result = $lock->acquire();

        $this->assertTrue($result['acquired']);
        $this->assertFileExists($this->lockDir().'/tunnel-100.lock');

        $lock->release();
    }

    public function test_acquire_writes_pid_to_lock_file(): void
    {
        $lock = new TunnelLock('101');
        $lock->acquire();

        $content = file_get_contents($this->lockDir().'/tunnel-101.lock');
        $data = json_decode($content, true);

        $this->assertSame(getmypid(), $data['pid']);
        $this->assertSame('101', $data['tunnel_id']);

        $lock->release();
    }

    // -- check() --

    public function test_check_returns_not_locked_when_no_lock_file(): void
    {
        $lock = new TunnelLock('200');
        $result = $lock->check();

        $this->assertFalse($result['locked']);
        $this->assertNull($result['pid']);
        $this->assertFalse($result['stale']);
    }

    public function test_check_detects_stale_lock_with_dead_pid(): void
    {
        @mkdir($this->lockDir(), 0o755, true);
        $lockPath = $this->lockDir().'/tunnel-201.lock';

        // Write a lock file with a PID that almost certainly doesn't exist
        $stalePid = 99999;
        // Make sure this PID doesn't exist on the system
        while (posix_kill($stalePid, 0)) {
            $stalePid++;
        }

        file_put_contents($lockPath, json_encode([
            'pid' => $stalePid,
            'tunnel_id' => 201,
            'started' => '2025-01-01 00:00:00',
            'host' => 'test',
        ]));

        $lock = new TunnelLock('201');
        $result = $lock->check();

        $this->assertFalse($result['locked']);
        $this->assertSame($stalePid, $result['pid']);
        $this->assertTrue($result['stale']);
    }

    public function test_check_detects_own_pid_as_not_locked(): void
    {
        @mkdir($this->lockDir(), 0o755, true);
        $lockPath = $this->lockDir().'/tunnel-202.lock';

        file_put_contents($lockPath, json_encode([
            'pid' => getmypid(),
            'tunnel_id' => 202,
            'started' => '2025-01-01 00:00:00',
            'host' => 'test',
        ]));

        $lock = new TunnelLock('202');
        $result = $lock->check();

        $this->assertFalse($result['locked']);
        $this->assertSame(getmypid(), $result['pid']);
        $this->assertFalse($result['stale']);
    }

    // -- release() --

    public function test_release_removes_lock_file(): void
    {
        $lock = new TunnelLock('300');
        $lock->acquire();
        $lockPath = $this->lockDir().'/tunnel-300.lock';

        $this->assertFileExists($lockPath);

        $lock->release();

        $this->assertFileDoesNotExist($lockPath);
    }

    public function test_release_is_safe_when_not_acquired(): void
    {
        $lock = new TunnelLock('301');
        // Should not throw
        $lock->release();
        $this->assertTrue(true);
    }

    // -- cleanupStaleLocks() --

    public function test_cleanup_stale_locks_removes_dead_pid_entries(): void
    {
        @mkdir($this->lockDir(), 0o755, true);

        // Find a PID that doesn't exist
        $stalePid = 99998;
        while (posix_kill($stalePid, 0)) {
            $stalePid++;
        }

        file_put_contents($this->lockDir().'/tunnel-400.lock', json_encode([
            'pid' => $stalePid,
            'tunnel_id' => 400,
            'started' => '2025-01-01 00:00:00',
            'host' => 'test',
        ]));

        $removed = TunnelLock::cleanupStaleLocks();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($this->lockDir().'/tunnel-400.lock');
    }

    public function test_cleanup_stale_locks_keeps_live_pid_entries(): void
    {
        @mkdir($this->lockDir(), 0o755, true);

        file_put_contents($this->lockDir().'/tunnel-401.lock', json_encode([
            'pid' => getmypid(),
            'tunnel_id' => 401,
            'started' => '2025-01-01 00:00:00',
            'host' => 'test',
        ]));

        $removed = TunnelLock::cleanupStaleLocks();

        $this->assertSame(0, $removed);
        $this->assertFileExists($this->lockDir().'/tunnel-401.lock');
    }

    public function test_cleanup_stale_locks_removes_invalid_json(): void
    {
        @mkdir($this->lockDir(), 0o755, true);

        file_put_contents($this->lockDir().'/tunnel-402.lock', 'not valid json');

        $removed = TunnelLock::cleanupStaleLocks();

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($this->lockDir().'/tunnel-402.lock');
    }

    public function test_cleanup_stale_locks_returns_zero_when_no_locks(): void
    {
        $removed = TunnelLock::cleanupStaleLocks();

        $this->assertSame(0, $removed);
    }
}
