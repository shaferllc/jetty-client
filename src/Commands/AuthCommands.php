<?php

declare(strict_types=1);

namespace JettyCli\Commands;

use JettyCli\CliUi;
use JettyCli\Config;
use JettyCli\SetupWizard;

final class AuthCommands
{
    public function __construct(
        private readonly CliUi $ui,
    ) {}

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    public function login(array $global, array $rest, string $helpText): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty login\n".$helpText,
            );
        }
        try {
            SetupWizard::runLogin($global['config'], $global['region'] ?? null);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  list<string>  $rest
     */
    public function logout(array $rest, string $helpText): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty logout\n".$helpText,
            );
        }
        Config::clearUserConfigKey('token');
        $this->stdout(
            'Removed saved API token from ~/.config/jetty/config.json.',
        );
        $envTok = getenv('JETTY_TOKEN');
        if (is_string($envTok) && trim($envTok) !== '') {
            $this->stderr(
                'Note: JETTY_TOKEN is still set in your environment; unset it to stop using that token.',
            );
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    public function onboard(array $global, array $rest, string $helpText): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty onboard\n".$helpText,
            );
        }
        try {
            SetupWizard::runOnboarding(
                $global['config'],
                $global['region'] ?? null,
            );
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  array{api-url: ?string, token: ?string, config: ?string, region: ?string}  $global
     * @param  list<string>  $rest
     */
    public function setup(array $global, array $rest, string $helpText): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty setup\n".$helpText,
            );
        }
        try {
            SetupWizard::runSetupMenu($global['config']);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param  list<string>  $rest
     */
    public function reset(array $rest, string $helpText): int
    {
        if ($rest !== []) {
            throw new \InvalidArgumentException(
                "Usage: jetty reset\n".$helpText,
            );
        }
        Config::resetLocalUserConfig();
        $this->stdout(
            'Cleared local Jetty config (Bridge URL, token, subdomain, domain, tunnel_server) and removed ~/.jetty.json if present.',
        );
        $this->stdout(
            'Environment variables (JETTY_TOKEN, JETTY_API_URL, …) are unchanged; unset them if needed.',
        );
        $this->stdout(
            'Project files (./jetty.config.json or JETTY_CONFIG) are not deleted.',
        );

        return 0;
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
