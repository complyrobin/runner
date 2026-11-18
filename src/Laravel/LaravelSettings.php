<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Laravel;

/**
 * Turns the Laravel config (config/complyrobin-runner.php) into the settings the runner reads, by their usual names.
 * Kept apart from Laravel itself, so it works and is tested without it.
 */
final class LaravelSettings
{
    private const array NAMES = [
        'token' => 'COMPLYROBIN_TOKEN',
        'api_url' => 'COMPLYROBIN_API_URL',
        'openai_admin_key' => 'OPENAI_ADMIN_KEY',
        'aws.access_key_id' => 'AWS_ACCESS_KEY_ID',
        'aws.secret_access_key' => 'AWS_SECRET_ACCESS_KEY',
        'aws.session_token' => 'AWS_SESSION_TOKEN',
        'google_cloud.access_token' => 'GOOGLE_CLOUD_ACCESS_TOKEN',
        'google_cloud.credentials' => 'GOOGLE_CLOUD_CREDENTIALS',
        'laravel_cloud_token' => 'LARAVEL_CLOUD_API_TOKEN',
        'github_token' => 'GH_SECRETS_TOKEN',
        'forge_token' => 'FORGE_API_TOKEN',
        'vercel_token' => 'VERCEL_TOKEN',
    ];

    /**
     * Only the config: never the app's own environment, which holds the app's keys under the same names.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function environment(array $config): array
    {
        $environment = [];

        foreach (self::NAMES as $path => $name) {
            $value = $config;

            foreach (explode('.', $path) as $segment) {
                $value = is_array($value) ? ($value[$segment] ?? null) : null;
            }

            if (is_string($value) && trim($value) !== '') {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
