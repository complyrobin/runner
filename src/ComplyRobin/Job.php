<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\ComplyRobin;

use InvalidArgumentException;

/**
 * One credential ComplyRobin wants rotated now, exactly as the claims endpoint describes it. Ids only: a job never
 * carries a key or a token.
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
    ) {}

    /**
     * @param  array<string, mixed>  $job
     */
    public static function fromArray(array $job): self
    {
        $run = $job['run'] ?? null;

        if (! is_string($run) || $run === '' || ! is_array($job['source'] ?? null) || ! is_array($job['destinations'] ?? null)) {
            throw new InvalidArgumentException('ComplyRobin sent a job this runner does not understand. Update complyrobin/runner.');
        }

        return new self(
            run: $run,
            reference: (string) ($job['credential']['reference'] ?? 'Unknown credential'),
            name: (string) ($job['credential']['name'] ?? ''),
            source: $job['source'],
            destinations: array_values(array_filter($job['destinations'], is_array(...))),
            graceHours: max(0, (int) ($job['grace_hours'] ?? 48)),
        );
    }

    public function label(): string
    {
        return $this->name === '' ? $this->reference : "{$this->reference} ({$this->name})";
    }
}
