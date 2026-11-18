<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\LaravelCloud;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Destinations\Settings;
use ComplyRobin\Runner\Discovery\Discoverable;
use InvalidArgumentException;

/**
 * A Laravel Cloud secret. Settings: secret_name, environment_ids, redeploy, and secret_id when it is known. Without a
 * secret_id the runner uses the secret of that name attached to each environment, and when there is none it makes
 * the secret and attaches it, so nobody has to look up ids. The value is sealed to the Laravel Cloud organisation's
 * public key, so only Laravel Cloud can open it. A redeploy ships the newest commit on each environment's branch.
 */
final readonly class LaravelCloudDestination implements Discoverable, KeyDestination
{
    /** Applications whose environments are listed for ComplyRobin. */
    private const int APPLICATIONS_WITH_ENVIRONMENTS = 50;

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
        $secretName = Settings::required($settings, 'secret_name');
        $secretId = Settings::optional($settings, 'secret_id');

        if ($secretId !== null) {
            $this->client->updateSecret($secretId, $secretName, $value);

            return;
        }

        $environments = self::environmentIds($settings);

        if ($environments === []) {
            throw new InvalidArgumentException('The job names no Laravel Cloud environment. Choose one in the credential\'s automatic rotation settings in ComplyRobin.');
        }

        $found = [];
        $missing = [];

        foreach ($environments as $environmentId) {
            $ids = array_column(array_filter(
                $this->client->environmentSecrets($environmentId),
                static fn (array $secret): bool => $secret['name'] === $secretName,
            ), 'id');

            if ($ids === []) {
                $missing[] = $environmentId;
            } else {
                array_push($found, ...$ids);
            }
        }

        $found = array_values(array_unique($found));

        foreach ($found as $id) {
            $this->client->updateSecret($id, $secretName, $value);
        }

        if ($missing === []) {
            return;
        }

        $id = $found[0] ?? $this->client->createSecret($secretName, $value);

        foreach ($missing as $environmentId) {
            $this->client->attachSecrets($environmentId, [$id]);
        }
    }

    public function redeployTargets(array $settings): array
    {
        return Settings::redeploys($settings) ? self::environmentIds($settings) : [];
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

    public function inventoryName(): string
    {
        return 'laravel_cloud';
    }

    /**
     * The applications the token can see, each with its environments and the names of their secrets.
     *
     * @return array{applications: list<array<string, mixed>>}
     */
    public function discover(): array
    {
        $applications = [];

        foreach ($this->client->applications() as $index => $application) {
            $applications[] = $index < self::APPLICATIONS_WITH_ENVIRONMENTS
                ? $application + ['environments' => $this->client->environments($application['id'])]
                : $application;
        }

        return ['applications' => $applications];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private static function environmentIds(array $settings): array
    {
        $environments = is_array($settings['environment_ids'] ?? null) ? $settings['environment_ids'] : [];

        return array_values(array_unique(array_map(strval(...), array_filter($environments, static fn (mixed $id): bool => (is_string($id) && $id !== '') || is_int($id)))));
    }
}
