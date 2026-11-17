<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Http;

/**
 * One HTTP request. Bodies are JSON; headers may carry a token, so a request is never printed.
 */
final readonly class Request
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $json
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?array $json = null,
    ) {}
}
