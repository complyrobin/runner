<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Http;

final readonly class Response
{
    public function __construct(public int $status, public string $body) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
