<?php

declare(strict_types=1);

namespace JettyCli;

use JettyCli\Commands\AuthCommands;
use JettyCli\Commands\ConfigCommand;
use JettyCli\Commands\HelpRenderer;

final class Application
{
    private ?CliUi $cliUi = null;

    private ?HelpRenderer $helpRenderer = null;

    private ?ConfigCommand $configCommand = null;

    private ?AuthCommands $authCommands = null;

    private function helpRenderer(): HelpRenderer
    {
        return $this->helpRenderer ??= new HelpRenderer($this->ui());
    }

    private function configCommand(): ConfigCommand
    {
        return $this->configCommand ??= new ConfigCommand($this->ui());
    }

    private function authCommands(): AuthCommands
    {
        return $this->authCommands ??= new AuthCommands($this->ui());
    }

    private function ui(): CliUi
    {
        return $this->cliUi ??= CliUi::forStdio();
    }

    public function run(array $argv): int
    {
        array_shift($argv);

        try {
            [$global, $rest] = $this->parseGlobalFlags($argv);

            if ($rest === []) {
                $cfg = $this->resolvedConfig($global);
                if (trim($cfg->token) === '') {
                    try {
                        SetupWizard::runOnboarding(
                            $global['config'] ?? null,
                            $global['region'] ?? null,
                        );
                    } catch (\Throwable $e) {
                        $this->ui()->errorLine($e->getMessage());

                        return 1;
                    }

                    return 0;
                }
                $this->printStyledMainHelp();
                $this->ui()->mutedLine(
                    'Tip: run `jetty help` for the full command reference, or `jetty help --advanced` for env vars.',
                );

                return 1;
            }

            $command = array_shift($rest);

            $code = match ($command) {
                'version', '--version', '-V' => $this->cmdVersion($rest),
                'update',
                'self-update',
                'global-update' => $this->cmdSelfUpdate($rest),
                'install-client' => $this->cmdInstallClient($rest),
                'list' => $this->cmdList($global, $rest),
                'replay' => $this->cmdReplay($global, $rest),
                'domains' => $this->cmdDomains($global, $rest),
                'delete' => $this->cmdDelete($global, $rest),
                'share', 'http' => $this->cmdShare($global, $rest),
                'stack' => $this->cmdStack($global, $rest),
                'login' => $this->cmdLogin($global, $rest),
                'onboard' => $this->cmdOnboard($global, $rest),
                'setup' => $this->cmdSetup($global, $rest),
                'logout' => $this->cmdLogout($rest),
                'reset' => $this->cmdReset($rest),
                'config' => $this->cmdConfig($global, $rest),
                'doctor' => $this->cmdDoctor(),
                'completions' => $this->cmdCompletions($rest),
                'help', '--help', '-h' => $this->cmdHelp($rest),
                default => throw new \InvalidArgumentException(
                    'Unknown command: '.
                        $command.
                        $this->unknownCommandUpgradeHint($command).
                        "\n".
                        $this->helpText(),
                ),
            };
            $this->maybePrintUpdateNotice($command, $code);

            return $code;
        } catch (\Throwable $e) {
            $diag = CliDiagnostics::diagnose($e);
            if ($diag !== null) {
                $this->ui()->errorLine($diag['error']);
                $this->stderr(CliDiagnostics::format($diag));
            } else {
                $this->ui()->errorLine($e->getMessage());
            }

            return 1;
        }
    }

