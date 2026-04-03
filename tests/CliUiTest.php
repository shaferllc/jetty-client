<?php

declare(strict_types=1);

namespace JettyCli\Tests;

use JettyCli\CliUi;
use PHPUnit\Framework\TestCase;

final class CliUiTest extends TestCase
{
    /** @var list<string> */
    private array $envVars = [
        'NO_COLOR',
        'CLICOLOR',
        'CLICOLOR_FORCE',
        'TERM',
    ];

    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        CliUi::resetDefault();
    }

    private function clearEnv(): void
    {
        foreach ($this->envVars as $var) {
            putenv($var);
        }
    }

    public function test_color_enabled_wraps_bold_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[1mhello\e[0m", $ui->bold('hello'));
    }

    public function test_color_enabled_wraps_dim_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[2mhello\e[0m", $ui->dim('hello'));
    }

    public function test_color_enabled_wraps_red_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[31mhello\e[0m", $ui->red('hello'));
    }

    public function test_color_enabled_wraps_green_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[32mhello\e[0m", $ui->green('hello'));
    }

    public function test_color_enabled_wraps_yellow_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[33mhello\e[0m", $ui->yellow('hello'));
    }

    public function test_color_enabled_wraps_cyan_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[36mhello\e[0m", $ui->cyan('hello'));
    }

    public function test_color_enabled_wraps_magenta_with_ansi(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[35mhello\e[0m", $ui->magenta('hello'));
    }

    public function test_color_disabled_returns_plain_text(): void
    {
        $ui = new CliUi(color: false);

        $this->assertSame('hello', $ui->bold('hello'));
        $this->assertSame('hello', $ui->dim('hello'));
        $this->assertSame('hello', $ui->red('hello'));
        $this->assertSame('hello', $ui->green('hello'));
        $this->assertSame('hello', $ui->yellow('hello'));
        $this->assertSame('hello', $ui->cyan('hello'));
        $this->assertSame('hello', $ui->magenta('hello'));
    }

    public function test_strip_ansi_removes_escape_sequences(): void
    {
        $ui = new CliUi(color: true);
        $styled = $ui->bold($ui->green('hello'));

        $this->assertSame('hello', $ui->stripAnsi($styled));
    }

    public function test_detect_color_no_color_set_returns_false(): void
    {
        putenv('NO_COLOR=1');

        $this->assertFalse(CliUi::detectColor());
    }

    public function test_detect_color_clicolor_zero_returns_false(): void
    {
        putenv('CLICOLOR=0');

        $this->assertFalse(CliUi::detectColor());
    }

    public function test_detect_color_clicolor_force_returns_true(): void
    {
        putenv('CLICOLOR_FORCE=1');

        $this->assertTrue(CliUi::detectColor());
    }

    public function test_detect_color_term_dumb_returns_false(): void
    {
        putenv('TERM=dumb');

        $this->assertFalse(CliUi::detectColor());
    }

    public function test_cmd_returns_bold_green(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[1m\e[32mjetty\e[0m\e[0m", $ui->cmd('jetty'));
    }

    public function test_flag_returns_cyan(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[36m--port\e[0m", $ui->flag('--port'));
    }

    public function test_env_name_returns_yellow(): void
    {
        $ui = new CliUi(color: true);

        $this->assertSame("\e[33mJETTY_TOKEN\e[0m", $ui->envName('JETTY_TOKEN'));
    }
}
