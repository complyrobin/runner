<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use ComplyRobin\Runner\Support\Redact;
use Throwable;

/**
 * Tests the runner's access to each service with one cheap call, without making or changing anything. The results
 * are printed and sent to ComplyRobin, which shows them on the Rotation runners page.
 */
final readonly class Checker
{
    /**
     * @param  array<string, object|null>  $services  by name; each has check(), or is null when its access isn't set
     * @param  list<string>  $secrets  values that must never appear in a message
     */
    public function __construct(private array $services, private array $secrets = []) {}

    /**
     * @return list<array{service: string, status: string, message: string}>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->services as $name => $service) {
            if ($service === null) {
                $results[] = ['service' => $name, 'status' => 'not_set', 'message' => 'No access set, so this runner can\'t use it.'];

                continue;
            }

            try {
                $service->check();
                $results[] = ['service' => $name, 'status' => 'ok', 'message' => 'The access works.'];
            } catch (Throwable $exception) {
                $results[] = ['service' => $name, 'status' => 'failed', 'message' => Redact::text($exception->getMessage(), 300, $this->secrets)];
            }
        }

        return $results;
    }

    /**
     * @param  list<array{service: string, status: string, message: string}>  $results
     */
    public static function healthy(array $results): bool
    {
        return array_filter($results, static fn (array $result): bool => $result['status'] === 'failed') === [];
    }
}