    /**
     * @return array{0: array{api-url: ?string, token: ?string, config: ?string, region: ?string}, 1: list<string>}
     */
    private function parseGlobalFlags(array $argv): array
    {
        $apiUrl = null;
        $token = null;
        $config = null;
        $region = null;
        $rest = [];
        $n = count($argv);

        for ($i = 0; $i < $n; $i++) {
            $arg = $argv[$i];
            if (str_starts_with($arg, '--config=')) {
                $config = substr($arg, strlen('--config='));
                if ($config === '') {
                    throw new \InvalidArgumentException(
                        '--config= requires a path',
                    );
                }

                continue;
            }
            if ($arg === '--config') {
                $config =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--config requires a path',
                    );
                if ($config === '' || str_starts_with($config, '--')) {
                    throw new \InvalidArgumentException(
                        '--config requires a path',
                    );
                }

                continue;
            }
            if (str_starts_with($arg, '--api-url=')) {
                $apiUrl = substr($arg, strlen('--api-url='));

                continue;
            }
            if ($arg === '--api-url') {
                $apiUrl =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--api-url requires a value',
                    );
                if ($apiUrl === '' || str_starts_with($apiUrl, '--')) {
                    throw new \InvalidArgumentException(
                        '--api-url requires a value',
                    );
                }

                continue;
            }
            if (str_starts_with($arg, '--token=')) {
                $token = substr($arg, strlen('--token='));

                continue;
            }
            if ($arg === '--token') {
                $token =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--token requires a value',
                    );
                if ($token === '' || str_starts_with($token, '--')) {
                    throw new \InvalidArgumentException(
                        '--token requires a value',
                    );
                }

