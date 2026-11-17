<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A rotation stopped at one of the contract's steps: mint, push, deploy or retire.
 */
final class StepFailed extends RuntimeException
{
    public const array STEPS = ['mint', 'push', 'deploy', 'retire'];

    public function __construct(public readonly string $step, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
