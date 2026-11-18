<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Forge;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;

/**
 * A variable in a Laravel Forge site's environment file. Settings: organisation (the Forge organisation's slug),
 * server_id, site_id, secret_name and redeploy. Only that one line of the file changes. A redeploy runs the site's
 * deploy script, which deploys the newest commit on its branch.
 */
final readonly class ForgeDestination implements KeyDestination
{
    private const string SLUG = '/^[A-Za-z0-9_-]{1,100}$/';

    private const string ID = '/^\d{1,20}$/';

    public function __construct(private ForgeClient $client) {}

    public function name(): string
    {
        return 'Forge';
    }

    public function describe(array $settings): string
    {
        return 'the Forge variable '.(string) ($settings['secret_name'] ?? '').' on site '.(string) ($settings['site_id'] ?? '');
    }

    public function put(array $settings, string $value): void
    {
        [$organisation, $server, $site] = $this->site($settings);
        $name = Settings::required($settings, 'secret_name', '/^[A-Za-z_][A-Za-z0-9_]*$/');

        $content = $this->client->environment($organisation, $server, $site);
        $this->client->updateEnvironment($organisation, $server, $site, EnvFile::set($content, $name, $value));
    }

    public function redeployTargets(array $settings): array
    {
        return Settings::redeploys($settings) ? [implode('/', $this->site($settings))] : [];
    }

    public function deploy(string $target): string
    {
        return $this->client->deploy(...explode('/', $target, 3));
    }

    public function deployment(string $target, string $deploymentId): DeploymentState
    {
        [$organisation, $server, $site] = explode('/', $target, 3);
        $status = $this->client->deploymentStatus($organisation, $server, $site, $deploymentId);

        return match ($status) {
            'finished', 'success', 'succeeded' => DeploymentState::succeeded(),
            'failed', 'failure', 'error', 'cancelled', 'canceled' => DeploymentState::failed("It ended as {$status}."),
            default => DeploymentState::running(),
        };
    }

    public function check(): void
    {
        $this->client->check();
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{0: string, 1: string, 2: string}
     */
    private function site(array $settings): array
    {
        return [
            Settings::required($settings, 'organisation', self::SLUG),
            Settings::required($settings, 'server_id', self::ID),
            Settings::required($settings, 'site_id', self::ID),
        ];
    }
}
