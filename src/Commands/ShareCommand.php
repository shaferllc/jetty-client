<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use Composer\InstalledVersions;
use JettyCli\ApiClient;
use JettyCli\CliUi;
use JettyCli\Config;
use JettyCli\EdgeAgent;
use JettyCli\EdgeAgentDebug;
use JettyCli\EdgeAgentResult;
use JettyCli\GitHubPharRelease;
use JettyCli\LocalDevDetector;
use JettyCli\LocalUpstreamUrl;
use JettyCli\ShareIdleConfig;
use JettyCli\ShareTrafficView;
use JettyCli\ShareUpstreamHostPolicy;
use JettyCli\TelegramNotifier;
use JettyCli\TunnelLock;
use JettyCli\TunnelResponseRewriter;
use JettyCli\TunnelResumeMatcher;
use JettyCli\TunnelRewriteOptions;
use JettyCli\UpdateConfig;

final class ShareCommand
{
    private ?ShareTrafficView $trafficView = null;

    public function __construct(
        private readonly CliUi $ui,
        private readonly ApiClient $client,
        private readonly Config $config,
        private readonly array $global,
    ) {}

    public function execute(array $args): int
    {
        if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
            $this->stdout($this->shareUsageHelp());

            return 0;
        }

        $cw0 = getcwd();
        if ($cw0 !== false) {
            $rp0 = realpath($cw0);
            putenv(
                'JETTY_SHARE_INVOCATION_CWD='.($rp0 !== false ? $rp0 : $cw0),
            );
        }
        putenv('JETTY_SHARE_CLI_UPSTREAM_HOSTNAME');

        $localHost = '127.0.0.1';
        /** @var non-empty-string|null Last non-IP --site/--host value for tunnel redirect rewriting */
        $shareCliHostnameForRewrite = null;
        $upstreamExplicit = false;
        $tunnelServerFlag = null;
        $printUrlOnly = false;
        $subdomain = null;
        $shareVerbose = false;
        $noDetect = false;
        $serveDocroot = null;
        $deleteOnExit = getenv('JETTY_SHARE_DELETE_ON_EXIT') === '1';
        $noResume = false;
        $forceShare = false;
        $noHealthCheck = false;
        $healthPath = null;
        /** @var list<array{match_type: string, path_prefix?: string, local_host: string, local_port: int, enabled: bool}> */
        $routingRules = [];
        /** @var int|null Tunnel auto-expire in seconds */
        $expiresInSeconds = null;

