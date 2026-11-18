<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;
use LogicException;

/**
 * An AWS Systems Manager Parameter Store parameter, written as an encrypted SecureString. Settings: region and
 * parameter_name. The app reads the new value the next time it fetches the parameter.
 */
final readonly class ParameterStoreDestination implements KeyDestination
{
    public function __construct(private AwsJsonClient $client, private IamClient $iam) {}

    public function name(): string
    {
        return 'AWS Parameter Store';
    }

    public function describe(array $settings): string
    {
        return 'the AWS parameter '.(string) ($settings['parameter_name'] ?? '');
    }

    public function put(array $settings, string $value): void
    {
        $this->client->call('ssm', Settings::required($settings, 'region'), 'AmazonSSM.PutParameter', [
            'Name' => Settings::required($settings, 'parameter_name'),
            'Value' => $value,
            'Type' => 'SecureString',
            'Overwrite' => true,
        ]);
    }

    public function redeployTargets(array $settings): array
    {
        return [];
    }

    public function deploy(string $target): string
    {
        throw new LogicException('AWS parameters are not redeployed.');
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