                continue;
            }
            if (str_starts_with($arg, '--region=')) {
                $r = substr($arg, strlen('--region='));
                if (trim($r) !== '') {
                    DefaultBridge::normalizeRegion($r);
                    $region = trim($r);
                }

                continue;
            }
            if ($arg === '--region') {
                $r =
                    $argv[++$i] ??
                    throw new \InvalidArgumentException(
                        '--region requires a value',
                    );
                if ($r === '' || str_starts_with($r, '--')) {
                    throw new \InvalidArgumentException(
                        '--region requires a value',
                    );
                }
                DefaultBridge::normalizeRegion($r);
                $region = $r;

                continue;
            }
            $rest[] = $arg;
        }

        $region =
            is_string($region) && trim($region) !== '' ? trim($region) : null;

        return [
            [
                'api-url' => $apiUrl,
                'token' => $token,
                'config' => $config,
                'region' => $region,
            ],
            $rest,
        ];
    }

    /**
     * @return array{api-url: ?string, token: ?string, config: ?string, region: ?string}
     */
    private function defaultGlobalState(): array
    {
        return [
            'api-url' => null,
            'token' => null,
            'config' => null,
            'region' => null,
        ];
    }

    /**
     * @param  array{api-url?: ?string, token?: ?string, config?: ?string, region?: ?string}  $global
     */
    private function resolvedConfig(array $global): Config
    {
        return Config::resolve(
            $global['config'] ?? null,
            $global['region'] ?? null,
        )->merge($global['api-url'] ?? null, $global['token'] ?? null);
    }

    /**
     * @param  array{api-url?: ?string, token?: ?string, config?: ?string, region?: ?string}  $global
     */
    private function client(array $global): ApiClient
    {
        $cfg = $this->resolvedConfig($global);
        $cfg->validate();

        return new ApiClient($cfg->apiUrl, $cfg->token);
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdVersion(array $args): int
    {
        if (in_array('--machine', $args, true)) {
            $this->stdout(ApiClient::VERSION);

            return 0;
        }

        $wantInstall = in_array('--install', $args, true);
        $checkUpdate = in_array('--check-update', $args, true);

        $u = $this->ui();
        $u->banner('v'.ApiClient::VERSION);
        $u->out($u->bold('jetty/client').' '.$u->cyan(ApiClient::VERSION));

        if ($wantInstall) {
            $this->stdout('');
            $this->stdout($this->versionInstallDetails());
        }

        if ($checkUpdate) {
            if ($wantInstall) {
                $this->stdout('');
            }
            $pharPath = \Phar::running(false);
            if ($pharPath !== '') {
                $repo = $this->releasesRepo();
                $token = $this->githubTokenForReleases();
                $latest = GitHubPharRelease::latest($repo, $token);
                if ($latest !== null) {
                    $remoteSemver = GitHubPharRelease::tagToSemver(
                        $latest['tag_name'],
                    );
                    $cmp = version_compare($remoteSemver, ApiClient::VERSION);
                    if ($cmp > 0) {
                        $this->ui()->warnLine(
                            'Update available: '.
                                $latest['tag_name'].
                                ' (you have '.
                                ApiClient::VERSION.
                                ') — run: '.
                                $this->ui()->cmd('jetty update'),
                        );
                    } else {
                        $this->ui()->successLine(
                            'Up to date with latest release '.
                                $latest['tag_name'].
                                '.',
                        );
                    }
                }
            } else {
                $this->ui()->warnLine(
                    '--check-update applies to PHAR installs only. Reinstall via `curl -sSf https://usejetty.online/install.sh | sh`.',
                );
            }
        }

        return 0;
    }

    private function versionInstallDetails(): string
    {
        $pharPath = \Phar::running(false);
        if ($pharPath !== '') {
            $lines = [
                'Install: PHAR',
                '  Path:    '.$pharPath,
                '  Update:  jetty update   (re-downloads from GitHub cli-v* for '.
                $this->releasesRepo().
                ')',
                '',
                'Config is shared: ~/.config/jetty/config.json applies no matter how you installed the binary.',
            ];
            $multi = $this->detectMultipleJettyBinariesOnPath();
            if ($multi !== null) {
                $lines[] = '';
                $lines[] =
                    'Multiple `jetty` executables on PATH — you only maintain the one you actually run:';
                foreach ($multi as $p) {
                    $lines[] = '  '.$p;
                }
            }

            return implode("\n", $lines);
        }

        $lines = [
            'Install: not a PHAR (this looks like a Composer-installed copy or a dev checkout).',
            '',
            'The supported install path is the PHAR placed by install.sh:',
            '  curl -sSf https://usejetty.online/install.sh | sh',
            '',
            'After reinstalling, `jetty update` updates the PHAR at ~/.local/bin/jetty in place.',
            'Override the path with JETTY_PHAR_PATH if you installed the PHAR somewhere else.',
        ];
        $multi = $this->detectMultipleJettyBinariesOnPath();
        if ($multi !== null) {
            $lines[] = '';
            $lines[] = 'Multiple `jetty` executables on PATH:';
            foreach ($multi as $p) {
                $lines[] = '  '.$p;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>|null
     */
    private function detectMultipleJettyBinariesOnPath(): ?array
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $out = [];
            @exec('where.exe jetty 2>NUL', $out, $code);
        } else {
            $out = [];
            @exec('which -a jetty 2>/dev/null', $out, $code);
        }
        if ($code !== 0 || $out === []) {
            return null;
        }
        $paths = array_values(array_unique(array_map('trim', $out)));
        $paths = array_values(array_filter($paths, fn (string $p) => $p !== ''));

        return count($paths) > 1 ? $paths : null;
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdSelfUpdate(array $args): int
    {
        return (new Commands\UpdateCommand($this->ui(), $this->resolvedConfig($this->defaultGlobalState())))->executeSelfUpdate($args);
    }

    private function cmdInstallClient(array $args): int
    {
        return (new Commands\UpdateCommand($this->ui(), $this->resolvedConfig($this->defaultGlobalState())))->executeInstallClient($args);
    }

    /**
     * @param  list<string>  $args
     */
    private function updatePharInPlace(array $args, string $pharPath): int
    {
        return $this->updateCommand()->updatePharInPlace($args, $pharPath);
    }

    private function localPharUpdateUrl(): ?string
    {
        return $this->updateCommand()->localPharUpdateUrl();
    }

    /**
     * @return bool true if the on-disk PHAR was replaced; false if the download was byte-identical to the existing file
     */
    private function applyPharDownload(
        string $pharPath,
        string $url,
        ?string $githubToken,
        ?string $checksumUrl = null,
    ): bool {
        return $this->updateCommand()->applyPharDownload($pharPath, $url, $githubToken, $checksumUrl);
    }

    private function emitPostUpdateConfigTip(): void
    {
        $this->updateCommand()->emitPostUpdateConfigTip();
    }

    private function releasesRepo(): string
    {
        return $this->updateCommand()->releasesRepo();
    }

    private function githubTokenForReleases(): ?string
    {
        return $this->updateCommand()->githubTokenForReleases();
    }

    private function unknownCommandUpgradeHint(string $command): string
    {
        return $this->updateCommand()->unknownCommandUpgradeHint($command);
    }

    private function updateCommand(): Commands\UpdateCommand
    {
        return new Commands\UpdateCommand($this->ui(), $this->resolvedConfig($this->defaultGlobalState()));
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdCompletions(array $args): int
    {
        $shell = $args[0] ?? '';
        if ($shell === '' || $shell === '--help') {
            $this->ui()->out('Usage: jetty completions <bash|zsh|fish>');
            $this->ui()->out('');
            $this->ui()->out('Install:');
            $this->ui()->out('  Bash:  eval "$(jetty completions bash)"');
            $this->ui()->out('  Zsh:   eval "$(jetty completions zsh)"');
            $this->ui()->out('  Fish:  jetty completions fish > ~/.config/fish/completions/jetty.fish');

            return 0;
        }

        echo ShellCompletions::generate($shell);

        return 0;
    }

    private function cmdHelp(array $rest): int
    {
        return $this->helpRenderer()->execute($rest);
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     */
    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdList(array $global, array $rest = []): int
    {
        if (in_array('--help', $rest, true) || in_array('-h', $rest, true)) {
            $this->stdout("Usage: jetty list [--long|-l]\n");

            return 0;
        }
        $long = in_array('--long', $rest, true) || in_array('-l', $rest, true);
        $unknown = array_values(
            array_filter(
                $rest,
                fn (string $x) => ! in_array($x, ['--long', '-l'], true),
            ),
        );
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty list [--long|-l]\n",
            );
        }

        $client = $this->client($global);
        $tunnels = $client->listTunnels();

        $u = $this->ui();
        if ($tunnels === []) {
            $u->mutedLine('No tunnels for this account yet.');
            $u->infoLine('Create one with '.$u->cmd('jetty share'));

            return 0;
        }

        $u->section('Your tunnels');
        foreach ($tunnels as $t) {
            $id = (string) ($t['id'] ?? '');
            $status = (string) ($t['status'] ?? '');
            $public = (string) ($t['public_url'] ?? '');
            $local = (string) ($t['local_target'] ?? '');
            $st = $this->formatTunnelStatusLabel($status);
            if ($long) {
                $note =
                    isset($t['note']) &&
                    is_string($t['note']) &&
                    trim($t['note']) !== ''
                        ? trim($t['note'])
                        : '';
                $suffix = $note !== '' ? '  '.$u->dim('note: '.$note) : '';
                $u->out($u->bold($id).'  '.$st.'  '.$u->cyan($public));
                $u->mutedLine('    → '.$local.$suffix);

                // Display routing rules if present
                $routingRules =
                    isset($t['routing_rules']) && is_array($t['routing_rules'])
                        ? $t['routing_rules']
                        : [];
                if ($routingRules !== []) {
                    $u->mutedLine('    Routes:');
                    $ruleNum = 0;
                    foreach ($routingRules as $rule) {
                        if (! is_array($rule)) {
                            continue;
                        }
                        $enabled = $rule['enabled'] ?? true;
                        if (! $enabled) {
                            continue;
                        }
                        $ruleNum++;
                        $matchType = (string) ($rule['match_type'] ?? '');
                        $localHost = (string) ($rule['local_host'] ?? '');
                        $localPort = (int) ($rule['local_port'] ?? 0);
                        $upstream = $localHost.':'.$localPort;

                        if ($matchType === 'path_prefix') {
                            $pathPrefix =
                                (string) ($rule['path_prefix'] ?? '/');
                            $pattern =
                                $pathPrefix === '/' ? '/*' : $pathPrefix.'/*';
                            $u->mutedLine(
                                '      '.
                                    $ruleNum.
                                    '. '.
                                    $pattern.
                                    ' → '.
                                    $upstream.
                                    ' (path_prefix)',
                            );
                        } elseif ($matchType === 'header') {
                            $headerName = (string) ($rule['header_name'] ?? '');
                            $headerValue =
                                (string) ($rule['header_value'] ?? '');
                            $u->mutedLine(
                                '      '.
                                    $ruleNum.
                                    '. '.
                                    $headerName.
                                    ': '.
                                    $headerValue.
                                    ' → '.
                                    $upstream.
                                    ' (header)',
                            );
                        }
                    }
                    // Show fallback (tunnel's primary upstream)
                    $u->mutedLine('      (fallback) → '.$local);
                }
            } else {
                $u->out(
                    $u->bold($id).
                        '  '.
                        $st.
                        '  '.
                        $u->cyan($public).
                        '  '.
                        $u->dim($local),
                );
            }
        }
        $u->out('');

        return 0;
    }

    private function formatTunnelStatusLabel(string $status): string
    {
        $u = $this->ui();
        $s = strtolower(trim($status));
        if ($s === '' || $s === 'unknown') {
            return $u->dim($status !== '' ? $status : '—');
        }
        if (
            str_contains($s, 'active') ||
            str_contains($s, 'run') ||
            $s === 'up' ||
            $s === 'online'
        ) {
            return $u->green($status);
        }
        if (
            str_contains($s, 'idle') ||
            str_contains($s, 'pause') ||
            str_contains($s, 'sleep')
        ) {
            return $u->yellow($status);
        }
        if (
            str_contains($s, 'error') ||
            str_contains($s, 'fail') ||
            str_contains($s, 'down') ||
            str_contains($s, 'off')
        ) {
            return $u->red($status);
        }

        return $u->dim($status);
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdReplay(array $global, array $rest): int
    {
        if (
            $rest === [] ||
            in_array('--help', $rest, true) ||
            in_array('-h', $rest, true)
        ) {
            $this->stdout("Usage: jetty replay <sample-id>\n");
            $this->stdout(
                "Replays a stored request sample against the tunnel's local_host:local_port.\n",
            );
            $this->stdout(
                "GET and HEAD only unless JETTY_REPLAY_ALLOW_UNSAFE=1.\n",
            );

            return 0;
        }

        $id = (int) ($rest[0] ?? 0);
        if ($id < 1) {
            throw new \InvalidArgumentException('Invalid sample id');
        }

        $client = $this->client($global);
        $data = $client->getRequestSample($id);
        $method = strtoupper((string) ($data['method'] ?? 'GET'));
        $unsafe = ReplayConfig::allowUnsafe();
        if ($method !== 'GET' && $method !== 'HEAD' && ! $unsafe) {
            $this->stderr(
                'Replay only allows GET/HEAD by default. Set JETTY_REPLAY_ALLOW_UNSAFE=1 to replay other methods.',
            );

            return 1;
        }

        $tunnel = $data['tunnel'] ?? null;
        if (! is_array($tunnel)) {
            throw new \RuntimeException('API response missing tunnel');
        }

        $host = (string) ($tunnel['local_host'] ?? '127.0.0.1');
        $port = (int) ($tunnel['local_port'] ?? 80);
        $path = (string) ($data['path'] ?? '/');
        if ($path === '' || ($path[0] ?? '') !== '/') {
            $path = '/'.ltrim($path, '/');
        }

        $query = $data['query'] ?? null;
        $query = is_string($query) && $query !== '' ? $query : null;

        $localUrl = 'http://'.$host.':'.$port.$path;
        if ($query !== null) {
            $localUrl .= '?'.$query;
        }

        $ch = curl_init($localUrl);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($method === 'GET' || $method === 'HEAD') {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $out = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($out === false) {
            throw new \RuntimeException('replay failed: '.$err);
        }

        $this->stdout((string) $out);
        $this->ui()->mutedLine('HTTP '.$code);

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $args
     */
    private function cmdDomains(array $global, array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->stdout("Usage: jetty domains [--json]\n");
            $this->stdout(
                "List subdomain labels reserved for your team (public URL preview).\n",
            );

            return 0;
        }
        $jsonOut =
            in_array('--json', $args, true) ||
            in_array('--machine', $args, true);
        $unknown = array_values(
            array_filter(
                $args,
                fn (string $x) => ! in_array($x, ['--json', '--machine'], true),
            ),
        );
        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty domains [--json]\n",
            );
        }

        $client = $this->client($global);
        $payload = $client->listReservedSubdomains();
        $rows = $payload['data'];
        $meta = $payload['meta'] ?? [];
        $suffix =
            is_array($meta) &&
            isset($meta['tunnel_host_suffix']) &&
            is_string($meta['tunnel_host_suffix'])
                ? $meta['tunnel_host_suffix']
                : '';

        if ($jsonOut) {
            $this->stdout(
                json_encode(
                    ['data' => $rows, 'meta' => $meta],
                    JSON_THROW_ON_ERROR,
                ),
            );

            return 0;
        }

        $u = $this->ui();
        if ($rows === []) {
            $u->mutedLine('No reserved subdomains for this team.');
            $u->infoLine('Add labels in the Jetty app (Domains).');
            if ($suffix !== '') {
                $u->out(
                    '  '.$u->dim('Suffix').'  '.$u->cyan('*.'.$suffix),
                );
            }

            return 0;
        }

        $u->section('Reserved subdomain labels');
        if ($suffix !== '') {
            $u->out(
                '  '.
                    $u->dim('Public URLs look like').
                    '  '.
                    $u->cyan('label.'.$suffix),
            );
        }
        $u->out('');
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $slug = (string) ($row['slug'] ?? '');
            $hint = (string) ($row['full_host_hint'] ?? '');
            if ($hint !== '') {
                $u->out(
                    '  '.$u->bold($u->green($slug)).'  '.$u->dim($hint),
                );
            } else {
                $u->out('  '.$u->bold($u->green($slug)));
            }
        }
        $u->out('');
        $u->infoLine(
            'Use '.
                $u->cmd('jetty share --subdomain=LABEL').
                ' or set subdomain in jetty.config.json',
        );

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $args
     */
    private function cmdDelete(array $global, array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException(
                'Usage: jetty delete <tunnel-id>',
            );
        }
        $id = trim($args[0]);
        if ($id === '') {
            throw new \InvalidArgumentException('Invalid tunnel id');
        }

        $this->client($global)->deleteTunnel($id);
        $this->stderr("Deleted tunnel {$id}");

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdLogin(array $global, array $rest): int
    {
        return $this->authCommands()->login($global, $rest, $this->helpText());
    }

    private function cmdOnboard(array $global, array $rest): int
    {
        return $this->authCommands()->onboard($global, $rest, $this->helpText());
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    private function cmdSetup(array $global, array $rest): int
    {
        return $this->authCommands()->setup($global, $rest, $this->helpText());
    }

    /**
     * @param  list<string>  $rest
     */
    private function cmdLogout(array $rest): int
    {
        return $this->authCommands()->logout($rest, $this->helpText());
    }

    /**
     * @param  list<string>  $rest
     */
    private function cmdReset(array $rest): int
    {
        return $this->authCommands()->reset($rest, $this->helpText());
    }

    private function cmdConfig(array $global, array $args): int
    {
        return $this->configCommand()->execute(
            $global,
            $args,
            fn (array $g, array $r) => $this->cmdSetup($g, $r),
            $this->helpText(),
        );
    }

    // ── jetty doctor ────────────────────────────────────────────────

    private function cmdDoctor(): int
    {
        $empty = $this->defaultGlobalState();

        return (new Commands\DoctorCommand($this->ui(), $this->client($empty), $this->resolvedConfig($empty)))->execute();
    }

    private function cmdShare(array $global, array $args): int
    {
        return (new Commands\ShareCommand(
            $this->ui(),
            $this->client($global),
            $this->resolvedConfig($global),
            $global,
        ))->execute($args);
    }

    private function cmdStack(array $global, array $args): int
    {
        return (new Commands\StackCommand(
            $this->ui(),
            $this->client($global),
            $this->resolvedConfig($global),
        ))->execute($args);
    }

    private function printStyledMainHelp(): void
    {
        $this->helpRenderer()->printStyledMainHelp();
    }

    private function printAdvancedHelpStyled(): void
    {
        $this->helpRenderer()->printAdvancedHelpStyled();
    }

    private function helpText(): string
    {
        return $this->helpRenderer()->helpText();
    }

    private function helpTextAdvanced(): string
    {
        return $this->helpRenderer()->helpTextAdvanced();
    }

    private function maybePrintUpdateNotice(string $command, int $exitCode): void
    {
        $this->helpRenderer()->maybePrintUpdateNotice(
            $command,
            $exitCode,
            fn () => $this->localPharUpdateUrl(),
            fn () => $this->releasesRepo(),
            fn () => $this->githubTokenForReleases(),
        );
    }

    private function stdout(string $s): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }

    private function stderr(string $s): void
    {
        fwrite(\STDERR, $s.\PHP_EOL);
    }
}
