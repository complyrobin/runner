<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\OpenAi;

use ComplyRobin\Runner\NewKey;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Sources\Settings;

/**
 * OpenAI service account keys. Settings: project_id and service_account_id. OpenAI can set a key to expire, so the
 * old keys are retired during the rotation.
 */
final readonly class OpenAiSource implements KeySource
{
    public function __construct(private OpenAiClient $client) {}

    public function name(): string
    {
        return 'OpenAI';
    }

    public function mint(array $settings, string $keyName, ?int $lifetimeSeconds): NewKey
    {
        return $this->client->createServiceAccountKey(
            Settings::required($settings, 'project_id'),
            Settings::required($settings, 'service_account_id'),
            $keyName,
            $lifetimeSeconds,
        );
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

    /**
     * @param  array<string, mixed>  $settings
     */
    private function expireOthers(array $settings, string $keepKeyId, int $seconds, int $expiresAt): int
    {
        $projectId = Settings::required($settings, 'project_id');
        $serviceAccountId = Settings::required($settings, 'service_account_id');
        $retired = 0;

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
