<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\OpenAi;

use ComplyRobin\Runner\Discovery\Discoverable;
use ComplyRobin\Runner\NewKey;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Sources\Settings;

/**
 * OpenAI service account keys. Settings: project_id, and either service_account_id or service_account_name. With a
 * name only, the runner uses the project's service account of that name, and makes it on the first rotation, so
 * nobody has to create one by hand. OpenAI can set a key to expire, so the old keys are retired during the rotation.
 */
final readonly class OpenAiSource implements Discoverable, KeySource
{
    /** Projects whose service accounts are listed for ComplyRobin; the rest are listed by name only. */
    private const int PROJECTS_WITH_SERVICE_ACCOUNTS = 50;

    public function __construct(private OpenAiClient $client) {}

    public function name(): string
    {
        return 'OpenAI';
    }

    public function mint(array $settings, string $keyName, ?int $lifetimeSeconds): NewKey
    {
        $projectId = Settings::required($settings, 'project_id');
        $serviceAccountId = $this->serviceAccountId($settings);

        if ($serviceAccountId === null) {
            return $this->client->createServiceAccount($projectId, Settings::required($settings, 'service_account_name'), $lifetimeSeconds)['key'];
        }

        return $this->client->createServiceAccountKey(
            $projectId,
            $serviceAccountId,
            $keyName,
            $lifetimeSeconds,
            Settings::optional($settings, 'service_account_id') === null ? ['service_account_id' => $serviceAccountId] : [],
        );
    }

    public function verify(array $settings, NewKey $key): void
    {
        $this->client->verifyKey($key->value());
    }

    public function discard(array $settings, NewKey $key): void
    {
        $this->client->deleteKey(Settings::required($settings, 'project_id'), $key->id);
    }

    public function expiresKeysItself(): bool
    {
        return true;
    }

    /**
     * Sets every other key of the service account to expire after the grace period, unless it already expires
     * sooner.
     */
    public function retireOthers(array $settings, NewKey $key, int $graceSeconds, int $now): int
    {
        return $this->expireOthers($settings, $key->id, $graceSeconds, $now + $graceSeconds);
    }

    public function switchOffOthers(array $settings, string $keepKeyId, int $now): int
    {
        return $this->expireOthers($settings, $keepKeyId, 60, $now + 60);
    }

    public function check(): void
    {
        $this->client->check();
    }

    public function inventoryName(): string
    {
        return 'openai';
    }

    /**
     * The projects the admin key can see, each with its service accounts.
     *
     * @return array{projects: list<array{id: string, name: string, service_accounts?: list<array{id: string, name: string}>}>}
     */
    public function discover(): array
    {
        $projects = [];

        foreach ($this->client->projects() as $index => $project) {
            $projects[] = $index < self::PROJECTS_WITH_SERVICE_ACCOUNTS
                ? $project + ['service_accounts' => $this->client->serviceAccounts($project['id'])]
                : $project;
        }

        return ['projects' => $projects];
    }

    /**
     * The service account's id: the one ComplyRobin sent, or the project's service account with the name it sent.
     * Null when there is no such service account yet.
     *
     * @param  array<string, mixed>  $settings
     */
    private function serviceAccountId(array $settings): ?string
    {
        $id = Settings::optional($settings, 'service_account_id');

        if ($id !== null) {
            return $id;
        }

        $name = Settings::required($settings, 'service_account_name');

        foreach ($this->client->serviceAccounts(Settings::required($settings, 'project_id')) as $serviceAccount) {
            if ($serviceAccount['name'] === $name) {
                return $serviceAccount['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function expireOthers(array $settings, string $keepKeyId, int $seconds, int $expiresAt): int
    {
        $projectId = Settings::required($settings, 'project_id');
        $serviceAccountId = $this->serviceAccountId($settings);
        $retired = 0;

        if ($serviceAccountId === null) {
            return 0;
        }

        foreach ($this->client->listProjectKeys($projectId) as $existing) {
            $owner = $existing['owner']['service_account']['id'] ?? null;
            $current = $existing['expires_at'] ?? null;

            if ($owner !== $serviceAccountId || ($existing['id'] ?? null) === $keepKeyId) {
                continue;
            }

            if (is_int($current) && $current <= $expiresAt) {
                continue;
            }

            $this->client->expireKeyIn($projectId, (string) $existing['id'], max(60, $seconds));
            $retired++;
        }

        return $retired;
    }
}
