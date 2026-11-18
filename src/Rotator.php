<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\ComplyRobin\Job;
use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Exceptions\StepFailed;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Support\Redact;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Rotates one credential, in the order the ComplyRobin contract fixes:
 *
 *  1. mint    make a new key for the credential's own identity at the provider (OpenAI, AWS or Google Cloud);
 *  2. verify  make one harmless call with the new key, so a key that doesn't work is never handed out;
 *  3. push    write it into each destination (Laravel Cloud, GitHub, AWS, Google Cloud, Forge, Vercel);
 *  4. deploy  redeploy what the destinations say, and wait until every deployment has finished;
 *  5. retire  have every other key of that identity stop working after the grace period.
 *
 * The old key keeps working until step 5, and step 5 only happens once the app is running with the new key, so a
 * failure at any step leaves the app running. Where the provider can't set a key to expire (AWS, Google Cloud),
 * step 5 happens later, as a retire job from ComplyRobin once the grace period has passed.
 *
 * Returns the report; throws StepFailed with a message that is safe to send to ComplyRobin.
 */
final readonly class Rotator
{
    /** How long to wait for redeploys to finish before giving up and leaving the old key working. */
    public const int DEPLOY_TIMEOUT_SECONDS = 20 * 60;

    /** How often to ask how a deployment is going. */
    private const int DEPLOY_POLL_SECONDS = 10;

    /** Waits between tries of a new key: a brand-new key can take a few seconds to work everywhere. */
    private const array VERIFY_WAITS = [3, 5, 10, 20, 30];

    /** What to set when access to a provider or destination is missing. */
    private const array ACCESS = [
        'openai' => ['OpenAI', 'Set OPENAI_ADMIN_KEY.'],
        'aws' => ['AWS', 'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or sign in first with aws-actions/configure-aws-credentials.'],
        'google_cloud' => ['Google Cloud', 'Set GOOGLE_CLOUD_ACCESS_TOKEN or GOOGLE_CLOUD_CREDENTIALS.'],
        'laravel_cloud' => ['Laravel Cloud', 'Set LARAVEL_CLOUD_API_TOKEN.'],
        'github' => ['GitHub', 'Set GH_SECRETS_TOKEN to a GitHub token that can write the repository\'s secrets.'],
        'aws_secrets_manager' => ['AWS', 'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or sign in first with aws-actions/configure-aws-credentials.'],
        'aws_parameter_store' => ['AWS', 'Set AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or sign in first with aws-actions/configure-aws-credentials.'],
        'google_secret_manager' => ['Google Cloud', 'Set GOOGLE_CLOUD_ACCESS_TOKEN or GOOGLE_CLOUD_CREDENTIALS.'],
        'forge' => ['Forge', 'Set FORGE_API_TOKEN.'],
        'vercel' => ['Vercel', 'Set VERCEL_TOKEN.'],
    ];

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /** @var Closure(string): void */
    private Closure $mask;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(string): void */
    private Closure $progress;

    /**
     * @param  array<string, KeySource>  $sources  by the source type ComplyRobin uses: openai, aws, google_cloud
     * @param  array<string, KeyDestination>  $destinations  by the destination type ComplyRobin uses: laravel_cloud, github, …
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     * @param  (Closure(string): void)|null  $mask  hides a value from CI logs (GitHub's add-mask)
     * @param  (Closure(int): void)|null  $sleep
     * @param  (Closure(string): void)|null  $progress  prints what the runner is waiting for
     */
    public function __construct(
        private array $sources,
        private array $destinations,
        ?Closure $clock = null,
        ?Closure $mask = null,
        ?Closure $sleep = null,
        ?Closure $progress = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->mask = $mask ?? static function (string $value): void {};
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->progress = $progress ?? static function (string $line): void {};
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StepFailed
     */
    public function rotate(Job $job): array
    {
        $source = $this->source($job, 'mint');
        $places = $this->places($job);
        $key = $this->mint($job, $source);
        $hint = $key->hint();

        try {
            $this->verify($job, $source, $key);
            $rotatedAt = $this->push($job, $source, $key, $places);
        } finally {
            $key->forget();
        }

        $deployments = $this->deploy($places);
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

        throw new StepFailed($step, self::missing($type, "This runner can't make {$type} keys yet. Update complyrobin/runner."));
    }

    /**
     * Every destination of the job with the service that writes to it, checked before a key is made, so a job the
     * runner can't finish never leaves a new key behind.
     *
     * @return list<array{settings: array<string, mixed>, service: KeyDestination}>
     */
    private function places(Job $job): array
    {
        if ($job->destinations === []) {
            throw new StepFailed('push', 'The job has nowhere to put the new key.');
        }

        $places = [];

        foreach ($job->destinations as $settings) {
            $type = (string) ($settings['type'] ?? '');

            if (! isset($this->destinations[$type])) {
                throw new StepFailed('push', self::missing($type, "This runner can't update {$type} secrets yet. Update complyrobin/runner."));
            }

            $places[] = ['settings' => $settings, 'service' => $this->destinations[$type]];
        }

        return $places;
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
     * Tries the new key, waiting a little between tries, before it goes anywhere. A key that never works is deleted
     * again, and the old one keeps working.
     */
    private function verify(Job $job, KeySource $source, NewKey $key): void
    {
        $waits = self::VERIFY_WAITS;

        while (true) {
            try {
                $source->verify($job->source, $key);

                return;
            } catch (Throwable $exception) {
                $wait = array_shift($waits);

                if ($wait === null) {
                    $this->discardQuietly($job, $source, $key);

                    throw new StepFailed('verify', "{$source->name()} made a new key, but it did not work when the runner tried it, so it was deleted again. The old key keeps working. ".self::reason($exception, $key->secrets()), $exception);
                }

                ($this->sleep)($wait);
            }
        }
    }

    /**
     * Writes the key into every destination. When none received it, the new key is deleted again so nothing is
     * left behind; when some did, it stays (those apps may already use it) and the retire step tidies up later.
     *
     * @param  list<array{settings: array<string, mixed>, service: KeyDestination}>  $places
     */
    private function push(Job $job, KeySource $source, NewKey $key, array $places): DateTimeImmutable
    {
        $updated = 0;

        try {
            foreach ($places as ['settings' => $settings, 'service' => $service]) {
                $part = (string) ($settings['value'] ?? 'key');
                $value = $key->part($part);
                $where = $service->describe($settings);

                if ($value === null) {
                    throw new StepFailed('push', "A {$source->name()} key has no {$part} to put in {$where}.");
                }

                try {
                    $service->put($settings, $value);
                } catch (Throwable $exception) {
                    throw new StepFailed('push', "Could not update {$where}. ".self::reason($exception, $key->secrets()), $exception);
                }

                $updated++;
            }
        } catch (StepFailed $failure) {
            if ($updated === 0) {
                $this->discardQuietly($job, $source, $key);
            }

            throw new StepFailed('push', $failure->getMessage().($updated > 0 ? " {$updated} other place(s) already have the new key." : ''), $failure);
        }

        return ($this->clock)();
    }

    /**
     * Starts every redeploy the destinations ask for (each app once), then waits until all have finished. The old
     * keys are only retired after this, so an app is never left with a key that has stopped working.
     *
     * @param  list<array{settings: array<string, mixed>, service: KeyDestination}>  $places
     * @return list<string> deployment ids
     */
    private function deploy(array $places): array
    {
        $targets = [];

        foreach ($places as ['settings' => $settings, 'service' => $service]) {
            try {
                $found = $service->redeployTargets($settings);
            } catch (Throwable $exception) {
                throw new StepFailed('deploy', 'The new key is saved, but the runner could not tell what to redeploy. '.self::kept().' '.self::reason($exception), $exception);
            }

            foreach ($found as $target) {
                $targets[$service->name().'|'.$target] = ['service' => $service, 'target' => $target];
            }
        }

        $running = [];

        foreach ($targets as $deployment) {
            $label = self::label($deployment['service'], $deployment['target']);

            try {
                $id = $deployment['service']->deploy($deployment['target']);
            } catch (Throwable $exception) {
                throw new StepFailed('deploy', "The new key is saved, but redeploying {$label} could not start, so the app may still use the old key. ".self::kept().' '.self::reason($exception), $exception);
            }

            $running[] = [...$deployment, 'id' => $id, 'label' => $label, 'errors' => 0];
        }

        $ids = array_values(array_filter(array_column($running, 'id'), static fn (string $id): bool => $id !== ''));
        $waited = 0;

        while ($running !== []) {
            foreach ($running as $index => $deployment) {
                try {
                    $state = $deployment['service']->deployment($deployment['target'], $deployment['id']);
                    $running[$index]['errors'] = 0;
                } catch (Throwable $exception) {
                    if (++$running[$index]['errors'] >= 3) {
                        throw new StepFailed('deploy', "The new key is saved, but the runner could not tell whether redeploying {$deployment['label']} worked. ".self::kept().' '.self::reason($exception), $exception);
                    }

                    continue;
                }

                if ($state->succeededNow()) {
                    unset($running[$index]);
                } elseif (! $state->isRunning()) {
                    throw new StepFailed('deploy', "The new key is saved, but redeploying {$deployment['label']} failed, so the app still uses the old key. ".self::kept().self::because($state));
                }
            }

            if ($running === []) {
                break;
            }

            if ($waited >= self::DEPLOY_TIMEOUT_SECONDS) {
                $labels = implode(', ', array_column($running, 'label'));

                throw new StepFailed('deploy', 'The new key is saved, but redeploying '.$labels.' was still going after '.intdiv(self::DEPLOY_TIMEOUT_SECONDS, 60).' minutes. '.self::kept());
            }

            if ($waited === 0) {
                ($this->progress)('Waiting for '.implode(', ', array_column($running, 'label')).' to finish deploying.');
            }

            ($this->sleep)(self::DEPLOY_POLL_SECONDS);
            $waited += self::DEPLOY_POLL_SECONDS;
        }

        return $ids;
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

    private static function missing(string $type, string $unknown): string
    {
        if (! isset(self::ACCESS[$type])) {
            return $unknown;
        }

        [$name, $fix] = self::ACCESS[$type];

        return "The runner has no {$name} access. {$fix}";
    }

    private static function label(KeyDestination $service, string $target): string
    {
        return match ($service->name()) {
            'Laravel Cloud' => "Laravel Cloud environment {$target}",
            'Forge' => 'Forge site '.basename($target),
            'Vercel' => 'Vercel project '.explode('|', $target)[0],
            default => $service->name().' '.$target,
        };
    }

    private static function kept(): string
    {
        return 'The old key keeps working and was not retired.';
    }

    private static function because(DeploymentState $state): string
    {
        return $state->reason === '' ? '' : ' '.Redact::text($state->reason, 200);
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
