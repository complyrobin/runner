<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\GitHub;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;
use LogicException;

/**
 * A GitHub Actions secret, of a repository or of one of its environments. Settings: repository (owner/name),
 * environment (optional) and secret_name. The value is sealed to the repository's public key. Workflows read the new
 * value on their next run, so there is nothing to redeploy.
 */
final readonly class GitHubDestination implements KeyDestination
{
    public const string REPOSITORY = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';

    public function __construct(private GitHubClient $client) {}

    public function name(): string
    {
        return 'GitHub';
    }

    public function describe(array $settings): string
    {
        $environment = is_string($settings['environment'] ?? null) && $settings['environment'] !== '' ? " ({$settings['environment']} environment)" : '';

        return 'the GitHub secret '.(string) ($settings['secret_name'] ?? '').' in '.(string) ($settings['repository'] ?? '').$environment;
    }

    public function put(array $settings, string $value): void
    {
        $this->client->putSecret(
            Settings::required($settings, 'repository', self::REPOSITORY),
            Settings::optional($settings, 'environment'),
            Settings::required($settings, 'secret_name', '/^[A-Za-z_][A-Za-z0-9_]*$/'),
            $value,
        );
    }

    public function redeployTargets(array $settings): array
    {
        return [];
    }

    public function deploy(string $target): string
    {
        throw new LogicException('GitHub secrets are not redeployed.');
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
