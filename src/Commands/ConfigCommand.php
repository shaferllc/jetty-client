<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use JettyCli\CliUi;
use JettyCli\Config;

final class ConfigCommand
{
    public function __construct(
        private readonly CliUi $ui,
    ) {}

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $args
     * @param  callable(array{api-url: ?string, token: ?string, config: ?string, region: ?string}, list<string>): int  $setupCallback
     */
    public function execute(array $global, array $args, callable $setupCallback, string $helpText): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException(
                "Usage: jetty config set|get|clear|wizard ...\n".
                    $helpText,
            );
        }
        $sub = array_shift($args);

        return match ($sub) {
            'set' => $this->cmdConfigSet($args),
            'get' => $this->cmdConfigGet($args),
            'clear' => $this->cmdConfigClear($args),
            'wizard' => $setupCallback($global, []),
            default => throw new \InvalidArgumentException(
                'Unknown config subcommand: '.$sub."\n".$helpText,
            ),
        };
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigSet(array $args): int
    {
        if (count($args) < 2) {
            throw new \InvalidArgumentException(
                'Usage: jetty config set <key> <value>  (keys: server, api-url, token, subdomain, domain, tunnel-server)',
            );
        }
        $key = $args[0];
        $value = $args[1];
        $jsonKey = Config::normalizeConfigCliKey($key);
        Config::writeUserConfigMerged([$jsonKey => trim($value)]);
        $path = Config::userConfigPath() ?? '(unknown)';
        $this->stdout("Wrote {$jsonKey} to {$path}");

        return 0;
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigGet(array $args): int
    {
        $m = Config::readUserConfigMap();
        if ($args === []) {
            if ($m === []) {
                $this->stdout('(no keys in user config file)');

                return 0;
            }
            foreach (
                [
                    'api_url',
                    'server',
                    'token',
                    'subdomain',
                    'custom_domain',
                    'tunnel_server',
                ] as $k
            ) {
                $this->printConfigLine($m, $k);
            }

            return 0;
        }
        $jsonKey = Config::normalizeConfigCliKey($args[0]);
        $this->printConfigLine($m, $jsonKey);

        return 0;
    }

    /**
     * @param  list<string>  $args
     */
    private function cmdConfigClear(array $args): int
    {
        if ($args === []) {
            throw new \InvalidArgumentException(
                'Usage: jetty config clear <key|all>',
            );
        }
        $a = strtolower(trim($args[0]));
        if ($a === 'all') {
            Config::clearAllUserConfig();
            $this->stdout('Cleared user config keys.');

            return 0;
        }
        $jsonKey = Config::normalizeConfigCliKey($a);
        Config::clearUserConfigKey($jsonKey);
        $this->stdout("Removed {$jsonKey}");

        return 0;
    }

    /**
     * @param  array<string, mixed>  $m
     */
    private function printConfigLine(array $m, string $jsonKey): void
    {
        if (
            ! array_key_exists($jsonKey, $m) ||
            $m[$jsonKey] === null ||
            $m[$jsonKey] === ''
        ) {
            $this->stdout("{$jsonKey}=");

            return;
        }
        $s = trim((string) $m[$jsonKey]);
        if ($jsonKey === 'token' && $s !== '') {
            $s =
                strlen($s) <= 8
                    ? '****'
                    : substr($s, 0, 4).'…'.substr($s, -4);
        }
        $this->stdout("{$jsonKey}={$s}");
    }

    private function stdout(string $s): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }
}
