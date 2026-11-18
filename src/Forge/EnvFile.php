<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Forge;

/**
 * Sets one variable in the text of a .env file, leaving every other line exactly as it was. A value that isn't plain
 * is written in double quotes, with backslashes, quotes, dollar signs and line breaks escaped the way Laravel's
 * .env reader (phpdotenv) reads them back.
 */
final class EnvFile
{
    public static function set(string $content, string $name, string $value): string
    {
        $line = $name.'='.self::quote($value);
        $pattern = '/^(?:export\s+)?'.preg_quote($name, '/').'\s*=.*$/m';
        $replaced = 0;

        $updated = preg_replace_callback($pattern, static function () use ($line, &$replaced): string {
            $replaced++;

            return $replaced === 1 ? $line : '';
        }, $content);

        if ($replaced > 0) {
            return (string) $updated;
        }

        return ($content === '' || str_ends_with($content, "\n") ? $content : $content."\n").$line."\n";
    }

    private static function quote(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_\-.\/:@+=,]*$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(["\\", '"', '$', "\r\n", "\n", "\r"], ['\\\\', '\\"', '\\$', '\\n', '\\n', '\\n'], $value).'"';
    }
}
