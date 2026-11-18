<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Vercel;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;

/**
 * A Vercel project's environment variable. Settings: project_id, team_id (optional), secret_name, targets
 * (production, preview, development) and redeploy. A redeploy rebuilds the current production deployment, the same
 * code, so it picks up the new value without shipping anything else.
 */
final readonly class VercelDestination implements KeyDestination
{
    private const string ID = '/^[A-Za-z0-9_.-]{1,100}$/';

    private const array TARGETS = ['production', 'preview', 'development'];

    public function __construct(private VercelClient $client) {}

    public function name(): string
    {
        return 'Vercel';
    }

    public function describe(array $settings): string
    {
        return 'the Vercel variable '.(string) ($settings['secret_name'] ?? '');
    }

    public function put(array $settings, string $value): void
    {
        $this->client->setVariable(
            Settings::required($settings, 'project_id', self::ID),
            Settings::optional($settings, 'team_id', self::ID),
            Settings::required($settings, 'secret_name', '/^[A-Za-z_][A-Za-z0-9_]*$/'),
            $value,
            $this->targets($settings),
        );
    }

    public function redeployTargets(array $settings): array
    {
        if (! Settings::redeploys($settings) || ! in_array('production', $this->targets($settings), true)) {
            return [];
        }

        return [Settings::required($settings, 'project_id', self::ID).'|'.(Settings::optional($settings, 'team_id', self::ID) ?? '')];
    }

    public function deploy(string $target): string
    {
        [$project, $team] = explode('|', $target, 2);

        return $this->client->redeployProduction($project, $team === '' ? null : $team);
    }

    public function deployment(string $target, string $deploymentId): DeploymentState
    {
        [, $team] = explode('|', $target, 2);
        $state = strtoupper($this->client->deploymentState($deploymentId, $team === '' ? null : $team));

        return match ($state) {
            'READY' => DeploymentState::succeeded(),
            'ERROR', 'CANCELED' => DeploymentState::failed("It ended as {$state}."),
            default => DeploymentState::running(),
        };
    }

    public function check(): void
    {
        $this->client->check();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function targets(array $settings): array
    {
        $targets = array_values(array_intersect(self::TARGETS, is_array($settings['targets'] ?? null) ? $settings['targets'] : []));

        return $targets === [] ? ['production'] : $targets;
    }
}
