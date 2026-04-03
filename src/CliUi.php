<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Terminal styling: ANSI colors when stdout is a TTY and NO_COLOR is unset.
 * Follows https://no-color.org/ — set NO_COLOR to disable.
 */
final class CliUi
{
    private static ?self $defaultInstance = null;

    private readonly bool $color;

    public function __construct(bool $color)
    {
        $this->color = $color;
    }

    public static function forStdio(): self
    {
        return new self(self::detectColor());
    }

    public static function default(): self
    {
        return self::$defaultInstance ??= self::forStdio();
    }

    public static function resetDefault(): void
    {
        self::$defaultInstance = null;
    }

    public function isColorEnabled(): bool
    {
        return $this->color;
    }

    public static function detectColor(): bool
    {
        $no = getenv('NO_COLOR');
        if ($no !== false && $no !== '') {
            return false;
        }
        if (getenv('CLICOLOR') === '0') {
            return false;
        }
        if (getenv('CLICOLOR_FORCE') === '1') {
            return true;
        }
        if (($t = getenv('TERM')) !== false && $t !== '' && $t === 'dumb') {
            return false;
        }

        return function_exists('posix_isatty') && @posix_isatty(\STDOUT);
    }

    public function stripAnsi(string $s): string
    {
        return preg_replace('/\e\[[0-9;]*m/', '', $s) ?? $s;
    }

    public function out(string $s = ''): void
    {
        fwrite(\STDOUT, $s.\PHP_EOL);
    }

    public function err(string $s = ''): void
    {
        fwrite(\STDERR, $s.\PHP_EOL);
    }

    public function outRaw(string $s): void
    {
        fwrite(\STDOUT, $s);
        fflush(\STDOUT);
    }

    public function errRaw(string $s): void
    {
        fwrite(\STDERR, $s);
        fflush(\STDERR);
    }

    public function infoLine(string $s): void
    {
        $this->out($this->cyan('●').' '.$s);
    }

    public function successLine(string $s): void
    {
        $this->out($this->green('✓').' '.$s);
    }

    public function warnLine(string $s): void
    {
        $this->err($this->yellow('!').' '.$s);
    }

    public function errorLine(string $s): void
    {
        $this->err($this->red('✖').' '.$s);
    }

    public function mutedLine(string $s): void
    {
        $this->out($this->dim($s));
    }

    public function verboseLine(string $s): void
    {
        $this->err($this->dim($this->magenta($s)));
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public function labelValueRows(array $pairs, int $labelWidth = 14): void
    {
        foreach ($pairs as [$label, $value]) {
            $padded = str_pad($label, $labelWidth);
            $this->out($this->dim($padded).' '.$value);
        }
    }

    public function section(string $title): void
    {
        $pad = str_repeat('─', max(0, 42 - strlen($title)));
        $this->out($this->bold($this->cyan($title)).' '.$this->dim($pad));
    }

    /**
     * Top rule + subtitle (e.g. version string).
     */
    public function banner(string $subtitle = ''): void
    {
        $this->out('');
        $jetty = $this->bold($this->cyan(' Jetty '));
        $tag = $subtitle !== '' ? $this->dim(' · '.$subtitle) : '';
        $this->out($jetty.$tag);
        $this->out($this->dim(str_repeat('·', min(56, 12 + strlen($subtitle)))));
        $this->out('');
    }

    /**
     * @param  list<string>  $lines  Inner lines (no outer border)
     */
    public function panel(string $title, array $lines): void
    {
        $this->out($this->bold($title));
        foreach ($lines as $line) {
            $this->out('  '.$line);
        }
        $this->out('');
    }

    /**
     * Renders aligned columns; $rows are plain strings (no ANSI width accounting).
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    public function table(array $headers, array $rows, array $columnWidths): void
    {
        $parts = [];
        foreach ($headers as $i => $h) {
            $w = $columnWidths[$i] ?? 12;
            $parts[] = str_pad($h, $w);
        }
        $this->out($this->dim(implode('  ', $parts)));
        $this->out($this->dim(str_repeat('─', min(120, array_sum($columnWidths) + 2 * count($columnWidths)))));
        foreach ($rows as $row) {
            $line = [];
            foreach ($row as $i => $cell) {
                $w = $columnWidths[$i] ?? 12;
                $c = (string) $cell;
                if (strlen($c) > $w - 1 && $w > 6) {
                    $c = substr($c, 0, $w - 2).'…';
                }
                $line[] = str_pad($c, $w);
            }
            $this->out(implode('  ', $line));
        }
    }

    /**
     * Two-column command help: command (styled) + description.
     *
     * @param  list<array{0: string, 1: string}>  $rows
     */
    public function commandGrid(array $rows, int $cmdWidth = 44): void
    {
        foreach ($rows as [$cmd, $desc]) {
            $c = $this->cmd($cmd);
            $len = strlen($this->stripAnsi($c));
            $pad = $len < $cmdWidth ? str_repeat(' ', $cmdWidth - $len) : ' ';
            $this->out($c.$pad.$this->dim($desc));
        }
    }

    public function cmd(string $s): string
    {
        return $this->bold($this->green($s));
    }

    public function flag(string $s): string
    {
        return $this->cyan($s);
    }

    public function envName(string $s): string
    {
        return $this->yellow($s);
    }

    public function bold(string $s): string
    {
        return $this->wrap($s, '1');
    }

    public function dim(string $s): string
    {
        return $this->wrap($s, '2');
    }

    public function underline(string $s): string
    {
        return $this->wrap($s, '4');
    }

    public function red(string $s): string
    {
        return $this->wrap($s, '31');
    }

    public function green(string $s): string
    {
        return $this->wrap($s, '32');
    }

    public function yellow(string $s): string
    {
        return $this->wrap($s, '33');
    }

    public function cyan(string $s): string
    {
        return $this->wrap($s, '36');
    }

    public function magenta(string $s): string
    {
        return $this->wrap($s, '35');
    }

    private function wrap(string $s, string $code): string
    {
        if (! $this->color) {
            return $s;
        }

        return "\e[".$code.'m'.$s."\e[0m";
    }
}
