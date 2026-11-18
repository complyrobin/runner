<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\LaravelCloud;

use InvalidArgumentException;

/**
 * Seals a value to a public key with libsodium's crypto_box_seal, base64 encoded. Only the holder of the matching
 * private key (Laravel Cloud, or GitHub for its secrets) can open it, so the value is never sent in the clear.
 */
final class SealedBox
{
    public static function seal(string $value, string $publicKeyBase64, string $service = 'Laravel Cloud'): string
    {
        $publicKey = base64_decode($publicKeyBase64, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new InvalidArgumentException("{$service} sent a public key that is not 32 bytes of base64.");
        }

        return base64_encode(sodium_crypto_box_seal($value, $publicKey));
    }
}
