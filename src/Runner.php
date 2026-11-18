<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\ComplyRobin\Job;
use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Exceptions\StepFailed;
use ComplyRobin\Runner\Http\Response;
use ComplyRobin\Runner\Support\Redact;
use Throwable;

/**
 * One check-in: ask ComplyRobin what is due, rotate each credential, report each outcome. Run it on a schedule
 * (hourly is plenty). Exit code 0 when everything due was rotated, 1 when a rotation failed, 2 when the runner
 * could not talk to ComplyRobin at all.
 */
final readonly class Runner
{
    public const string VERSION = '0.1.0';

    public const int OK = 0;

    public const int ROTATION_FAILED = 1;

    public const int UNREACHABLE = 2;

    /** Claims are repeated while they hand out work, up to this many times. */
    private const int MAX_CLAIMS = 20;

    /** @var Closure(string): void */
    private Closure $output;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  list<string>  $secrets  values that must never appear in output
     * @param  (Closure(string): void)|null  $output
     * @param  (Closure(int): void)|null  $sleep
     * @param  Checker|null  $checker  tests the runner's access when someone pressed Test in ComplyRobin
     */
    public function __construct(
        private ComplyRobinClient $complyRobin,
        private Rotator $rotator,
        private array $secrets = [],
        ?Closure $output = null,
        ?Closure $sleep = null,
        private ?Checker $checker = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
            fwrite(STDOUT, $line.PHP_EOL);
        };
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    public function run(): int
    {
        $exitCode = self::OK;
        $handled = 0;

        for ($claim = 0; $claim < self::MAX_CLAIMS; $claim++) {
            try {
                $claimed = $this->complyRobin->claim();
            } catch (Throwable $exception) {
                $this->say('Could not check in with ComplyRobin. '.$this->explain($exception));

                return self::UNREACHABLE;
            }

            if ($claimed->checkRequested && $claim === 0) {
                $this->test();
            }

            $jobs = $claimed->jobs;

            if ($jobs === []) {
                break;
            }

            foreach ($jobs as $job) {
                $handled++;

                if (! $this->handle($job)) {
                    $exitCode = self::ROTATION_FAILED;
                }
            }
        }

        if ($handled === 0) {
            $this->say('Nothing is due for rotation.');
        }

        return $exitCode;
    }

    /**
     * Someone pressed Test on this runner in ComplyRobin: test each service's access and send the results back.
     */
    private function test(): void
    {
        if (! $this->checker instanceof Checker) {
            return;
        }

        $this->say('ComplyRobin asked for a test of this runner\'s access.');
        $results = $this->checker->run();

        foreach ($results as $result) {
            $this->say("{$result['service']}: {$result['message']}");
        }

        try {
            $this->complyRobin->sendCheck($results);
        } catch (Throwable $exception) {
            $this->say('Could not send the test results to ComplyRobin. '.$this->explain($exception));
        }
    }

    private function handle(Job $job): bool
    {
        $this->say($job->isRetire() ? "{$job->label()}: switching off the old keys." : "{$job->label()}: rotating.");
        $failed = ['run' => $job->run] + ($job->isRetire() ? ['action' => 'retire'] : []) + ['outcome' => 'failed'];

        try {
            $report = $job->isRetire() ? $this->rotator->retireOldKeys($job) : $this->rotator->rotate($job);
        } catch (StepFailed $failure) {
            $message = Redact::text($failure->getMessage(), 500, $this->secrets);
            $this->say("{$job->label()}: failed at {$failure->step}. {$message}");
            $this->deliver($job, $failed + ['step' => $failure->step, 'message' => $message]);

            return false;
        } catch (Throwable $exception) {
            $message = 'The runner stopped unexpectedly. '.$this->explain($exception);
            $this->say("{$job->label()}: failed. {$message}");
            $this->deliver($job, $failed + ['step' => $job->isRetire() ? 'retire' : 'mint', 'message' => Redact::text($message, 500, $this->secrets)]);

            return false;
        }

        $this->say($job->isRetire()
            ? "{$job->label()}: switched off {$report['retired_keys']} old key(s)."
            : "{$job->label()}: rotated. ".$this->summary($report));

        return $this->deliver($job, $report);
    }

    /**
     * Sends a report, retrying when ComplyRobin can't be reached or answers 5xx/429 (reports are safe to repeat).
     * A success ComplyRobin refuses with 422 is sent again with only the essentials, so the rotation is still
     * recorded. If nothing gets through, ComplyRobin gives the run up after an hour and tells the owner.
     *
     * @param  array<string, mixed>  $report
     */
    private function deliver(Job $job, array $report): bool
    {
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            try {
                $response = $this->complyRobin->report($report);
            } catch (Throwable $exception) {
                $response = null;
                $problem = $this->explain($exception);
            }

            if ($response instanceof Response) {
                if ($response->status === 200) {
                    return true;
                }

                if ($response->status === 409) {
                    $this->say("{$job->label()}: ComplyRobin already has a different outcome for this run, so this one was not recorded.");

                    return false;
                }

                if ($response->status === 422 && ($report['outcome'] ?? null) === 'succeeded' && $attempt === 1) {
                    $this->say("{$job->label()}: ComplyRobin refused part of the report (".$this->explain(ApiError::from('ComplyRobin', $response))."). Sending the essentials only.");
                    $report = $this->essentials($report);

                    continue;
                }

                if ($response->status < 500 && $response->status !== 429) {
                    $this->say("{$job->label()}: ComplyRobin did not record the outcome. ".$this->explain(ApiError::from('ComplyRobin', $response)));

                    return false;
                }

                $problem = $this->explain(ApiError::from('ComplyRobin', $response));
            }

            if ($attempt < 4) {
                ($this->sleep)(2 ** $attempt);
            }
        }

        $this->say("{$job->label()}: could not report to ComplyRobin. ".($problem ?? '').' ComplyRobin will mark the run as not reported within the hour.');

        return false;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function essentials(array $report): array
    {
        return array_filter([
            'run' => $report['run'],
            'outcome' => 'succeeded',
            'rotated_at' => $report['rotated_at'],
            'new_key' => ['id' => $report['new_key']['id'], 'expires_at' => $report['new_key']['expires_at'] ?? null],
            'old_keys_expire_at' => $report['old_keys_expire_at'] ?? null,
            'retire_later' => $report['retire_later'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function summary(array $report): string
    {
        $parts = ['New key '.$report['new_key']['id'].'.'];
        $deployments = count($report['deployments'] ?? []);

        if ($deployments > 0) {
            $parts[] = "{$deployments} deployment(s) started.";
        }

        $parts[] = match (true) {
            isset($report['retire_later']) => 'Old keys keep working until '.$report['old_keys_expire_at'].', then the runner switches them off.',
            isset($report['old_keys_expire_at']) => 'Old keys expire at '.$report['old_keys_expire_at'].'.',
            default => 'There were no old keys to retire.',
        };

        return implode(' ', $parts);
    }

    private function explain(Throwable $exception): string
    {
        return Redact::text($exception->getMessage(), 300, $this->secrets);
    }

    private function say(string $line): void
    {
        ($this->output)(Redact::text($line, 2000, $this->secrets));
    }
}
