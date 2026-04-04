<?php

declare(strict_types=1);

namespace JettyCli;

/**
 * Minimal YAML parser for jetty.yml project config files.
 *
 * Supports: scalars, flat/nested maps, sequences (arrays), comments, quoted strings.
 * Does NOT support: anchors, aliases, multi-line strings, complex types, tags.
 * This is intentionally limited to avoid adding a dependency to the PHAR.
 */
final class SimpleYaml
{
    /**
     * Parse a YAML string into a PHP array.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $stack = [&$result];
        $indents = [0];

        foreach ($lines as $line) {
            $trimmed = rtrim($line);

            // Skip empty lines and comments.
            if ($trimmed === '' || ltrim($trimmed) === '' || str_starts_with(ltrim($trimmed), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            $content = trim($trimmed);

            // Pop stack back to the correct nesting level.
            while (count($indents) > 1 && $indent <= $indents[count($indents) - 1]) {
                array_pop($stack);
                array_pop($indents);
            }

            // Array item: "- value" or "- key: value"
            if (str_starts_with($content, '- ')) {
                $itemContent = trim(substr($content, 2));
                $current = &$stack[count($stack) - 1];

                if (str_contains($itemContent, ': ')) {
                    // Inline map inside array: "- key: value"
                    $item = self::parseInlineMap($itemContent);
                    $current[] = $item;
                } else {
                    $current[] = self::parseScalar($itemContent);
                }

                continue;
            }

            // Key-value pair: "key: value" or "key:" (nested)
            if (str_contains($content, ':')) {
                $colonPos = strpos($content, ':');
                $key = trim(substr($content, 0, $colonPos));
                $value = trim(substr($content, $colonPos + 1));

                $current = &$stack[count($stack) - 1];

                if ($value === '' || $value === '|' || $value === '>') {
                    // Nested map or empty value - create sub-array.
                    if (! isset($current[$key]) || ! is_array($current[$key])) {
                        $current[$key] = [];
                    }
                    $stack[] = &$current[$key];
                    $indents[] = $indent;
                } else {
                    $current[$key] = self::parseScalar($value);
                }
            }
        }

        return $result;
    }

    /**
     * Parse a YAML file into a PHP array.
     *
     * @return array<string, mixed>|null
     */
    public static function parseFile(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return null;
        }

        return self::parse($content);
    }

    private static function parseScalar(string $value): string|int|float|bool|null
    {
        // Quoted strings.
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return substr($value, 1, -1);
        }

        // Strip inline comments.
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = trim(substr($value, 0, $commentPos));
        }

        // Booleans.
        $lower = strtolower($value);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if ($lower === 'null' || $lower === '~') {
            return null;
        }

        // Numbers.
        if (is_numeric($value)) {
            if (str_contains($value, '.')) {
                return (float) $value;
            }

            return (int) $value;
        }

        return $value;
    }

    /**
     * Parse an inline map like "key1: value1, key2: value2" or "key: value".
     *
     * @return array<string, mixed>
     */
    private static function parseInlineMap(string $content): array
    {
        $result = [];
        // Simple key: value parsing (not full inline flow syntax).
        $parts = explode(', ', $content);
        foreach ($parts as $part) {
            $colonPos = strpos($part, ':');
            if ($colonPos === false) {
                continue;
            }
            $key = trim(substr($part, 0, $colonPos));
            $value = trim(substr($part, $colonPos + 1));
            $result[$key] = self::parseScalar($value);
        }

        return $result !== [] ? $result : ['_value' => self::parseScalar($content)];
    }
}
