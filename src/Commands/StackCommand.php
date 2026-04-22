<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use JettyCli\ApiClient;
use JettyCli\CliUi;
use JettyCli\Config;

/**
 * Fetches a named multi-tunnel template from the Bridge and runs one `jetty share` per tunnel in parallel
 * (macOS and Linux). On Windows, prints the commands to run manually.
 */
final class StackCommand
{
    public function __construct(
        private readonly CliUi $ui,
        private readonly ApiClient $client,
        private readonly Config $config,
    ) {}

    public function execute(array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->ui->out('Usage: jetty stack <slug>');
            $this->ui->out('  Fetches a crew template from the Bridge and runs `jetty share` for each entry.');
            $this->ui->out('  Parallel start is supported on macOS and Linux. On Windows, this prints commands to copy.');
            $this->ui->out('  See Crew → Workspace on the Bridge to define stack JSON.');

            return 0;
        }

        $slug = $args[0] ?? '';
        if ($slug === '' || str_starts_with($slug, '-')) {
            $this->ui->errorLine('Usage: jetty stack <slug>');

            return 1;
        }

        if (trim($this->config->token) === '') {
            $this->ui->errorLine('Not logged in. Run `jetty login` or set a token in config.');

            return 1;
        }

        $data = $this->client->getTunnelStackTemplateBySlug($slug);
        $rawConfig = is_array($data['config'] ?? null) ? $data['config'] : [];
        $tunnels = $rawConfig['tunnels'] ?? [];
        if (! is_array($tunnels) || $tunnels === []) {
            $this->ui->errorLine('This template has no "tunnels" in its config.');

            return 1;
        }

        $defaultServer = isset($rawConfig['server']) && is_string($rawConfig['server']) && trim($rawConfig['server']) !== ''
            ? trim($rawConfig['server'])
            : null;

        if (\PHP_OS_FAMILY === 'Windows') {
            $this->ui->out('On Windows, run one terminal per line:');
            $this->ui->out('');
            foreach ($tunnels as $t) {
                if (! is_array($t)) {
                    continue;
                }
                $this->ui->out($this->oneShellLine($t, $defaultServer));
            }

            return 0;
        }

        $prefix = $this->childJettyPrefix();
        $procs = [];
        $cwd = getcwd();
        if ($cwd === false) {
            $cwd = null;
        }

        foreach ($tunnels as $t) {
            if (! is_array($t)) {
                $this->ui->errorLine('Stack entry is not an object. Fix the template in the Bridge.');

                return 1;
            }
            $port = (int) ($t['local_port'] ?? 0);
            if ($port < 1 || $port > 65535) {
                $this->ui->errorLine('Stack entry is missing a valid local_port (1-65535).');

                return 1;
            }
            $localHost = isset($t['local_host']) && is_string($t['local_host']) && trim($t['local_host']) !== ''
                ? trim($t['local_host'])
                : '127.0.0.1';
            $cmd = array_merge(
                $prefix,
                [
                    'share',
                    (string) $port,
                    '--host='.$localHost,
                ],
            );
            $subdomain = is_string($t['subdomain'] ?? null) ? trim((string) $t['subdomain']) : '';
            if ($subdomain !== '') {
                $cmd[] = '--subdomain='.$subdomain;
            }
            $server = is_string($t['server'] ?? null) && trim((string) $t['server']) !== ''
                ? trim((string) $t['server'])
                : $defaultServer;
            if ($server !== null) {
                $cmd[] = '--server='.$server;
            }

            $stdinNull = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $p = @proc_open(
                $cmd,
                [
                    0 => ['file', $stdinNull, 'r'],
                    1 => \STDOUT,
                    2 => \STDERR,
                ],
                $unused,
                $cwd,
                null,
                [
                    'bypass_shell' => true,
                ],
            );
            if (! is_resource($p)) {
                $this->ui->errorLine('Failed to start a child for port '.$port.'.');

                return 1;
            }
            $procs[] = $p;
        }

        if ($procs === []) {
            return 0;
        }

        $this->ui->out('');
        $this->ui->out('Running '.count($procs).' `jetty share` process(es) in this terminal. Output from each may be interleaved.');
        $this->ui->out('');

        $rc = 0;
        $remaining = $procs;
        while ($remaining !== []) {
            foreach ($remaining as $i => $p) {
                if (! is_resource($p)) {
                    unset($remaining[$i]);

                    continue;
                }
                $s = proc_get_status($p);
                if (! $s['running']) {
                    $code = (int) $s['exitcode'];
                    if ($code !== 0) {
                        $rc = 1;
                    }
                    proc_close($p);
                    unset($remaining[$i]);
                }
            }
            if ($remaining !== []) {
                usleep(200_000);
            }
        }

        return $rc;
    }

    private function oneShellLine(array $t, ?string $defaultServer): string
    {
        $port = (int) ($t['local_port'] ?? 0);
        $localHost = isset($t['local_host']) && is_string($t['local_host']) && trim($t['local_host']) !== ''
            ? trim($t['local_host'])
            : '127.0.0.1';
        $parts = ['jetty', 'share', (string) $port, '--host='.self::argEscape($localHost)];
        $sd = is_string($t['subdomain'] ?? null) ? trim((string) $t['subdomain']) : '';
        if ($sd !== '') {
            $parts[] = '--subdomain='.self::argEscape($sd);
        }
        $server = is_string($t['server'] ?? null) && trim((string) $t['server']) !== ''
            ? trim((string) $t['server'])
            : $defaultServer;
        if ($server !== null) {
            $parts[] = '--server='.self::argEscape($server);
        }

        $line = '';
        $first = true;
        foreach ($parts as $w) {
            if (! $first) {
                $line .= ' ';
            }
            $first = false;
            if (str_contains($w, ' ')) {
                $line .= '"'.str_replace('"', '\"', $w).'"';
            } else {
                $line .= $w;
            }
        }

        return $line;
    }

    private static function argEscape(string $s): string
    {
        if (str_contains($s, ' ')) {
            return '"'.str_replace('"', '\"', $s).'"';
        }

        return $s;
    }

    /**
     * @return list<non-falsy-string>
     */
    private function childJettyPrefix(): array
    {
        $argv0 = $_SERVER['argv'][0] ?? 'jetty';
        if (! is_string($argv0) || $argv0 === '') {
            $argv0 = 'jetty';
        }
        if (str_ends_with(strtolower($argv0), '.phar') && (is_file($argv0) || (getcwd() !== false && is_file(getcwd().\DIRECTORY_SEPARATOR.$argv0)))) {
            $path = is_file($argv0) ? $argv0 : getcwd().\DIRECTORY_SEPARATOR.$argv0;
            $r = realpath($path);
            if ($r !== false) {
                return [PHP_BINARY, $r];
            }

            return [PHP_BINARY, $path];
        }
        if (is_file($argv0)) {
            $r = realpath($argv0);
            if ($r !== false) {
                return [$r];
            }

            return [$argv0];
        }

        return [$argv0];
    }
}
