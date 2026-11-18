<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;
use LogicException;

/**
 * An AWS Secrets Manager secret. Settings: region and secret_id (its name or ARN). The new value becomes the
 * secret's current version; the app reads it the next time it fetches the secret.
 */
final readonly class SecretsManagerDestination implements KeyDestination
{
    public function __construct(private AwsJsonClient $client, private IamClient $iam) {}

    public function name(): string
    {
        return 'AWS Secrets Manager';
    }

    public function describe(array $settings): string
    {
        return 'the AWS secret '.(string) ($settings['secret_id'] ?? '');
    }

    public function put(array $settings, string $value): void
    {
        $this->client->call('secretsmanager', Settings::required($settings, 'region'), 'secretsmanager.PutSecretValue', [
            'SecretId' => Settings::required($settings, 'secret_id'),
            'SecretString' => $value,
        ]);
    }

    public function redeployTargets(array $settings): array
    {
        return [];
    }

    public function deploy(string $target): string
    {
        throw new LogicException('AWS secrets are not redeployed.');
    }

    public function deployment(string $target, string $deploymentId): DeploymentState
    {
        return DeploymentState::succeeded();
    }

    public function check(): void
    {
        $this->iam->check();
    }
}
