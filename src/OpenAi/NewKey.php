<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\OpenAi;

/**
 * A key OpenAI has just made. The value lives only in memory, only until it is sealed for each destination, and is
 * wiped with forget(). It is never printed, logged or reported: only its id and its last four characters are.
 */
final class NewKey
{
    public function __construct(
        public readonly string $id,
        private string $value,
        public readonly ?int $expiresAt,
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function hint(): string
    {
        return substr($this->value, -4);
    }

    public function forget(): void
    {
        if ($this->value !== '') {
            sodium_memzero($this->value);
        }

        $this->value = '';
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'value' => '[hidden]', 'expiresAt' => $this->expiresAt];
    }
}
