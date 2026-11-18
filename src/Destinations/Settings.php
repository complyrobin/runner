<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Destinations;

use InvalidArgumentException;

/**
 * Reads the ids in a job's destination settings, and checks the ones that become part of an address so a setting
 * can't point a token at another host.
 */
final class Settings
{
    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws InvalidArgumentException when the setting is missing or not in the expected shape
     */
    public static function required(array $settings, string $name, ?string $pattern = null): string
    {
        $value = $settings[$name] ?? null;

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The job's destination has no {$name}. Check the credential's automatic rotation settings in ComplyRobin.");
        }

        if ($pattern !== null && preg_match($pattern, trim($value)) !== 1) {
            throw new InvalidArgumentException("The job's destination has a {$name} that doesn't look right. Check the credential's automatic rotation settings in ComplyRobin.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function optional(array $settings, string $name, ?string $pattern = null): ?string
    {
        $value = $settings[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return self::required($settings, $name, $pattern);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function redeploys(array $settings): bool
    {
        return ($settings['redeploy'] ?? false) === true;
    }
}