        $shareFile = Config::readProjectShareOverrides();
        $shareProjectRoot = $shareFile['project_root'] ?? null;
        if (
            getenv('JETTY_SHARE_PROJECT_ROOT') === false &&
            is_string($shareProjectRoot) &&
            trim($shareProjectRoot) !== ''
        ) {
            $root = trim($shareProjectRoot);
            $cfgPath = Config::nearestProjectJettyConfigPath();
            if (
                $cfgPath !== null &&
                $root !== '' &&
                ! preg_match('#^(/|[A-Za-z]:[\\\\/])#', $root)
            ) {
                $root = dirname($cfgPath).\DIRECTORY_SEPARATOR.$root;
            }
            $rp = realpath($root);
            if ($rp !== false && is_dir($rp)) {
                putenv('JETTY_SHARE_PROJECT_ROOT='.$rp);
            }
        }
        $shareDebugAgent = filter_var(
            $shareFile['debug_agent'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        if (EdgeAgentDebug::enabledFromEnvironment()) {
            $shareDebugAgent = true;
        }
        $skipEdge = filter_var(
            $shareFile['skip_edge'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $noBodyRewrite = filter_var(
            $shareFile['no_body_rewrite'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $noJsRewrite = filter_var(
            $shareFile['no_js_rewrite'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        $noCssRewrite = filter_var(
            $shareFile['no_css_rewrite'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
        );
        if (
            getenv('JETTY_SHARE_REWRITE_HOSTS') === false &&
            isset($shareFile['rewrite_hosts'])
        ) {
            $rh = $shareFile['rewrite_hosts'];
            if (is_string($rh) && trim($rh) !== '') {
                putenv('JETTY_SHARE_REWRITE_HOSTS='.trim($rh));
            } elseif (is_array($rh)) {
                $parts = array_filter(
                    array_map('trim', array_map('strval', $rh)),
                );
                if ($parts !== []) {
                    putenv('JETTY_SHARE_REWRITE_HOSTS='.implode(',', $parts));
                }
            }
        }

        $positional = [];
        foreach ($args as $arg) {
            if ($arg === '--delete-on-exit') {
                $deleteOnExit = true;

                continue;
            }
            if ($arg === '--no-delete-on-exit') {
                $deleteOnExit = false;

                continue;
            }
            if ($arg === '--verbose' || $arg === '-v' || $arg === '--errors') {
                $shareVerbose = true;

                continue;
            }
            if ($arg === '--debug-agent') {
                $shareDebugAgent = true;

                continue;
            }
            if ($arg === '--no-detect') {
                $noDetect = true;

                continue;
            }
            if ($arg === '--serve') {
                $serveDocroot = $this->shareDefaultServeDocroot();

                continue;
            }
            if (str_starts_with($arg, '--serve=')) {
                $p = substr($arg, strlen('--serve='));
                $serveDocroot =
                    $p === ''
                        ? $this->shareDefaultServeDocroot()
                        : $this->shareResolveServeDocroot($p);

                continue;
            }
            if (str_starts_with($arg, '--server=')) {
                $v = trim(substr($arg, strlen('--server=')));
                if ($v === '') {
                    throw new \InvalidArgumentException(
                        '--server= requires a tunnel id (e.g. us-west-1)',
                    );
                }
                $this->assertTunnelServerLabel($v);
                $tunnelServerFlag = $v;

                continue;
            }
            if (str_starts_with($arg, '--site=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--site=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--bind=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--bind=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--local=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--local=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--local-host=')) {
                $localHost = $this->parseShareUpstreamValue(
                    $arg,
                    '--local-host=',
                );
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--host=')) {
                $localHost = $this->parseShareUpstreamValue($arg, '--host=');
                $upstreamExplicit = true;
                $this->shareMaybeSetCliHostnameForRewrite(
                    $shareCliHostnameForRewrite,
                    $localHost,
                );

                continue;
            }
            if (str_starts_with($arg, '--subdomain=')) {
                $subdomain = substr($arg, strlen('--subdomain='));
                if ($subdomain === '') {
                    throw new \InvalidArgumentException(
                        '--subdomain= requires a value',
                    );
                }

                continue;
            }
            // --expires=30m or --expires=3600 (seconds)
            if (str_starts_with($arg, '--expires=')) {
                $expiresRaw = substr($arg, strlen('--expires='));
                $expiresIn = self::parseDurationToSeconds($expiresRaw);
                if ($expiresIn !== null && $expiresIn >= 60) {
                    $expiresInSeconds = $expiresIn;
                }

                continue;
            }
            // --route /api=8080 or --route /api=api-server:8080
            if (str_starts_with($arg, '--route=')) {
                $routeSpec = substr($arg, strlen('--route='));
                $parsed = self::parseRouteFlag($routeSpec);
                if ($parsed !== null) {
                    $routingRules[] = $parsed;
                }

                continue;
            }
            // --routes-file=path/to/routes.json
            if (str_starts_with($arg, '--routes-file=')) {
                $routesFile = substr($arg, strlen('--routes-file='));
                $loadedRules = self::loadRoutesFile($routesFile);
                if ($loadedRules !== []) {
                    $routingRules = array_merge($routingRules, $loadedRules);
                }

                continue;
            }
            if ($arg === '--print-url-only') {
                $printUrlOnly = true;

                continue;
            }
            if ($arg === '--skip-edge') {
                $skipEdge = true;

                continue;
            }
            if ($arg === '--edge') {
                $skipEdge = false;

                continue;
            }
            if ($arg === '--no-body-rewrite') {
                $noBodyRewrite = true;

                continue;
            }
            if ($arg === '--no-js-rewrite') {
                $noJsRewrite = true;

                continue;
            }
            if ($arg === '--no-css-rewrite') {
                $noCssRewrite = true;

                continue;
            }
            if ($arg === '--no-resume') {
                $noResume = true;

                continue;
            }
            if ($arg === '--force' || $arg === '-f') {
                $forceShare = true;

                continue;
            }
            if ($arg === '--no-health-check') {
                $noHealthCheck = true;

                continue;
            }
            if (str_starts_with($arg, '--health-path=')) {
                $healthPath = substr($arg, strlen('--health-path='));

                continue;
            }
            if (str_starts_with($arg, '--')) {
                throw new \InvalidArgumentException(
                    'Unknown option: '.
                        $arg.
                        "\n".
                        $this->shareUsageSummary(),
                );
            }
            $positional[] = $arg;
        }

        if ($shareCliHostnameForRewrite !== null) {
            putenv(
                'JETTY_SHARE_CLI_UPSTREAM_HOSTNAME='.
                    $shareCliHostnameForRewrite,
            );
        }

        if (count($positional) > 1) {
            throw new \InvalidArgumentException(
                "Too many arguments.\n".$this->shareUsageHelp(),
            );
        }

        $explicitPort = null;
        if ($positional !== []) {
            $rawPort = $positional[0];
            if (
                ! is_numeric($rawPort) ||
                (string) (int) $rawPort !== (string) $rawPort
            ) {
                throw new \InvalidArgumentException(
                    'Invalid port: expected 1–65535, or omit port to auto-detect a local dev server.'.
                        "\n".
                        $this->shareUsageHelp(),
                );
            }
            $explicitPort = (int) $rawPort;
        }

        if ($printUrlOnly && $serveDocroot !== null) {
            throw new \InvalidArgumentException(
                '--serve cannot be combined with --print-url-only.',
            );
        }

        // Auto-load .jetty/routes.json if present and no --route flags were given.
        if ($routingRules === []) {
            $cwd = getcwd();
            if ($cwd !== false) {
                $autoRoutesPath = $cwd.'/.jetty/routes.json';
                $routingRules = self::loadRoutesFile($autoRoutesPath);
            }
        }

        $builtInServerProc = null;
        $pendingServe = null;
        $port = 8000;
        $portHint = null;

        if ($serveDocroot !== null) {
            $listenPort = $explicitPort ?? $this->shareFindFreeTcpPort();
            if ($listenPort < 1 || $listenPort > 65535) {
                throw new \InvalidArgumentException(
                    'Invalid port for --serve.',
                );
            }
            $pendingServe = ['docroot' => $serveDocroot, 'port' => $listenPort];
            $localHost = '127.0.0.1';
            $port = $listenPort;
            $portHint =
                'PHP built-in server → http://127.0.0.1:'.
                $listenPort.
                ' (root: '.
                $serveDocroot.
                ')';
            $upstreamExplicit = true;
        } else {
            $detected = null;
            if (
                ! $noDetect &&
                ! $upstreamExplicit &&
                $explicitPort === null &&
                getenv('JETTY_SHARE_NO_DETECT') !== '1'
            ) {
                $cwd = getcwd();
                if ($cwd !== false) {
                    $detected = LocalDevDetector::detect($cwd);
                }
            }
            if ($detected !== null) {
                $localHost = $detected['host'];
                $port = $detected['port'];
                $portHint = $detected['hint'];
            } else {
                [$port, $portHint] = $this->resolveSharePort(
                    $localHost,
                    $explicitPort,
                );
            }
        }

        $cfg = $this->config;
        $cfg->validate();
        $client = $this->client;

        if ($pendingServe !== null) {
            try {
                $builtInServerProc = $this->shareStartPhpBuiltInServer(
                    $pendingServe['docroot'],
                    $pendingServe['port'],
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Failed to start PHP built-in server: '.$e->getMessage(),
                );
            }
        }
        if ($subdomain === null || trim((string) $subdomain) === '') {
            $d = trim($cfg->defaultSubdomain);
            if ($d !== '') {
                $subdomain = $d;
            }
        }

        $tunnelServer =
            $tunnelServerFlag !== null
                ? $tunnelServerFlag
                : trim($cfg->defaultTunnelServer);
        if ($tunnelServer === '') {
            $tunnelServer = null;
        } else {
            $this->assertTunnelServerLabel($tunnelServer);
        }

        if ($shareVerbose) {
            $this->shareVerboseLog(true, 'API base: '.$client->apiBaseUrl());
            $this->shareVerboseLog(
                true,
                'share target: '.
                    $localHost.
                    ':'.
                    $port.
                    '; subdomain='.
                    ($subdomain ?? '').
                    '; tunnel_server='.
                    ($tunnelServer ?? 'null'),
            );
        }

        if ($portHint !== null && ! $printUrlOnly) {
            $this->ui->infoLine($portHint);
        }

        $healthPathResolved = '/';
        if ($healthPath !== null && trim((string) $healthPath) !== '') {
            $healthPathResolved = '/'.ltrim(trim((string) $healthPath), '/');
        } else {
            $envPath = getenv('JETTY_SHARE_HEALTH_PATH');
            if (is_string($envPath) && trim($envPath) !== '') {
                $healthPathResolved = '/'.ltrim(trim($envPath), '/');
            }
        }

        $upstreamHostPolicy = ShareUpstreamHostPolicy::fromEnvironment();
        if (! $upstreamHostPolicy->allows($localHost)) {
            throw new \InvalidArgumentException(
                $upstreamHostPolicy->denyMessage($localHost).
                    "\n".
                    $this->shareUsageHelp(),
            );
        }

        $this->shareProbeUpstreamHealth(
            $localHost,
            $port,
            $healthPathResolved,
            $noHealthCheck,
        );

        $data = null;
        $id = 0;
        $publicUrl = '';
        $resumedTunnel = false;
        $tunnelLock = null;

        try {
            $resumeId = null;
            $allowResume =
                ! $noResume && getenv('JETTY_SHARE_NO_RESUME') !== '1';
            if ($allowResume) {
                try {
                    $listed = $client->listTunnels();
                    $hit = $this->shareFindResumableTunnel(
                        $listed,
                        $localHost,
                        $port,
                        $subdomain,
                        $tunnelServer,
                    );
                    if ($hit !== null && (int) ($hit['id'] ?? 0) > 0) {
                        $resumeId = (int) $hit['id'];
                    }
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'resume: list '.
                                count($listed).
                                ' tunnel(s); match='.
                                ($resumeId !== null
                                    ? (string) $resumeId
                                    : 'none'),
                        );
                    }
                } catch (\Throwable $e) {
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'resume: list tunnels failed: '.$e->getMessage(),
                        );
                    }
                }
            }

            if ($shareVerbose) {
                if ($resumeId !== null) {
                    $this->shareVerboseLog(
                        true,
                        'POST /api/tunnels/'.
                            $resumeId.
                            '/attach body: '.
                            json_encode(
                                [
                                    'local_host' => $localHost,
                                    'local_port' => $port,
                                    'server' => $tunnelServer !== null &&
                                        trim((string) $tunnelServer) !== ''
                                            ? trim((string) $tunnelServer)
                                            : null,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                    );
                } else {
                    $this->shareVerboseLog(
                        true,
                        'POST /api/tunnels body: '.
                            json_encode(
                                [
                                    'local_host' => $localHost,
                                    'local_port' => $port,
                                    'subdomain' => $subdomain,
                                    'server' => $tunnelServer,
                                ],
                                JSON_THROW_ON_ERROR,
                            ),
                    );
                }
            }

            if ($resumeId !== null) {
                try {
                    $data = $client->attachTunnel(
                        $resumeId,
                        $localHost,
                        $port,
                        $tunnelServer,
                    );
                    $resumedTunnel = true;
                } catch (\RuntimeException $e) {
                    if (
                        preg_match("/HTTP (404|405)\b/", $e->getMessage()) !== 1
                    ) {
                        throw $e;
                    }
                    if (! $printUrlOnly) {
                        $this->stderr(
                            'Note: tunnel resume is not available on this Bridge; registering a new tunnel instead.',
                        );
                    }
                    $data = $client->createTunnel(
                        $localHost,
                        $port,
                        $subdomain,
                        $tunnelServer,
                        $routingRules !== [] ? $routingRules : null,
                        $expiresInSeconds,
                    );
                }
            } else {
                $data = $client->createTunnel(
                    $localHost,
                    $port,
                    $subdomain,
                    $tunnelServer,
                );
            }

            if ($shareVerbose) {
                $this->shareVerboseLog(
                    true,
                    'tunnel API response (redacted): '.
                        json_encode(
                            $this->shareRedactTunnelResponseForLog($data),
                            JSON_THROW_ON_ERROR,
                        ),
                );
            }

            $publicUrl = (string) ($data['public_url'] ?? '');
            $localTarget = (string) ($data['local_target'] ?? '');
            $id = (string) ($data['id'] ?? '');

            $tunnelLock = new TunnelLock($id);
            $lockStatus = $tunnelLock->check();
            if ($lockStatus['locked'] && ! $forceShare) {
                $u = $this->ui;
                $u->out('');
                $u->out('  '.$u->bold($u->cyan($publicUrl)));
                $u->out('');
                $u->out(
                    '  '.
                        $u->dim(str_pad('Forwarding', 14)).
                        $u->dim($localTarget.' → ').
                        ($publicUrl !== ''
                            ? parse_url($publicUrl, PHP_URL_HOST)
                            : ''),
                );
                $u->out(
                    '  '.$u->dim(str_pad('Tunnel', 14)).$u->dim('#').$id,
                );
                $u->out(
                    '  '.
                        $u->dim(str_pad('PID', 14)).
                        $lockStatus['pid'].
                        ($lockStatus['started'] !== null
                            ? '  '.
                                $u->dim('started '.$lockStatus['started'])
                            : ''),
                );
                $u->out('');

                $msg =
                    'Another `jetty share` process (PID '.
                    $lockStatus['pid'].
                    ') is already connected to this tunnel.';
                $msg .=
                    "\n\nTo fix: kill it (`kill ".
                    $lockStatus['pid'].
                    '`) or use --force to proceed anyway.';
                throw new \RuntimeException($msg);
            }
            if ($lockStatus['stale']) {
                TunnelLock::cleanupStaleLocks();
            }
            $lockResult = $tunnelLock->acquire();
            if (! $lockResult['acquired']) {
                $u = $this->ui;
                $u->out('');
                $u->out('  '.$u->bold($u->cyan($publicUrl)));
                $u->out('');

                $existingPid = $lockResult['existing_pid'] ?? null;
                $msg =
                    'Another `jetty share` process is already connected to tunnel '.
                    $id.
                    '.';
                if ($existingPid !== null) {
                    $msg .= ' (PID '.$existingPid.')';
                    $msg .=
                        "\nTo fix: kill it (`kill ".
                        $existingPid.
                        '`) or use --force to proceed anyway.';
                } else {
                    $msg .= "\nUse --force to proceed anyway.";
                }
                throw new \RuntimeException($msg);
            }

            $shareStartedAt = time();
            EdgeAgent::initHttpActivity($shareStartedAt);
            $telegramBase = [
                'tunnel_id' => $id,
                'public_url' => $publicUrl,
                'local_target' => $localTarget,
                'server' => $tunnelServer,
            ];
            $status = (string) ($data['status'] ?? '');
            $subdomain = (string) ($data['subdomain'] ?? '');
            $edge = is_array($data['edge'] ?? null) ? $data['edge'] : [];
            $ws = (string) ($edge['websocket_url'] ?? '');
            $srvOut =
                isset($data['server']) &&
                is_string($data['server']) &&
                $data['server'] !== ''
                    ? $data['server']
                    : null;
            $agentToken = (string) ($data['agent_token'] ?? '');

            if ($printUrlOnly) {
                $this->stdout($publicUrl);
                TelegramNotifier::shareStarted(
                    array_merge($telegramBase, ['print_url_only' => true]),
                );

                return 0;
            }

            $u = $this->ui;

            $curlHost = parse_url($publicUrl, PHP_URL_HOST);
            if (! is_string($curlHost) || $curlHost === '') {
                $suffix =
                    $this->tunnelHostSuffixFromPublicUrl($publicUrl) ??
                    $this->tunnelHostSuffix();
                $curlHost = $subdomain.'.'.$suffix;
            }

            // -- Public URL (hero line) --
            $u->out('');
            $u->out('  '.$u->bold($u->cyan($publicUrl)));
            $u->out('');

            // -- Tunnel details --
            $pairs = [
                [
                    'Forwarding',
                    $u->dim($localTarget.' → ').$u->cyan($curlHost),
                ],
            ];
            if ($srvOut !== null) {
                $pairs[] = ['Server', $srvOut];
            }
            $pairs[] = [
                'Tunnel',
                $u->dim('#').
                (string) $id.
                ($resumedTunnel ? '  '.$u->yellow('resumed') : ''),
            ];
            $invocationCwd = getenv('JETTY_SHARE_INVOCATION_CWD');
            if (is_string($invocationCwd) && $invocationCwd !== '') {
                $pairs[] = ['Project', $u->dim($invocationCwd)];
            }
            foreach ($pairs as [$label, $value]) {
                $u->out('  '.$u->dim(str_pad($label, 14)).$value);
            }
            $u->out('');

            if ($this->shareUpdateCheck()) {
                return 0;
            }
            $this->shareDuplicateInstallCheck();

            TelegramNotifier::shareStarted($telegramBase);

            // CI/CD: auto-detect PR number and notify Bridge
            $ciPrNumber = $this->detectCiPrNumber();
            if ($ciPrNumber !== null) {
                $client->notifyCiCd($id, 'tunnel_started', ['pr_number' => $ciPrNumber]);
            }

            try {
                $client->heartbeat($id);
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'initial heartbeat OK for tunnel id '.$id,
                    );
                }
            } catch (\Throwable $e) {
                $this->ui->warnLine('initial heartbeat: '.$e->getMessage());
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'initial heartbeat exception: '.$e->getMessage(),
                    );
                }
            }

            $edgeOutcome = null;
            $edgeFailDetail = null;
            $shareRewriteOptions = $this->shareTunnelRewriteOptionsFromCli(
                $noBodyRewrite,
                $noJsRewrite,
                $noCssRewrite,
            );
            $agentDebug = $shareDebugAgent
                ? EdgeAgentDebug::stderrJsonSink([
                    'tunnel_id' => $id,
                    'local_upstream' => $localHost.':'.$port,
                    'public_tunnel_host' => $curlHost,
                ])
                : null;
            if ($shareDebugAgent) {
                $this->stderr(
                    "Agent debug: structured JSON lines on stderr (prefix [jetty:agent-debug]).\n",
                );
            }
            $ndjsonFile = getenv('JETTY_SHARE_DEBUG_NDJSON_FILE');
            if (
                getenv('JETTY_SHARE_DEBUG_REWRITE') === '1' &&
                (! is_string($ndjsonFile) || trim($ndjsonFile) === '')
            ) {
                $this->stderr(
                    "[jetty share] JETTY_SHARE_DEBUG_REWRITE=1 but JETTY_SHARE_DEBUG_NDJSON_FILE is unset — file NDJSON is disabled.\n".
                        '  export JETTY_SHARE_DEBUG_NDJSON_FILE="/abs/path/debug.ndjson"  (each line: {"event","ts_ms","rewrite_debug_rev","data"}; rev '.
                        TunnelResponseRewriter::REWRITE_DEBUG_REV.
                        ").\n".
                        "  If your log still shows top-level sessionId/hypothesisId (no rewrite_debug_rev), rebuild the PHAR or run php bin/jetty from jetty-client.\n",
                );
            }
            if (is_string($ndjsonFile) && trim($ndjsonFile) !== '') {
                TunnelResponseRewriter::emitDebugNdjson(
                    'jetty.share.ndjson_sink_ready',
                    [
                        'tunnel_id' => $id,
                        'local_upstream' => $localHost.':'.$port,
                        'public_tunnel_host' => $curlHost,
                        'edge_agent_will_run' => ! $skipEdge && $ws !== '' && $agentToken !== '',
                    ],
                );
            }
            $rewriteHostProbe = TunnelResponseRewriter::tunnelRewriteHostLookup(
                $localHost,
            );
            $rewriteHostProbeCount = count($rewriteHostProbe);
            if (
                $rewriteHostProbeCount <= 2 &&
                filter_var($localHost, FILTER_VALIDATE_IP)
            ) {
                $this->ui->warnLine(
                    'Tunnel redirect rewrite only knows '.
                        $rewriteHostProbeCount.
                        ' host(s) for upstream '.
                        $localHost.
                        '. '.
                        'If the browser jumps off the tunnel, run `jetty share` from this source tree (`php bin/jetty` or rebuild your PHAR), '.
                        'or `cd` into your Laravel app, set JETTY_SHARE_PROJECT_ROOT, or use --site=your-site.test.',
                );
            }
            $hotFile = TunnelResponseRewriter::detectViteHotFile();
            if ($hotFile !== null) {
                $hotContents = @file_get_contents($hotFile);
                $hotUrl = is_string($hotContents) ? trim($hotContents) : '';
                $hotProjectDir = dirname(dirname($hotFile)); // public/hot → project root
                $this->ui->warnLine(
                    'Vite dev server detected (`public/hot` file exists'.
                        ($hotUrl !== '' ? ': '.$hotUrl : '').
                        '). Assets served by Vite (CSS, JS) run on a separate port that is NOT tunnelled — the page will likely appear blank or unstyled through the tunnel.',
                );
                // Offer to run npm run build automatically.
                $hasPackageJson = is_file(
                    rtrim($hotProjectDir, '/').'/package.json',
                );
                if ($hasPackageJson && ! ($printUrlOnly ?? false)) {
                    $this->stderr(
                        '  Run `npm run build` now to compile assets? [Y/n] ',
                    );
                    $answer = trim((string) fgets(\STDIN));
                    if ($answer === '' || strtolower($answer[0]) === 'y') {
                        $this->stderr('  Building assets…');
                        if (
                            TunnelResponseRewriter::runNpmBuild($hotProjectDir)
                        ) {
                            $this->ui->successLine(
                                'Assets built successfully. The tunnel will serve compiled assets.',
                            );
                        } else {
                            $this->ui->warnLine(
                                'Build failed — the page may still appear blank. Try running `npm run build` manually.',
                            );
                        }
                    } else {
                        $this->stderr(
                            '  Skipped. To fix manually: stop `npm run dev`, run `npm run build`, then reload the tunnel URL.',
                        );
                    }
                } else {
                    $this->stderr(
                        '  To fix: stop `npm run dev`, run `npm run build`, then restart `jetty share`.',
                    );
                }
                $this->stderr('');
            }
            // -- Edge region auto-routing: probe available edges and pick the fastest --
            $selectedEdgeRegionLabel = null;
            if (
                ! $printUrlOnly &&
                ! $skipEdge &&
                $ws !== '' &&
                $agentToken !== ''
            ) {
                try {
                    $edgeRegions = $client->getEdgeRegions();
                    if (count($edgeRegions) > 1) {
                        $fastest = $client->selectFastestEdge($edgeRegions);
                        if ($fastest !== null && ($fastest['url'] ?? '') !== '') {
                            $ws = $fastest['url'];
                            $latencyMs = $fastest['latency_ms'] ?? 0;
                            $regionLocation = $fastest['location'] ?? $fastest['region'] ?? 'unknown';
                            $selectedEdgeRegionLabel = $regionLocation . ' (' . $latencyMs . 'ms)';
                        }
                    }
                } catch (\Throwable $e) {
                    // Fallback: keep the default $ws from the tunnel API response.
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'edge region probe failed, using default: ' . $e->getMessage(),
                        );
                    }
                }
            }

            if (
                ! $printUrlOnly &&
                ! $skipEdge &&
                $ws !== '' &&
                $agentToken !== ''
            ) {
                if ($selectedEdgeRegionLabel !== null) {
                    $this->stderr($this->ui->dim('  Connecting to ' . $selectedEdgeRegionLabel . '...'));
                } else {
                    $this->stderr($this->ui->dim('  Connecting...'));
                }
                try {
                    $edgeOutcome = EdgeAgent::run(
                        $ws,
                        $id,
                        $agentToken,
                        $localHost,
                        $port,
                        $client,
                        $id,
                        fn (string $m) => $this->printEdgeAgentStderr($m),
                        $shareVerbose,
                        $shareRewriteOptions,
                        $curlHost,
                        $agentDebug,
                        function () use (
                            $client,
                            $id,
                            $localHost,
                            $port,
                            $tunnelServer,
                        ): string {
                            $data = $client->attachTunnel(
                                $id,
                                $localHost,
                                $port,
                                $tunnelServer,
                            );
                            $t = (string) ($data['agent_token'] ?? '');
                            if ($t === '') {
                                throw new \RuntimeException(
                                    'attach response missing agent_token',
                                );
                            }

                            return $t;
                        },
                    );
                } catch (\Throwable $e) {
                    $edgeFailDetail = $e->getMessage();
                    $this->ui->errorLine(
                        'edge agent failed: '.$e->getMessage(),
                    );
                    if ($shareVerbose) {
                        $this->ui->verboseLine(
                            '[jetty:share] '.
                                get_class($e).
                                ' in '.
                                $e->getFile().
                                ':'.
                                $e->getLine(),
                        );
                        $this->ui->verboseLine(
                            '[jetty:share] '.$e->getTraceAsString(),
                        );
                    }
                    $this->ui->warnLine(
                        'Continuing with heartbeats only (no HTTP forwarding until you fix edge connectivity).',
                    );
                    $edgeOutcome = EdgeAgentResult::FailedEarly;
                }
            } elseif (! $printUrlOnly && ! $skipEdge && $ws === '') {
                $this->ui->warnLine(
                    'Bridge returned no edge WebSocket URL (JETTY_EDGE_WS_URL). Heartbeats only.',
                );
            } elseif (! $printUrlOnly && ! $skipEdge && $agentToken === '') {
                $this->ui->warnLine(
                    'No agent_token in API response — heartbeats only.',
                );
            }

            $needsHeartbeatLoop = true;
            if ($edgeOutcome === EdgeAgentResult::Finished) {
                $needsHeartbeatLoop = false;
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'edge agent finished (user stop); skipping heartbeat fallback',
                    );
                }
            } elseif ($edgeOutcome === EdgeAgentResult::Disconnected) {
                if ($shareVerbose) {
                    $this->shareVerboseLog(
                        true,
                        'edge WebSocket dropped unexpectedly; falling back to heartbeat loop',
                    );
                }
                $this->stderr(
                    "\nEdge WebSocket disconnected (idle timeout, proxy, or edge restart). Tunnel stays registered; heartbeats continue.\n",
                );
                $this->stderr($this->shareExitTunnelHint($deleteOnExit));
                $needsHeartbeatLoop = true;
            } elseif ($edgeOutcome === EdgeAgentResult::FailedEarly) {
                $this->stderr('');
                $this->stderr(
                    'Edge WebSocket agent did not stay up; falling back to heartbeats only (tunnel stays registered).',
                );
                $this->stderr(
                    'Fix edge connectivity, or use --skip-edge for registration + heartbeats without the agent. '.
                        $this->shareCtrlCHint($deleteOnExit),
                );
                $needsHeartbeatLoop = true;
                TelegramNotifier::edgeAgentFailed(
                    $id,
                    $publicUrl,
                    $edgeFailDetail ??
                        'Edge WebSocket agent exited early (see CLI output).',
                );
            }

