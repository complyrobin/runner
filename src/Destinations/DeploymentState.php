<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Destinations;

/**
 * Where a deployment is: still going, done, or failed with the service's reason.
 */
final readonly class DeploymentState
{
    private function __construct(public string $status, public string $reason = '') {}

    public static function running(): self
    {
        return new self('running');
    }

    public static function succeeded(): self
    {
        return new self('succeeded');
    }

    public static function failed(string $reason = ''): self
    {
        return new self('failed', $reason);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function succeededNow(): bool
    {
        return $this->status === 'succeeded';
    }
}
