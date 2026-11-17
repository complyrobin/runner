<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\ComplyRobin\Job;
use ComplyRobin\Runner\Exceptions\StepFailed;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\OpenAi\NewKey;
use ComplyRobin\Runner\OpenAi\OpenAiClient;
use ComplyRobin\Runner\Support\Redact;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Rotates one credential, in the order the ComplyRobin contract fixes:
 *
 *  1. mint    make a new key for the credential's dedicated OpenAI service account;
 *  2. push    seal it and write it into each Laravel Cloud secret;
 *  3. deploy  redeploy the listed environments so the app picks it up;
 *  4. retire  set every other key of that service account to expire after the grace period.
 *
 * The old key keeps working until step 4's grace period ends, so a failure at any step leaves the app running.
 * Returns the success report; throws StepFailed with a message that is safe to send to ComplyRobin.
 */
final readonly class Rotator
{
    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(string): void */
    private Closure $mask;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     * @param  (Closure(string): void)|null  $mask  hides a value from CI logs (GitHub's add-mask)
     */
    public function __construct(
        private ?OpenAiClient $openAi,
        private ?LaravelCloudClient $laravelCloud,
        ?Closure $clock = null,
        ?Closure $mask = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->mask = $mask ?? static function (string $value): void {};
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StepFailed
     */
    public function rotate(Job $job): array
    {
        $key = $this->mint($job);
        $hint = $key->hint();

        try {
            $rotatedAt = $this->push($job, $key);
        } finally {
            $key->forget();
        }

        $deployments = $this->deploy($job);
        $oldKeysExpireAt = $this->retire($job, $key);

        return array_filter([
            'run' => $job->run,
            'outcome' => 'succeeded',
            'rotated_at' => self::iso($rotatedAt),
            'new_key' => [
                'id' => $key->id,
                'hint' => $hint,
                'expires_at' => $key->expiresAt === null ? null : self::iso(new DateTimeImmutable('@'.$key->expiresAt)),
            ],
            'old_keys_expire_at' => $oldKeysExpireAt === null ? null : self::iso($oldKeysExpireAt),
            'deployments' => $deployments,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function mint(Job $job): NewKey
    {
        $type = (string) ($job->source['type'] ?? '');

        if ($type !== 'openai') {
            throw new StepFailed('mint', "This runner can't make {$type} keys yet. Update complyrobin/runner.");
        }

        if ($this->openAi === null) {
            throw new StepFailed('mint', 'The runner has no OpenAI admin key. Set OPENAI_ADMIN_KEY.');
        }

        $days = $job->source['key_lifetime_days'] ?? null;
        $now = ($this->clock)();

        try {
            $key = $this->openAi->createServiceAccountKey(
                (string) ($job->source['project_id'] ?? ''),
                (string) ($job->source['service_account_id'] ?? ''),
                'ComplyRobin '.$job->reference.' '.$now->format('Y-m-d H:i'),
                is_int($days) && $days > 0 ? $days * 86400 : null,
            );
        } catch (Throwable $exception) {
            throw new StepFailed('mint', 'Could not make a new key. '.self::reason($exception), $exception);
        }

        ($this->mask)($key->value());

        return $key;
    }

    /**
     * Writes the key into every destination. When none received it, the new key is deleted again so nothing is
     * left behind; when some did, it stays (those apps may already use it) and the next run's retire step tidies up.
     */
    private function push(Job $job, NewKey $key): DateTimeImmutable
    {
        $updated = 0;

        try {
            if ($job->destinations === []) {
                throw new StepFailed('push', 'The job has nowhere to put the new key.');
            }

            foreach ($job->destinations as $destination) {
                $type = (string) ($destination['type'] ?? '');

                if ($type !== 'laravel_cloud') {
                    throw new StepFailed('push', "This runner can't update {$type} secrets yet. Update complyrobin/runner.");
                }

                if ($this->laravelCloud === null) {
                    throw new StepFailed('push', 'The runner has no Laravel Cloud API token. Set LARAVEL_CLOUD_API_TOKEN.');
                }

                $name = (string) ($destination['secret_name'] ?? '');

                try {
                    $this->laravelCloud->updateSecret((string) ($destination['secret_id'] ?? ''), $name, $key->value());
                } catch (Throwable $exception) {
                    throw new StepFailed('push', "Could not update the Laravel Cloud secret {$name}. ".self::reason($exception, [$key->value()]), $exception);
                }

                $updated++;
            }
        } catch (StepFailed $failure) {
            if ($updated === 0) {
                $this->deleteQuietly($job, $key);
            }

            throw new StepFailed('push', $failure->getMessage().($updated > 0 ? " {$updated} other secret(s) already have the new key." : ''), $failure);
        }

        return ($this->clock)();
    }

    /**
     * @return list<string> deployment ids
     */
    private function deploy(Job $job): array
    {
        $environments = [];

        foreach ($job->destinations as $destination) {
            if (($destination['redeploy'] ?? false) === true) {
                foreach (is_array($destination['environment_ids'] ?? null) ? $destination['environment_ids'] : [] as $environment) {
                    $environments[(string) $environment] = true;
                }
            }
        }

        $deployments = [];

        foreach (array_keys($environments) as $environment) {
            try {
                $id = $this->laravelCloud?->deploy((string) $environment) ?? '';
            } catch (Throwable $exception) {
                throw new StepFailed('deploy', "The new key is saved, but redeploying environment {$environment} failed, so the app may still use the old key (it keeps working). ".self::reason($exception), $exception);
            }

            if ($id !== '') {
                $deployments[] = $id;
            }
        }

        return $deployments;
    }

    /**
     * Sets every other key of the service account to expire after the grace period, unless it already expires
     * sooner. The service account belongs to this one credential, so "every other key" is exactly the old ones.
     */
    private function retire(Job $job, NewKey $key): ?DateTimeImmutable
    {
        $projectId = (string) ($job->source['project_id'] ?? '');
        $serviceAccountId = (string) ($job->source['service_account_id'] ?? '');
        $seconds = $job->graceHours * 3600;
        $expiresAt = ($this->clock)()->modify("+{$seconds} seconds");
        $retired = 0;

        try {
            foreach ($this->openAi?->listProjectKeys($projectId) ?? [] as $existing) {
                $owner = $existing['owner']['service_account']['id'] ?? null;
                $current = $existing['expires_at'] ?? null;

                if ($owner !== $serviceAccountId || ($existing['id'] ?? null) === $key->id) {
                    continue;
                }

                if (is_int($current) && $current <= $expiresAt->getTimestamp()) {
                    continue;
                }

                $this->openAi->expireKeyIn($projectId, (string) $existing['id'], max(60, $seconds));
                $retired++;
            }
        } catch (Throwable $exception) {
            throw new StepFailed('retire', 'The new key is live, but the old keys could not be set to expire, so they still work. '.self::reason($exception), $exception);
        }

        return $retired > 0 ? $expiresAt : null;
    }

    private function deleteQuietly(Job $job, NewKey $key): void
    {
        try {
            $this->openAi?->deleteKey((string) ($job->source['project_id'] ?? ''), $key->id);
        } catch (Throwable) {
            // The next run's retire step expires it anyway.
        }
    }

    /**
     * @param  list<string>  $secrets
     */
    private static function reason(Throwable $exception, array $secrets = []): string
    {
        return Redact::text($exception->getMessage(), 300, $secrets);
    }

    private static function iso(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