            $idleAutoDeleted = false;
            if ($needsHeartbeatLoop) {
                $idleAutoDeleted = $this->runShareHeartbeatLoop(
                    $client,
                    $id,
                    $publicUrl,
                    $shareVerbose,
                    $deleteOnExit,
                    $shareStartedAt,
                );
            }

            if ($idleAutoDeleted) {
                return 0;
            }

            if ($deleteOnExit) {
                TelegramNotifier::shareEnded(
                    $id,
                    $publicUrl,
                    'session ended (CLI deleting tunnel)',
                );

                try {
                    if ($shareVerbose) {
                        $this->shareVerboseLog(
                            true,
                            'DELETE /api/tunnels/'.$id,
                        );
                    }
                    $client->deleteTunnel($id);
                    $this->stderr("Tunnel {$id} deleted.\n");
                } catch (\Throwable $e) {
                    $this->stderr(
                        'warning: could not delete tunnel '.
                            $id.
                            ': '.
                            $e->getMessage(),
                    );
                    TelegramNotifier::tunnelDeleteFailed($id, $e->getMessage());
                    if ($shareVerbose) {
                        $this->stderr(
                            '[jetty:share] delete exception: '.
                                $e->getTraceAsString(),
                        );
                    }
                }
            } else {
                TelegramNotifier::shareEnded(
                    $id,
                    $publicUrl,
                    'session ended (tunnel left registered — remove in app or jetty delete)',
                );
                $this->stderr(
                    "\nTunnel {$id} left registered. Remove it in the Jetty web app, or run: jetty delete {$id}\n",
                );
            }

