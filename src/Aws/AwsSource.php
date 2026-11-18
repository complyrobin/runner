<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\NewKey;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Sources\Settings;
use RuntimeException;

/**
 * AWS IAM user access keys. Setting: user_name, an IAM user that exists only for this credential.
 *
 * AWS keys can't be told to expire, and a user can have only two. So a rotation makes the new key and leaves the
 * old one working; ComplyRobin sends a retire job once the grace period has passed, which switches the old key off
 * (Inactive, so it can be switched back on in AWS if something still needed it); the next rotation deletes it.
 *
 * The key is reported by a shortened id (AKIA…WXYZ): ComplyRobin keeps nothing that looks like a whole AWS key id.
 * A destination asks for the access key id with value "key_id", and for the secret with "key".
 */
final readonly class AwsSource implements KeySource
{
    public function __construct(private IamClient $client) {}

    public function name(): string
    {
        return 'AWS';
    }

    public function mint(array $settings, string $keyName, ?int $lifetimeSeconds): NewKey
    {
        $userName = Settings::required($settings, 'user_name');
        $active = 0;

        foreach ($this->client->listAccessKeys($userName) as $existing) {
            if ($existing['status'] === 'Inactive') {
                $this->client->deleteAccessKey($userName, $existing['id']);
            } else {
                $active++;
            }
        }

        if ($active >= 2) {
            throw new RuntimeException("The IAM user {$userName} already has two active access keys, the most AWS allows. The runner switches the old key off once each rotation's grace period has passed; to rotate sooner, delete the key nothing uses any more in AWS.");
        }

        $created = $this->client->createAccessKey($userName);

        return new NewKey(self::shortId($created['id']), $created['secret'], null, substr($created['id'], -4), ['key_id' => $created['id']]);
    }

    public function verify(array $settings, NewKey $key): void
    {
        $this->client->verifyKey((string) $key->part('key_id'), $key->value());
    }

    public function discard(array $settings, NewKey $key): void
    {
        $id = $key->part('key_id');

        if ($id !== null) {
            $this->client->deleteAccessKey(Settings::required($settings, 'user_name'), $id);
        }
    }

    public function expiresKeysItself(): bool
    {
        return false;
    }

    /**
     * Leaves the old key working. Returns 1 when there is one, so ComplyRobin sends a retire job later.
     */
    public function retireOthers(array $settings, NewKey $key, int $graceSeconds, int $now): int
    {
        $newId = $key->part('key_id');

        return count(array_filter(
            $this->client->listAccessKeys(Settings::required($settings, 'user_name')),
            static fn (array $existing): bool => $existing['id'] !== $newId && $existing['status'] === 'Active',
        ));
    }

    public function switchOffOthers(array $settings, string $keepKeyId, int $now): int
    {
        $userName = Settings::required($settings, 'user_name');
        $keys = $this->client->listAccessKeys($userName);

        $kept = array_filter($keys, static fn (array $existing): bool => self::shortId($existing['id']) === $keepKeyId && $existing['status'] === 'Active');

        if ($kept === []) {
            throw new ApiError('AWS', 200, "The access key from the last rotation ({$keepKeyId}) is no longer active on {$userName}, so no keys were switched off.");
        }

        $switchedOff = 0;

        foreach ($keys as $existing) {
            if (self::shortId($existing['id']) !== $keepKeyId && $existing['status'] === 'Active') {
                $this->client->deactivateAccessKey($userName, $existing['id']);
                $switchedOff++;
            }
        }

        return $switchedOff;
    }

    public function check(): void
    {
        $this->client->check();
    }

    /**
     * AKIAIOSFODNN7EXAMPLE becomes AKIA…MPLE: enough to tell the keys apart, not a whole key id.
     */
    public static function shortId(string $accessKeyId): string
    {
        return substr($accessKeyId, 0, 4).'…'.substr($accessKeyId, -4);
    }
}
