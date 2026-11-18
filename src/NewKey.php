<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

/**
 * A key a provider has just made. The value lives only in memory, only until it is sealed for each destination, and
 * is wiped with forget(). It is never printed, logged or reported: only its id and a short hint are.
 *
 * Some keys come in parts: an AWS access key is an access key id plus a secret, and each can go into its own secret.
 * part('key') is the secret value; other parts are named by the provider.
 */
final class NewKey
{
    /**
     * @param  string  $id  the provider's id for the key, safe to report
     * @param  string|null  $hint  what to show people to recognise the key; the value's last four characters when null
     * @param  array<string, string>  $parts  other parts a destination may ask for, such as key_id; never secret, so kept after forget()
     * @param  list<string>  $sensitive  pieces of the value that must be hidden on their own as well, such as the private key inside a key file
     * @param  array<string, string>  $identity  ids of something the runner made or found for the credential, such as the OpenAI service account, for ComplyRobin to keep
     */
    public function __construct(
        public readonly string $id,
        private string $value,
        public readonly ?int $expiresAt,
        private ?string $hint = null,
        private array $parts = [],
        private array $sensitive = [],
        public readonly array $identity = [],
    ) {}

    public function value(): string
    {
        return $this->value;
    }

    public function hint(): string
    {
        return $this->hint ?? substr($this->value, -4);
    }

    /**
     * The part a destination asked for. Null when this kind of key has no such part.
     */
    public function part(string $name): ?string
    {
        return $name === 'key' ? $this->value : ($this->parts[$name] ?? null);
    }

    /**
     * Every value that must be hidden from logs and messages: the key itself and any sensitive piece of it.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        return array_values(array_filter([$this->value, ...$this->sensitive], static fn (string $value): bool => $value !== ''));
    }

    public function forget(): void
    {
        if ($this->value !== '') {
            sodium_memzero($this->value);
        }

        foreach (array_keys($this->sensitive) as $index) {
            if ($this->sensitive[$index] !== '') {
                sodium_memzero($this->sensitive[$index]);
            }
        }

        $this->value = '';
        $this->sensitive = [];
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'value' => '[hidden]', 'expiresAt' => $this->expiresAt];
    }
}
