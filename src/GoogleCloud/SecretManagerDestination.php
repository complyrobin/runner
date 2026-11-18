<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\GoogleCloud;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;
use LogicException;

/**
 * A Google Cloud Secret Manager secret. Settings: project_id and secret_id. The value is added as the secret's newest
 * version; an app that reads "latest" picks it up the next time it fetches the secret.
 */
final readonly class SecretManagerDestination implements KeyDestination
{
    public const string BASE_URL = 'https://secretmanager.googleapis.com/v1';

    public function __construct(private GoogleCloudClient $client, private string $baseUrl = self::BASE_URL) {}

    public function name(): string
    {
        return 'Google Secret Manager';
    }

    public function describe(array $settings): string
    {
        return 'the Google Cloud secret '.(string) ($settings['secret_id'] ?? '');
    }

    public function put(array $settings, string $value): void
    {
        $project = Settings::required($settings, 'project_id', '/^[a-z0-9][a-z0-9:.-]{4,62}$/');
        $secret = Settings::required($settings, 'secret_id', '/^[A-Za-z0-9_-]{1,255}$/');

        $this->client->call('POST', rtrim($this->baseUrl, '/').'/projects/'.rawurlencode($project).'/secrets/'.rawurlencode($secret).':addVersion', [
            'payload' => ['data' => base64_encode($value)],
        ]);
    }

    public function redeployTargets(array $settings): array
    {
        return [];
    }

    public function deploy(string $target): string
    {
        throw new LogicException('Google Cloud secrets are not redeployed.');
    }

    public function deployment(string $target, string $deploymentId): DeploymentState
    {
        return DeploymentState::succeeded();
    }

    public function check(): void
    {
        $this->client->check();
    }
}
