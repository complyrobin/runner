<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\GoogleCloud;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\NewKey;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Sources\Settings;
use RuntimeException;
use Throwable;

/**
 * Google Cloud service account keys. Setting: service_account_email, a service account that exists only for this
 * credential. The new key is the whole JSON key file, which is what apps read (for example from
 * GOOGLE_APPLICATION_CREDENTIALS_JSON).
 *
 * Google Cloud keys can't be told to expire one by one, so a rotation leaves the old keys working; ComplyRobin
 * sends a retire job once the grace period has passed, which disables them (they can be enabled again in Google
 * Cloud if something still needed one); the next rotation deletes them.
 */
final readonly class GoogleCloudSource implements KeySource
{
    public function __construct(private GoogleCloudClient $client) {}

    public function name(): string
    {
        return 'Google Cloud';
    }

    public function mint(array $settings, string $keyName, ?int $lifetimeSeconds): NewKey
    {
        $email = Settings::required($settings, 'service_account_email');

        foreach ($this->client->listKeys($email) as $existing) {
            if ($existing['disabled']) {
                $this->client->deleteKey($existing['name']);
            }
        }

        $created = $this->client->createKey($email);
        $file = json_decode($created['key_file'], true);
        $sensitive = [];

        if (is_array($file) && is_string($file['private_key'] ?? null)) {
            // The private key on its own, and each line of it, so no piece of it can show in a log.
            $sensitive[] = $file['private_key'];

            foreach (preg_split('/\R|\\\\n/', $file['private_key']) ?: [] as $line) {
                if (strlen(trim($line)) >= 16 && ! str_contains($line, '-----')) {
                    $sensitive[] = trim($line);
                }
            }
        }

        $id = self::keyId($created['name']);

        return new NewKey($id, $created['key_file'], null, substr($id, -4), [], $sensitive);
    }

    public function verify(array $settings, NewKey $key): void
    {
        try {
            $this->client->verifyKeyFile($key->value());
        } catch (Throwable) {
            throw new RuntimeException('Google Cloud did not accept the new key file yet.');
        }
    }

    public function discard(array $settings, NewKey $key): void
    {
        $this->client->deleteKey($this->resourceName($settings, $key->id));
    }

    public function expiresKeysItself(): bool
    {
        return false;
    }

    /**
     * Leaves the old keys working. Returns how many there are, so ComplyRobin sends a retire job later.
     */
    public function retireOthers(array $settings, NewKey $key, int $graceSeconds, int $now): int
    {
        return count(array_filter(
            $this->client->listKeys(Settings::required($settings, 'service_account_email')),
            static fn (array $existing): bool => self::keyId($existing['name']) !== $key->id && ! $existing['disabled'],
        ));
    }

    public function switchOffOthers(array $settings, string $keepKeyId, int $now): int
    {
        $email = Settings::required($settings, 'service_account_email');
        $keys = $this->client->listKeys($email);
        $kept = array_filter($keys, static fn (array $existing): bool => self::keyId($existing['name']) === $keepKeyId && ! $existing['disabled']);

        if ($kept === []) {
            throw new ApiError('Google Cloud', 200, "The key from the last rotation ({$keepKeyId}) is no longer enabled on {$email}, so no keys were disabled.");
        }

        $disabled = 0;

        foreach ($keys as $existing) {
            if (self::keyId($existing['name']) !== $keepKeyId && ! $existing['disabled']) {
                $this->client->disableKey($existing['name']);
                $disabled++;
            }
        }

        return $disabled;
    }

    public function check(): void
    {
        $this->client->check();
    }

    /**
     * projects/p/serviceAccounts/sa@p.iam.gserviceaccount.com/keys/0123abcd becomes 0123abcd.
     */
    private static function keyId(string $name): string
    {
        $parts = explode('/', $name);

        return (string) end($parts);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resourceName(array $settings, string $keyId): string
    {
        return 'projects/-/serviceAccounts/'.Settings::required($settings, 'service_account_email').'/keys/'.$keyId;
    }
}
