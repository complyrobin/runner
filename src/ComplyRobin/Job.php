<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\ComplyRobin;

use InvalidArgumentException;

/**
 * One thing ComplyRobin wants done now, exactly as the claims endpoint describes it: rotate a credential, or retire
 * the old keys of an earlier rotation once its grace period has passed (for providers that can't set keys to
 * expire). Ids only: a job never carries a key or a token.
 */
final readonly class Job
{
    /**
     * @param  array<string, mixed>  $source
     * @param  list<array<string, mixed>>  $destinations
     */
    public function __construct(
        public string $run,
        public string $reference,
        public string $name,
        public array $source,
        public array $destinations,
        public int $graceHours,
        public string $action = 'rotate',
        public ?string $keepKey = null,
    ) {}

    public function isRetire(): bool
    {
        return $this->action === 'retire';
    }

    /**
     * @param  array<string, mixed>  $job
     */
    public static function fromArray(array $job): self
    {
        $run = $job['run'] ?? null;
        $action = $job['action'] ?? 'rotate';
        $destinations = $job['destinations'] ?? ($action === 'retire' ? [] : null);

        if (! is_string($run) || $run === '' || ! in_array($action, ['rotate', 'retire'], true) || ! is_array($job['source'] ?? null) || ! is_array($destinations)) {
            throw new InvalidArgumentException('ComplyRobin sent a job this runner does not understand. Update complyrobin/runner.');
        }

        return new self(
            run: $run,
            reference: (string) ($job['credential']['reference'] ?? 'Unknown credential'),
            name: (string) ($job['credential']['name'] ?? ''),
            source: $job['source'],
            destinations: array_values(array_filter($destinations, is_array(...))),
            graceHours: max(0, (int) ($job['grace_hours'] ?? 48)),
            action: $action,
            keepKey: is_string($job['keep_key'] ?? null) ? $job['keep_key'] : null,
        );
    }

    public function label(): string
    {
        return $this->name === '' ? $this->reference : "{$this->reference} ({$this->name})";
    }
}