            return 0;
        } catch (\Throwable $e) {
            TelegramNotifier::shareFailed(
                $data === null ? 'create_tunnel' : 'share',
                $e,
                [
                    'tunnel_id' => $id,
                    'public_url' => $publicUrl,
                ],
            );
            throw $e;
        } finally {
            $tunnelLock?->release();
            $this->shareStopBuiltInServer($builtInServerProc);
        }
    }

    private function shareDefaultServeDocroot(): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            throw new \RuntimeException(
                'Could not determine working directory for --serve.',
            );
        }
        $pub = $cwd.\DIRECTORY_SEPARATOR.'public';
        if (is_dir($pub)) {
            return $pub;
        }

        return $cwd;
    }

    private function shareResolveServeDocroot(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->shareDefaultServeDocroot();
        }
        $real = realpath($path);

        return $real !== false && is_dir($real)
            ? $real
            : throw new \InvalidArgumentException('Not a directory: '.$path);
    }

    private function shareFindFreeTcpPort(): int
    {
        $s = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errno,
            $errstr,
            STREAM_SERVER_BIND,
        );
        if ($s === false) {
            return 8899;
        }
        $name = stream_socket_get_name($s, false);
        fclose($s);
        if (is_string($name) && preg_match('/:(\d+)$/', $name, $m)) {
            return (int) $m[1];
        }

        return 8899;
    }

    /**
     * @return resource|object
     */
    private function shareStartPhpBuiltInServer(string $docroot, int $port)
    {
        $docroot = realpath($docroot) ?: $docroot;
        if (! is_dir($docroot)) {
            throw new \InvalidArgumentException(
                'Document root is not a directory: '.$docroot,
            );
        }

        $php = \PHP_BINARY;
        $cmd = [$php, '-S', '127.0.0.1:'.$port, '-t', $docroot];
        $nullDevice = \PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $proc = @proc_open(
            $cmd,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => \STDERR,
            ],
            $pipes,
            $docroot,
        );
        if (! is_resource($proc)) {
            throw new \RuntimeException(
                'proc_open failed for PHP built-in server',
            );
        }
        usleep(200_000);
        if (! $this->tcpPortAcceptsConnections('127.0.0.1', $port)) {
            proc_close($proc);
            throw new \RuntimeException(
                'PHP built-in server did not listen on 127.0.0.1:'.$port,
            );
        }

        return $proc;
    }

    /**
     * @param  resource|object|null  $proc
     */
    private function shareStopBuiltInServer(mixed $proc): void
    {
        if ($proc === null) {
            return;
        }
        if (is_resource($proc)) {
            @proc_terminate($proc);
            @proc_close($proc);
            $this->stderr('Stopped PHP built-in server.');
        }
    }

    /**
     * Auto-detect the pull/merge request number from CI environment variables.
     */
    private function detectCiPrNumber(): ?int
    {
        // GitHub Actions
        $pr = getenv('GITHUB_PR_NUMBER');
        if ($pr !== false && is_numeric($pr) && (int) $pr > 0) {
            return (int) $pr;
        }

        // GitLab CI
        $mr = getenv('CI_MERGE_REQUEST_IID');
        if ($mr !== false && is_numeric($mr) && (int) $mr > 0) {
            return (int) $mr;
        }

        return null;
    }

    private function shareVerboseLog(bool $verbose, string $message): void
    {
        if ($verbose) {
            $this->ui->verboseLine('[jetty:share] '.$message);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function shareRedactTunnelResponseForLog(array $data): array
    {
        $out = $data;
        if (isset($out['agent_token']) && is_string($out['agent_token'])) {
            $out['agent_token'] =
                '(length '.strlen($out['agent_token']).')';
        }

        return $out;
    }

    /**
     * @return bool True if the tunnel was removed automatically (idle policy); caller should not delete again.
     */
    private function runShareHeartbeatLoop(
        ApiClient $client,
        string $tunnelId,
        string $publicUrl,
        bool $verbose,
        bool $deleteOnExit,
        int $shareStartedAt,
    ): bool {
        $idleCfg = ShareIdleConfig::fromEnvironment();
        $idleDisabled = $idleCfg->disabled;
        $promptAfter = $idleCfg->promptMinutes;
        $graceMin = $idleCfg->graceMinutes;

        if ($deleteOnExit) {
            $this->stderr(
                "\nSending heartbeats every 25s. Ctrl+C to delete this tunnel and exit.\n",
            );
        } else {
            $this->stderr(
                "\nSending heartbeats every 25s. Ctrl+C to stop this session (tunnel stays registered until you remove it in the app or run `jetty delete {$tunnelId}`).\n",
            );
        }
        if (! $idleDisabled) {
            $this->stderr(
                "Long idle: after {$promptAfter} minutes without HTTP traffic you will be prompted; if there is still no traffic and you do not type `keep` within {$graceMin} minutes, this tunnel is removed automatically (JETTY_SHARE_IDLE_DISABLE=1 to turn off).\n",
            );
        }
        if ($verbose) {
            $this->shareVerboseLog(
                true,
                'heartbeat loop start (tunnel id '.$tunnelId.')',
            );
        }

        $stop = false;
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
            $handler = function () use (&$stop, $verbose): void {
                $stop = true;
                if ($verbose) {
                    fwrite(
                        \STDERR,
                        "[jetty:share] caught signal; stopping heartbeat loop\n",
                    );
                }
            };
            \pcntl_signal(\SIGINT, $handler);
            \pcntl_signal(\SIGTERM, $handler);
        } else {
            $this->stderr(
                "\n(ext-pcntl not loaded — Ctrl+C handling may vary by platform.)\n",
            );
        }

        $confirmDeadline = null;
        $idleBaselineAtPrompt = null;
        $stdinBuf = '';

        $nextBeat = time() + 25;
        $beatNum = 0;
        while (! $stop) {
            if (\function_exists('pcntl_signal_dispatch')) {
                \pcntl_signal_dispatch();
            }
            if ($stop) {
                break;
            }
            $now = time();
            if ($now >= $nextBeat) {
                try {
                    $client->heartbeat($tunnelId);
                    $beatNum++;
                    if ($verbose) {
                        $this->shareVerboseLog(
                            true,
                            'heartbeat #'.$beatNum.' OK',
                        );
                    }
                } catch (\Throwable $e) {
                    $this->stderr('heartbeat failed: '.$e->getMessage());
                    if ($verbose) {
                        $this->shareVerboseLog(
                            true,
                            'heartbeat exception: '.
                                get_class($e).
                                ' '.
                                $e->getMessage(),
                        );
                    }
                }
                $nextBeat = $now + 25;
            }

            if (! $idleDisabled) {
                $lastAct = EdgeAgent::lastHttpActivityUnix() ?? $shareStartedAt;

                if ($confirmDeadline !== null) {
                    if (
                        $idleBaselineAtPrompt !== null &&
                        $lastAct > $idleBaselineAtPrompt
                    ) {
                        $this->stderr(
                            "HTTP activity detected; idle warning cleared.\n",
                        );
                        $confirmDeadline = null;
                        $idleBaselineAtPrompt = null;
                    } elseif ($this->shareStdinHasKeep($stdinBuf)) {
                        $this->stderr(
                            "Keeping tunnel registered; idle timer reset.\n",
                        );
                        EdgeAgent::markHttpActivity();
                        $confirmDeadline = null;
                        $idleBaselineAtPrompt = null;
                    } elseif (time() >= $confirmDeadline) {
                        try {
                            $client->deleteTunnel($tunnelId);
                            $this->stderr(
                                "\nTunnel {$tunnelId} removed automatically (no HTTP traffic and no `keep` within {$graceMin} minutes).\n",
                            );
                            TelegramNotifier::shareEnded(
                                $tunnelId,
                                $publicUrl,
                                'idle timeout (tunnel removed by CLI policy)',
                            );
                        } catch (\Throwable $e) {
                            $this->stderr(
                                'warning: could not remove idle tunnel '.
                                    $tunnelId.
                                    ': '.
                                    $e->getMessage(),
                            );
                            TelegramNotifier::tunnelDeleteFailed(
                                $tunnelId,
                                $e->getMessage(),
                            );
                        }
                        $stop = true;

                        return true;
                    }
                }

                $idleSec = time() - $lastAct;
                if (
                    $confirmDeadline === null &&
                    $idleSec >= $promptAfter * 60
                ) {
                    $this->stderr(
                        $this->shareIdlePromptMessage(
                            $tunnelId,
                            $publicUrl,
                            $promptAfter,
                            $graceMin,
                        ),
                    );
                    $idleBaselineAtPrompt = $lastAct;
                    $confirmDeadline = time() + $graceMin * 60;
                }
            }

            usleep(200_000);
        }
        if ($verbose) {
            $this->shareVerboseLog(true, 'heartbeat loop end');
        }

        return false;
    }

    /**
     * Non-blocking stdin read; returns true if the user typed keep / y / yes on a line.
     */
    private function shareStdinHasKeep(string &$buffer): bool
    {
        if (! \is_resource(STDIN)) {
            return false;
        }
        if (\function_exists('posix_isatty') && ! posix_isatty(STDIN)) {
            return false;
        }
        stream_set_blocking(STDIN, false);
        $chunk = fread(STDIN, 4096);
        stream_set_blocking(STDIN, true);
        if ($chunk === false || $chunk === '') {
            return false;
        }

        // Check for single-key view switches before buffering lines.
        if ($this->trafficView !== null) {
            $u = $this->ui;
            $tv = $this->shareTrafficView();
            $filtered = '';
            for ($i = 0, $len = strlen($chunk); $i < $len; $i++) {
                $key = $chunk[$i];
                if ($key !== "\n" && $key !== "\r" && $tv->handleKey($key)) {
                    $u->err('');
                    $u->err($tv->statusLine($u));
                    $u->err('');
                } else {
                    $filtered .= $key;
                }
            }
            $chunk = $filtered;
        }

        $buffer .= $chunk;
        while (($pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $pos);
            $buffer = (string) substr($buffer, $pos + 1);
            $t = strtolower(trim($line));
            if ($t === 'keep' || $t === 'y' || $t === 'yes') {
                return true;
            }
        }

        return false;
    }

    private function shareIdlePromptMessage(
        string $tunnelId,
        string $publicUrl,
        int $promptAfterMinutes,
        int $graceMinutes,
    ): string {
        $urlLine = $publicUrl !== '' ? "Public URL: {$publicUrl}\n" : '';

        return "\nNo HTTP traffic for {$promptAfterMinutes} minutes through this tunnel.\n".
            $urlLine.
            "Keep tunnel {$tunnelId} registered? Type keep (or y) and press Enter within {$graceMinutes} minutes, ".
            "or send a request to the URL above. Otherwise this tunnel will be removed automatically.\n\n";
    }

    private function shareCtrlCHint(bool $deleteOnExit): string
    {
        if ($deleteOnExit) {
            return 'Ctrl+C to exit and delete the tunnel.';
        }

        return 'Ctrl+C to stop this session (tunnel stays registered).';
    }

    private function shareExitTunnelHint(bool $deleteOnExit): string
    {
        $tail = $deleteOnExit
            ? 'Press Ctrl+C to exit and delete the tunnel.'
            : 'Press Ctrl+C to stop this session (tunnel stays registered until you remove it in the web app).';

        return 'HTTP via the public URL will not reach your app until you run `jetty share` again or the agent reconnects. '.
            $tail.
            "\n";
    }

    /**
     * @param  non-empty-string|null  $slot
     */
    private function shareMaybeSetCliHostnameForRewrite(
        ?string &$slot,
        string $candidate,
    ): void {
        $c = strtolower(trim($candidate));
        if ($c === '' || filter_var($c, FILTER_VALIDATE_IP)) {
            return;
        }
        $slot = $c;
    }

    private function parseShareUpstreamValue(
        string $arg,
        string $prefix,
    ): string {
        $v = substr($arg, strlen($prefix));
        if (trim($v) === '') {
            $hint =
                $prefix === '--host='
                    ? 'use --site= for your local hostname or IP (e.g. my-app.test)'
                    : $prefix.' requires a hostname or IP';

            throw new \InvalidArgumentException($hint);
        }

        return $v;
    }

    private function assertTunnelServerLabel(string $label): void
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $label) !== 1) {
            throw new \InvalidArgumentException(
                'Invalid --server value "'.
                    $label.
                    '": use a tunnel id (letters/digits with optional . _ -), e.g. us-west-1.',
            );
        }
    }

    /**
     * Show an update-available notice in the tunnel info block (if a newer version exists).
     *
     * @return bool True if the process should exit (update was applied).
     */
    private function shareUpdateCheck(): bool
    {
        $u = $this->ui;
        $current = ApiClient::VERSION;
        $update = new UpdateCommand($u, $this->config);
        $help = new HelpRenderer($u);

        if (UpdateConfig::isNoticeSkipped()) {
            $u->out('  '.$u->dim(str_pad('Version', 14)).'v'.$current);

            return false;
        }

        $pharPath = \Phar::running(false);
        $canCheck = true;
        if ($pharPath !== '' && $update->localPharUpdateUrl() !== null) {
            $canCheck = false;
        }
        if (
            $pharPath === '' &&
            (! class_exists(InstalledVersions::class) ||
                ! InstalledVersions::isInstalled('jetty/client'))
        ) {
            $canCheck = false;
        }

        $latestTag = null;
        $updateAvailable = false;

        if ($canCheck) {
            $cachePath = $help->jettyUpdateNoticeCachePath();
            if ($cachePath !== '') {
                $now = time();
                $cache = $help->readUpdateNoticeCache($cachePath);
                $needRefresh =
                    $cache === null ||
                    $now - (int) ($cache['checked_at'] ?? 0) >= 86400;

                // Invalidate when the cached remote version is no longer
                // newer than the running version (e.g. user upgraded).
                if (
                    ! $needRefresh &&
                    $cache !== null &&
                    ! empty($cache['update_available'])
                ) {
                    $cachedRemote = (string) ($cache['remote_semver'] ?? '');
                    if (
                        $cachedRemote !== '' &&
                        version_compare($cachedRemote, $current) <= 0
                    ) {
                        $needRefresh = true;
                    }
                }

                if ($needRefresh) {
                    try {
                        $repo = $update->releasesRepo();
                        $token = $update->githubTokenForReleases();
                        $latest = GitHubPharRelease::latest($repo, $token);
                        if ($latest !== null) {
                            $remoteSemver = GitHubPharRelease::tagToSemver(
                                $latest['tag_name'],
                            );
                            $cmp = version_compare($remoteSemver, $current);
                            $cache = [
                                'checked_at' => $now,
                                'remote_tag' => $latest['tag_name'],
                                'remote_semver' => $remoteSemver,
                                'update_available' => $cmp > 0,
                                'last_notice_at' => (int) ($cache['last_notice_at'] ?? 0),
                                'last_notified_tag' => (string) ($cache['last_notified_tag'] ??
                                        ''),
                            ];
                            $help->writeUpdateNoticeCache($cachePath, $cache);
                        }
                    } catch (\Throwable) {
                        // Network error — show version without latest info.
                    }
                }

                if ($cache !== null) {
                    $latestTag = (string) ($cache['remote_tag'] ?? '');
                    $updateAvailable =
                        (bool) ($cache['update_available'] ?? false);
                }
            }
        }

        if ($updateAvailable && $latestTag !== '') {
            $latestSemver = GitHubPharRelease::tagToSemver($latestTag);
            $u->out(
                '  '.
                    $u->dim(str_pad('Version', 14)).
                    'v'.
                    $current.
                    '  '.
                    $u->yellow('update available: v'.$latestSemver),
            );
            $u->out('');

            $canSelfUpdate =
                $pharPath !== '' ||
                (class_exists(InstalledVersions::class) &&
                    InstalledVersions::isInstalled('jetty/client'));
            if ($canSelfUpdate) {
                $u->errRaw('  Update now? [Y/n] ');
                $answer = trim((string) fgets(\STDIN));
                if ($answer === '' || strtolower($answer[0]) === 'y') {
                    $u->err('  Updating…');
                    try {
                        if ($pharPath !== '') {
                            $update->updatePharInPlace([], $pharPath);
                        } else {
                            $update->updateComposerJettyClient([]);
                        }
                        $u->successLine(
                            'Updated to v'.
                                $latestSemver.
                                '. Run `jetty share` again to use the new version.',
                        );

                        return true;
                    } catch (\Throwable $e) {
                        $u->warnLine('Update failed: '.$e->getMessage());
                        $u->err('  Continuing with v'.$current.'…');
                    }
                }
            }
        } elseif ($latestTag !== '') {
            $u->out(
                '  '.
                    $u->dim(str_pad('Version', 14)).
                    'v'.
                    $current.
                    '  '.
                    $u->green('latest'),
            );
        } else {
            $u->out('  '.$u->dim(str_pad('Version', 14)).'v'.$current);
        }

        return false;
    }

    /**
     * Warn once during `jetty share` if multiple jetty binaries are found on the system.
     */
    private function shareDuplicateInstallCheck(): void
    {
        $doctor = new DoctorCommand($this->ui, $this->client, $this->config);
        $installs = $doctor->findAllJettyInstalls();
        // Filter out project wrappers — those are expected in dev repos.
        $real = array_filter(
            $installs,
            fn ($i) => $i['type'] !== 'project-wrapper',
        );
        if (count($real) <= 1) {
            return;
        }

        $u = $this->ui;
        $paths = array_map(
            fn ($i) => $i['path'].
                ' ('.
                $i['type'].
                ($i['version'] !== '' ? ', v'.$i['version'] : '').
                ')',
            $real,
        );
        $u->warnLine(
            'Multiple jetty installs detected — this can cause version mismatches. Run '.
                $u->cmd('jetty doctor').
                ' to clean up.',
        );
        foreach ($paths as $p) {
            $u->err('    '.$u->dim($p));
        }
    }

    /**
     * CLI flags override environment for this `jetty share` run. Returns null when no CLI overrides (EdgeAgent uses env only).
     */
    private function shareTunnelRewriteOptionsFromCli(
        bool $noBody,
        bool $noJs,
        bool $noCss,
    ): ?TunnelRewriteOptions {
        if (! $noBody && ! $noJs && ! $noCss) {
            return null;
        }

        $base = TunnelRewriteOptions::fromEnvironment();
        if ($noBody) {
            return new TunnelRewriteOptions(
                false,
                false,
                false,
                $base->maxBodyBytes,
            );
        }

        return new TunnelRewriteOptions(
            $base->bodyRewrite,
            $noJs ? false : $base->jsRewrite,
            $noCss ? false : $base->cssRewrite,
            $base->maxBodyBytes,
        );
    }

    private function shareUsageSummary(): string
    {
        return 'Usage: jetty share [port] [--host=127.0.0.1] [--server=us-west-1] [--site=HOST] [--subdomain=label] [--print-url-only] [--skip-edge] [--serve[=DIR]] [--no-detect] [--no-resume] [--force|-f] [--health-path=PATH] [--no-health-check] [--delete-on-exit] [--no-body-rewrite] [--no-js-rewrite] [--no-css-rewrite] [--verbose|-v|--errors] [--debug-agent] (alias: http)';
    }

    private function shareUsageHelp(): string
    {
        return $this->shareUsageSummary().
            <<<'TXT'


              port  Optional. If omitted: auto-detect upstream from cwd (Laravel APP_URL, Herd/Valet links, DDEV, Docker Compose, Vite/Next/etc., or .env PORT), else scan common dev ports on 127.0.0.1. With --site=HOSTNAME (not 127.0.0.1), tries :443 then :80 when open (Valet/Herd TLS first — avoids HTTP→HTTPS redirect loops); pass an explicit port (e.g. 8000) for php artisan serve only.
              --host= / --site= / --bind= / --local= / --local-host=  Upstream hostname or IP (default 127.0.0.1).
              --serve[=DIR]  Start PHP's built-in server (default docroot: ./public if present, else cwd) and tunnel to it; optional port as first arg.
              --no-detect    Skip local-dev auto-detection (use plain 127.0.0.1 + port scan). Env: JETTY_SHARE_NO_DETECT=1
              --no-resume    Always register a new tunnel (skip GET /api/tunnels + attach). Env: JETTY_SHARE_NO_RESUME=1
              --force / -f   Proceed even if another `jetty share` is already running for this tunnel (not recommended — causes edge conflicts).
              --health-path=PATH  Upstream probe path (default /). Env: JETTY_SHARE_HEALTH_PATH
              --no-health-check   Skip GET probe to local upstream before creating the tunnel. Env: JETTY_SHARE_NO_HEALTH_CHECK=1
              --skip-edge  Register + heartbeats only; no WebSocket forwarding agent.
              --no-body-rewrite  Disable tunnel URL rewriting of response bodies (HTML/CSS/JS). Env: JETTY_SHARE_NO_BODY_REWRITE=1
              --no-js-rewrite  Disable rewriting quoted URLs inside inline/standalone JavaScript only. Env: JETTY_SHARE_NO_JS_REWRITE=1
              --no-css-rewrite  Disable rewriting url() inside CSS (inline style + <style>). Env: JETTY_SHARE_NO_CSS_REWRITE=1
              --delete-on-exit  Exit: call DELETE /api/tunnels (default: leave tunnel registered — remove in the web app or `jetty delete`). Env: JETTY_SHARE_DELETE_ON_EXIT=1
              --no-delete-on-exit  Force no delete on exit (overrides env).
              --verbose / -v / --errors  Log connection steps, heartbeats, and edge WebSocket frames (stderr).
              --debug-agent  Structured agent diagnostics as JSON lines on stderr (prefix [jetty:agent-debug]); also JETTY_SHARE_DEBUG_AGENT=1 or jetty.config.json share.debug_agent. Heartbeat lines: JETTY_SHARE_DEBUG_AGENT_HEARTBEATS=1.

            TXT;
    }

    /**
     * Valet/Herd-style hostnames (non-loopback, non-IP): prefer :443 then :80 before scanning common
     * dev ports, so TLS matches secured local sites and avoids HTTP→HTTPS redirect loops in tunnels.
     */
    private static function shareUpstreamHostPrefersStandardWebPort(
        string $localHost,
    ): bool {
        $h = strtolower(trim($localHost));
        if ($h === '' || $h === 'localhost' || $h === '::1') {
            return false;
        }

        return filter_var($h, FILTER_VALIDATE_IP) === false;
    }

    /**
     * @return array{0: int, 1: string|null} port and optional stderr hint when port was inferred
     */
    private function resolveSharePort(string $localHost, ?int $explicit): array
    {
        if ($explicit !== null) {
            if ($explicit < 1 || $explicit > 65535) {
                throw new \InvalidArgumentException(
                    'port must be between 1 and 65535',
                );
            }

            return [$explicit, null];
        }

        $common = [8000, 3000, 5173, 8080, 5000, 4000, 8765, 8888];
        if (self::shareUpstreamHostPrefersStandardWebPort($localHost)) {
            // Prefer :443 first: Valet/Herd often redirect http://site → https://site; tunneling :80
            // yields 301→tunnel URL→301 loops. HTTPS upstream avoids that.
            $common = array_merge([443, 80], $common);
        }

        foreach ($common as $p) {
            if (! $this->tcpPortAcceptsConnections($localHost, $p)) {
                continue;
            }
            if (
                $p === 443 &&
                self::shareUpstreamHostPrefersStandardWebPort($localHost)
            ) {
                return [
                    $p,
                    'Local upstream: '.
                    $localHost.
                    ':443 (auto — TLS preferred to avoid redirect loops; override with e.g. `jetty share 80`).',
                ];
            }
            if (
                $p === 80 &&
                self::shareUpstreamHostPrefersStandardWebPort($localHost)
            ) {
                return [
                    $p,
                    'Local upstream: '.
                    $localHost.
                    ':80 (auto — :443 not available; Valet/Herd may redirect, ensure :443 is listening).',
                ];
            }

            return [
                $p,
                'Local upstream: '.
                $localHost.
                ':'.
                $p.
                ' (auto-detected).',
            ];
        }

        return [
            8000,
            'Local upstream: '.
            $localHost.
            ':8000 (auto — no dev port responded; pass a port if yours is different).',
        ];
    }

    private function tcpPortAcceptsConnections(
        string $host,
        int $port,
        float $timeoutSeconds = 0.2,
    ): bool {
        $host = trim($host);
        if ($host === '') {
            return false;
        }
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if (\is_resource($fp)) {
            fclose($fp);

            return true;
        }

        return false;
    }

    /**
     * GET local upstream before registering the tunnel so misconfiguration fails fast.
     *
     * @param  non-empty-string  $path  Path beginning with /
     */
    private function shareProbeUpstreamHealth(
        string $localHost,
        int $port,
        string $path,
        bool $noHealthCheck,
    ): void {
        if ($noHealthCheck || getenv('JETTY_SHARE_NO_HEALTH_CHECK') === '1') {
            return;
        }

        $url = LocalUpstreamUrl::baseForCurl($localHost, $port).$path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException(
                'upstream health check: curl_init failed for '.$url,
            );
        }

        $connectT = EdgeAgent::upstreamConnectTimeoutSeconds();
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => max(15, $connectT + 5),
            CURLOPT_CONNECTTIMEOUT => $connectT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_HTTPGET => true,
        ];

        // For local HTTPS (port 443), skip SSL verification — Valet/Herd/local dev certs are self-signed.
        if ($port === 443) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException(
                'upstream health check failed: could not reach '.
                    $url.
                    ' ('.
                    ($err !== '' ? $err : 'curl error '.$errno).
                    '). Fix your local server or pass --no-health-check.',
            );
        }

        if ($status === 0) {
            throw new \RuntimeException(
                'upstream health check failed: no HTTP response from '.
                    $url.
                    '. Fix your local server or pass --no-health-check.',
            );
        }

        if ($status >= 500) {
            throw new \RuntimeException(
                'upstream health check failed: '.
                    $url.
                    ' returned HTTP '.
                    $status.
                    '. Fix your app or pass --no-health-check.',
            );
        }
    }

    /**
     * Pick an existing API tunnel row to reconnect when local target + edge server + optional subdomain match.
     * Valet-style hostnames also resume across {@code :80} and {@code :443} for the same host.
     *
     * @param  list<array<string, mixed>>  $tunnels
     * @return array<string, mixed>|null
     */
    private function shareFindResumableTunnel(
        array $tunnels,
        string $localHost,
        int $port,
        ?string $subdomain,
        ?string $tunnelServer,
    ): ?array {
        return TunnelResumeMatcher::findResumableTunnel(
            $tunnels,
            $localHost,
            $port,
            $subdomain,
            $tunnelServer,
            self::shareUpstreamHostPrefersStandardWebPort($localHost),
        );
    }

    /**
     * Prefer the suffix from Bridge's public_url so the curl hint matches the API even when
     * the shell still has JETTY_TUNNEL_HOST=tunnel... from an old export.
     *
     * @return non-empty-string|null
     */
    private function tunnelHostSuffixFromPublicUrl(string $publicUrl): ?string
    {
        $publicUrl = trim($publicUrl);
        if ($publicUrl === '') {
            return null;
        }
        $parts = parse_url($publicUrl);
        if (
            ! is_array($parts) ||
            empty($parts['host']) ||
            ! is_string($parts['host'])
        ) {
            return null;
        }
        $host = $parts['host'];
        $dot = strpos($host, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }

        $suffix = substr($host, $dot + 1);

        return $suffix !== '' ? $suffix : null;
    }

    private function tunnelHostSuffix(): string
    {
        $v = getenv('JETTY_PUBLIC_TUNNEL_HOST');
        if (is_string($v) && $v !== '') {
            return $v;
        }
        $v = getenv('JETTY_TUNNEL_HOST');
        if (is_string($v) && $v !== '') {
            return $v;
        }

        return 'tunnels.usejetty.online';
    }

    // -- Traffic view methods --

    private function shareTrafficView(): ShareTrafficView
    {
        return $this->trafficView ??= new ShareTrafficView;
    }

    private function printTrafficViewHint(): void
    {
        $u = $this->ui;
        $u->err('');
        $u->err(
            $u->dim('  Views: [a]ll  [p]ages  [s]tatic  [j]son/api  [e]rrors'),
        );
    }

    /**
     * Check stdin for view-switching keystrokes (non-blocking).
     * Called from the share idle loop.
     */
    private function shareCheckViewSwitch(): void
    {
        if ($this->trafficView === null) {
            return;
        }
        if (! \is_resource(STDIN)) {
            return;
        }

        stream_set_blocking(STDIN, false);
        $chunk = @fread(STDIN, 64);
        stream_set_blocking(STDIN, true);

        if ($chunk === false || $chunk === '') {
            return;
        }

        $u = $this->ui;
        $tv = $this->shareTrafficView();

        for ($i = 0, $len = strlen($chunk); $i < $len; $i++) {
            $key = $chunk[$i];
            if ($key === "\n" || $key === "\r") {
                continue;
            }
            if ($tv->handleKey($key)) {
                $u->err('');
                $u->err($tv->statusLine($u));
                $u->err('');
            }
        }
    }

    private function printEdgeAgentStderr(string $m): void
    {
        $u = $this->ui;
        if (str_starts_with($m, 'Edge agent connected')) {
            $u->successLine('Connected — forwarding HTTP to local upstream.');
            $this->printTrafficViewHint();

            return;
        }
        if (str_starts_with($m, 'Edge agent reconnected')) {
            $u->successLine('Reconnected — forwarding HTTP to local upstream.');

            return;
        }
        if (str_starts_with($m, 'edge: reconnecting')) {
            $u->err('  '.$u->yellow('↻ '.trim($m)));

            return;
        }
        if (str_starts_with($m, 'edge: WebSocket dropped')) {
            $u->err('  '.$u->dim($m));

            return;
        }
        // Traffic log lines: "[category]  METHOD /path STATUS SIZE ELAPSED"
        if (
            preg_match(
                "/^\[(\w+)]\s+(GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s+(\S+)\s+(\d{3})\s/",
                $m,
                $match,
            )
        ) {
            $category = $match[1];
            $status = (int) $match[4];

            $tv = $this->shareTrafficView();
            $tv->record($category);

            if (! $tv->shouldShow($category)) {
                return;
            }

            // Strip the category prefix for display, add the category icon
            $display =
                '  '.
                $tv->categoryTag($category).
                ' '.
                substr(
                    $m,
                    strlen($match[0]) -
                        strlen(
                            $match[2].' '.$match[3].' '.$match[4].' ',
                        ),
                );
            // Rebuild: "  ● GET  /path 200 12.3kB 45ms"
            $line = preg_replace(
                "/^\[\w+]\s+/",
                '  '.$tv->categoryTag($category).' ',
                $m,
            );

            if ($status >= 500) {
                $u->err($u->red($line));
            } elseif ($status >= 400) {
                $u->err($u->yellow($line));
            } elseif ($status >= 300) {
                $u->err($u->cyan($line));
            } elseif ($category === 'asset') {
                $u->err($u->dim($line));
            } else {
                $u->err($line);
            }

            return;
        }
        $u->err($m);
    }

    // -- Static helpers --

    /**
     * Parse a human-friendly duration string into seconds.
     * Supports: 30m, 1h, 2h30m, 3600 (plain seconds), 1d.
     */
    private static function parseDurationToSeconds(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Plain integer = seconds.
        if (ctype_digit($raw)) {
            return (int) $raw;
        }

        $total = 0;
        if (preg_match_all('/(\d+)\s*(d|h|m|s)/i', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $val = (int) $m[1];
                $total += match (strtolower($m[2])) {
                    'd' => $val * 86400,
                    'h' => $val * 3600,
                    'm' => $val * 60,
                    's' => $val,
                    default => 0,
                };
            }
        }

        return $total > 0 ? $total : null;
    }

    /**
     * Parse --route=/api=8080 or --route=/api=api-server:8080 into a routing rule.
     *
     * @return array{match_type: string, path_prefix: string, local_host: string, local_port: int, enabled: bool}|null
     */
    private static function parseRouteFlag(string $spec): ?array
    {
        // Format: /path=port or /path=host:port
        $parts = explode('=', $spec, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        $pathPrefix = $parts[0];
        $target = $parts[1];

        if (str_contains($target, ':')) {
            $hostPort = explode(':', $target, 2);
            $host = $hostPort[0];
            $port = (int) $hostPort[1];
        } else {
            $host = '127.0.0.1';
            $port = (int) $target;
        }

        if ($port <= 0 || $port > 65535) {
            return null;
        }

        return [
            'match_type' => 'path_prefix',
            'path_prefix' => $pathPrefix,
            'local_host' => $host,
            'local_port' => $port,
            'enabled' => true,
        ];
    }

    /**
     * Load routing rules from a JSON file (e.g. .jetty/routes.json).
     *
     * @return list<array{match_type: string, path_prefix?: string, header_name?: string, header_value?: string, local_host: string, local_port: int, enabled: bool}>
     */
    private static function loadRoutesFile(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($data)) {
            return [];
        }

        // Support both {"rules": [...]} and bare [...]
        $rules = isset($data['rules']) && is_array($data['rules']) ? $data['rules'] : $data;

        $result = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if (! isset($rule['match_type'], $rule['local_host'], $rule['local_port'])) {
                continue;
            }
            $rule['enabled'] = $rule['enabled'] ?? true;
            $result[] = $rule;
        }

        return $result;
    }

    // -- I/O wrappers --

    private function stdout(string $s): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }

    private function stderr(string $s): void
    {
        fwrite(\STDERR, $s.\PHP_EOL);
    }
}
