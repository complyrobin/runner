<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Sources;

use InvalidArgumentException;

/**
 * Reads the ids in a job's source settings.
 */
final class Settings
{
    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws InvalidArgumentException when the setting is missing
     */
    public static function required(array $settings, string $name): string
    {
        $value = $settings[$name] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("The job has no {$name}. Check the credential's automatic rotation settings in ComplyRobin.");
        }

        return trim($value);
    }
}
