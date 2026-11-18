<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\ComplyRobin;

/**
 * ComplyRobin's answer to a check-in: the jobs to do now, and whether someone pressed Test on the runner.
 */
final readonly class Claim
{
    /**
     * @param  list<Job>  $jobs
     */
    public function __construct(public array $jobs, public bool $checkRequested = false) {}
}
