<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Exceptions;

use ComplyRobin\Runner\Http\Response;
use ComplyRobin\Runner\Support\Redact;
use RuntimeException;

/**
 * A service answered with an error. The message names the service and status, plus the service's own short
 * explanation with anything that looks like a key removed, so it is safe to print and to report.
 */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly string $service, public readonly int $status, string $detail = '')
    {
        $message = "{$service} answered {$status}.";

        if ($detail !== '') {
            $message .= ' '.Redact::text($detail, 200);
        }

        parent::__construct($message);
    }

    public static function from(string $service, Response $response): self
    {
        $json = $response->json();
        $detail = $json['error']['message'] ?? $json['message'] ?? '';

        return new self($service, $response->status, is_string($detail) ? $detail : '');
    }
}
