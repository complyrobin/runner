<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\LaravelCloud;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;

/**
 * A Laravel Cloud secret. Settings: secret_id, secret_name, environment_ids and redeploy. The value is sealed to the
 * Laravel Cloud organisation's public key, so only Laravel Cloud can open it. A redeploy ships the newest commit on
 * each environment's branch.
 */
final readonly class LaravelCloudDestination implements KeyDestination
{
    public function __construct(private LaravelCloudClient $client) {}

    public function name(): string
    {
        return 'Laravel Cloud';
    }

    public function describe(array $settings): string
    {
        return 'the Laravel Cloud secret '.(string) ($settings['secret_name'] ?? '');
    }

    public function put(array $settings, string $value): void
    {
        $this->client->updateSecret(Settings::required($settings, 'secret_id'), Settings::required($settings, 'secret_name'), $value);
    }

    public function redeployTargets(array $settings): array
    {
        if (! Settings::redeploys($settings)) {
            return [];
        }

        $environments = is_array($settings['environment_ids'] ?? null) ? $settings['environment_ids'] : [];

        return array_values(array_map(strval(...), array_filter($environments, static fn (mixed $id): bool => is_string($id) || is_int($id))));
    }

    public function deploy(string $target): string
    {
        return $this->client->deploy($target);
    }

    public function deployment(string $target, string $deploymentId): DeploymentState
    {
        return $this->client->deployment($deploymentId);
    }

    public function check(): void
    {
        $this->client->check();
    }
}
