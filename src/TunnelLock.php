<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Prevents multiple `jetty share` processes from running for the same tunnel.
 *
 * When two or more CLI processes connect to the same tunnel, they compete for the
 * edge session. Each connection replaces the previous one in the edge's session map,
 * but if a "losing" process's WebSocket goes stale, the edge may end up with no
 * valid agent registered — causing HTTP requests to redirect to "tunnel unavailable"
 * even though a CLI thinks it's connected.
 *
 * This class creates a lockfile per tunnel ID in ~/.cache/jetty/locks/ to detect
 * and warn about duplicate processes.
 */
final class TunnelLock
{
    private string $lockPath;

    /** @var resource|null */
    private mixed $lockHandle = null;

    public function __construct(
        private readonly int $tunnelId,
    ) {
        $this->lockPath = self::lockDir().'/tunnel-'.$tunnelId.'.lock';
    }

    /**
     * Attempt to acquire an exclusive lock for this tunnel.
     *
     * @return array{acquired: bool, existing_pid: ?int, existing_started: ?string, stale: bool}
     */
    public function acquire(): array
    {
        $dir = self::lockDir();
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return ['acquired' => true, 'existing_pid' => null, 'existing_started' => null, 'stale' => false];
        }

        $existing = $this->readExistingLock();

        $this->lockHandle = @fopen($this->lockPath, 'c+');
        if ($this->lockHandle === false) {
            return ['acquired' => true, 'existing_pid' => null, 'existing_started' => null, 'stale' => false];
        }

        if (! flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;

            return [
                'acquired' => false,
                'existing_pid' => $existing['pid'] ?? null,
                'existing_started' => $existing['started'] ?? null,
                'stale' => false,
            ];
        }

        if ($existing !== null && isset($existing['pid'])) {
            $pid = (int) $existing['pid'];
            if ($pid > 0 && ! self::processExists($pid)) {
                $existing = null;
            }
        }

        ftruncate($this->lockHandle, 0);
        rewind($this->lockHandle);
        $data = json_encode([
            'pid' => getmypid(),
            'tunnel_id' => $this->tunnelId,
            'started' => date('Y-m-d H:i:s'),
            'host' => gethostname() ?: 'unknown',
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        fwrite($this->lockHandle, $data."\n");
        fflush($this->lockHandle);

        return ['acquired' => true, 'existing_pid' => null, 'existing_started' => null, 'stale' => false];
    }

    /**
     * Check if another process holds the lock without trying to acquire it.
     *
     * @return array{locked: bool, pid: ?int, started: ?string, stale: bool}
     */
    public function check(): array
    {
        if (! file_exists($this->lockPath)) {
            return ['locked' => false, 'pid' => null, 'started' => null, 'stale' => false];
        }

        $existing = $this->readExistingLock();
        if ($existing === null) {
            return ['locked' => false, 'pid' => null, 'started' => null, 'stale' => false];
        }

        $pid = isset($existing['pid']) ? (int) $existing['pid'] : null;
        $started = $existing['started'] ?? null;

        if ($pid === null || $pid <= 0) {
            return ['locked' => false, 'pid' => null, 'started' => $started, 'stale' => true];
        }

        if ($pid === getmypid()) {
            return ['locked' => false, 'pid' => $pid, 'started' => $started, 'stale' => false];
        }

        if (! self::processExists($pid)) {
            return ['locked' => false, 'pid' => $pid, 'started' => $started, 'stale' => true];
        }

        $handle = @fopen($this->lockPath, 'r');
        if ($handle === false) {
            return ['locked' => true, 'pid' => $pid, 'started' => $started, 'stale' => false];
        }

        $locked = ! flock($handle, LOCK_EX | LOCK_NB);
        if (! $locked) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ['locked' => $locked, 'pid' => $pid, 'started' => $started, 'stale' => ! $locked];
    }

    /**
     * Release the lock if held.
     */
    public function release(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }

        if (file_exists($this->lockPath)) {
            @unlink($this->lockPath);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readExistingLock(): ?array
    {
        if (! file_exists($this->lockPath)) {
            return null;
        }

        $raw = @file_get_contents($this->lockPath);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private static function processExists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @exec('tasklist /FI "PID eq '.$pid.'" 2>NUL', $output);
            foreach ($output as $line) {
                if (str_contains($line, (string) $pid)) {
                    return true;
                }
            }

            return false;
        }

        return posix_kill($pid, 0);
    }

    private static function lockDir(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return $home.'/.cache/jetty/locks';
        }

        if (\PHP_OS_FAMILY === 'Windows') {
            $userProfile = getenv('USERPROFILE');
            if (is_string($userProfile) && $userProfile !== '') {
                return $userProfile.'/.cache/jetty/locks';
            }
        }

        return sys_get_temp_dir().'/jetty-locks';
    }

    /**
     * Clean up stale lock files (processes that no longer exist).
     *
     * @return int Number of stale locks removed
     */
    public static function cleanupStaleLocks(): int
    {
        $dir = self::lockDir();
        if (! is_dir($dir)) {
            return 0;
        }

        $removed = 0;
        $files = @scandir($dir);
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            if (! str_starts_with($file, 'tunnel-') || ! str_ends_with($file, '.lock')) {
                continue;
            }

            $path = $dir.'/'.$file;
            $raw = @file_get_contents($path);
            if ($raw === false) {
                continue;
            }

            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                @unlink($path);
                $removed++;

                continue;
            }

            if (! is_array($data) || ! isset($data['pid'])) {
                @unlink($path);
                $removed++;

                continue;
            }

            $pid = (int) $data['pid'];
            if ($pid <= 0 || ! self::processExists($pid)) {
                @unlink($path);
                $removed++;
            }
        }

        return $removed;
    }
}
