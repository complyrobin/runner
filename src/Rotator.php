<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\ComplyRobin\Job;
use ComplyRobin\Runner\Exceptions\StepFailed;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Support\Redact;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Rotates one credential, in the order the ComplyRobin contract fixes:
 *
 *  1. mint    make a new key for the credential's own identity at the provider (OpenAI, AWS or Google Cloud);
 *  2. push    seal it and write it into each Laravel Cloud secret;
 *  3. deploy  redeploy the listed environments so the app picks it up;
 *  4. retire  have every other key of that identity stop working after the grace period.
 *
 * The old key keeps working until step 4's grace period ends, so a failure at any step leaves the app running.
 * Where the provider can't set a key to expire (AWS, Google Cloud), step 4 happens later, as a retire job from
 * ComplyRobin once the grace period has passed.
 *
 * Returns the report; throws StepFailed with a message that is safe to send to ComplyRobin.
 */
final readonly class Rotator
{
    /** What to set when a provider's access is missing. */
    private const array ACCESS = [
        'openai' => ['OpenAI', 'Set OPENAI_ADMIN_KEY.'],
        'aws' => ['AWS', 'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or sign in first with aws-actions/configure-aws-credentials.'],
        'google_cloud' => ['Google Cloud', 'Set GOOGLE_CLOUD_ACCESS_TOKEN or GOOGLE_CLOUD_CREDENTIALS.'],
    ];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(string): void */
    private Closure $mask;

    /**
     * @param  array<string, KeySource>  $sources  by the source type ComplyRobin uses: openai, aws, google_cloud
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     * @param  (Closure(string): void)|null  $mask  hides a value from CI logs (GitHub's add-mask)
     */
    public function __construct(
        private array $sources,
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
        $source = $this->source($job, 'mint');
        $key = $this->mint($job, $source);
        $hint = $key->hint();

        try {
            $rotatedAt = $this->push($job, $source, $key);
        } finally {
            $key->forget();
        }

        $deployments = $this->deploy($job);
        [$oldKeysExpireAt, $retireLater] = $this->retire($job, $source, $key);

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
            'retire_later' => $retireLater ? true : null,
            'deployments' => $deployments,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * A retire job: the grace period after a rotation has passed, so switch off every key of the credential's
     * identity except the one that rotation made.
     *
     * @return array<string, mixed>
     *
     * @throws StepFailed
     */
    public function retireOldKeys(Job $job): array
    {
        $source = $this->source($job, 'retire');

        if ($job->keepKey === null || $job->keepKey === '') {
            throw new StepFailed('retire', 'ComplyRobin did not say which key to keep, so no keys were switched off.');
        }

        try {
            $switchedOff = $source->switchOffOthers($job->source, $job->keepKey, ($this->clock)()->getTimestamp());
        } catch (Throwable $exception) {
            throw new StepFailed('retire', 'The old keys could not be switched off, so they still work. '.self::reason($exception), $exception);
        }

        return ['run' => $job->run, 'action' => 'retire', 'outcome' => 'retired', 'retired_keys' => $switchedOff];
    }

    private function source(Job $job, string $step): KeySource
    {
        $type = (string) ($job->source['type'] ?? '');

        if (isset($this->sources[$type])) {
            return $this->sources[$type];
        }

        if (isset(self::ACCESS[$type])) {
            [$name, $fix] = self::ACCESS[$type];

            throw new StepFailed($step, "The runner has no {$name} access. {$fix}");
        }

        throw new StepFailed($step, "This runner can't make {$type} keys yet. Update complyrobin/runner.");
    }

    private function mint(Job $job, KeySource $source): NewKey
    {
        $days = $job->source['key_lifetime_days'] ?? null;
        $now = ($this->clock)();

        try {
            $key = $source->mint(
                $job->source,
                'ComplyRobin '.$job->reference.' '.$now->format('Y-m-d H:i'),
                is_int($days) && $days > 0 ? $days * 86400 : null,
            );
        } catch (Throwable $exception) {
            throw new StepFailed('mint', 'Could not make a new key. '.self::reason($exception), $exception);
        }

        foreach ($key->secrets() as $secret) {
            ($this->mask)($secret);
        }

        return $key;
    }

    /**
     * Writes the key into every destination. When none received it, the new key is deleted again so nothing is
     * left behind; when some did, it stays (those apps may already use it) and the retire step tidies up later.
     */
    private function push(Job $job, KeySource $source, NewKey $key): DateTimeImmutable
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
                $part = (string) ($destination['value'] ?? 'key');
                $value = $key->part($part);

                if ($value === null) {
                    throw new StepFailed('push', "A {$source->name()} key has no {$part} to put in {$name}.");
                }

                try {
                    $this->laravelCloud->updateSecret((string) ($destination['secret_id'] ?? ''), $name, $value);
                } catch (Throwable $exception) {
                    throw new StepFailed('push', "Could not update the Laravel Cloud secret {$name}. ".self::reason($exception, $key->secrets()), $exception);
                }

                $updated++;
            }
        } catch (StepFailed $failure) {
            if ($updated === 0) {
                $this->discardQuietly($job, $source, $key);
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
     * Has the other keys stop working after the grace period. Returns when, or null when there were none, and
     * whether ComplyRobin must send a retire job to switch them off then.
     *
     * @return array{0: DateTimeImmutable|null, 1: bool}
     */
    private function retire(Job $job, KeySource $source, NewKey $key): array
    {
        $seconds = $job->graceHours * 3600;
        $now = ($this->clock)();

        try {
            $others = $source->retireOthers($job->source, $key, $seconds, $now->getTimestamp());
        } catch (Throwable $exception) {
            $what = $source->expiresKeysItself() ? 'set to expire' : 'listed';

            throw new StepFailed('retire', "The new key is live, but the old keys could not be {$what}, so they still work. ".self::reason($exception), $exception);
        }

        if ($others === 0) {
            return [null, false];
        }

        return [$now->modify("+{$seconds} seconds"), ! $source->expiresKeysItself()];
    }

    private function discardQuietly(Job $job, KeySource $source, NewKey $key): void
    {
        try {
            $source->discard($job->source, $key);
        } catch (Throwable) {
            // The retire step switches it off later anyway.
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
