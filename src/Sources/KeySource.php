<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Sources;

use ComplyRobin\Runner\NewKey;

/**
 * A provider the runner makes keys with, using the customer's own access to it. Each credential has its own
 * identity at the provider (an OpenAI service account, an AWS IAM user, a Google Cloud service account), so
 * "every other key" of that identity is exactly the old keys.
 *
 * Settings are the ids ComplyRobin sends in a job's source, never a key.
 */
interface KeySource
{
    /** The provider's name, as people know it. */
    public function name(): string;

    /**
     * Makes a new key.
     *
     * @param  array<string, mixed>  $settings
     */
    public function mint(array $settings, string $keyName, ?int $lifetimeSeconds): NewKey;

    /**
     * Deletes a new key that no destination received.
     *
     * @param  array<string, mixed>  $settings
     */
    public function discard(array $settings, NewKey $key): void;

    /**
     * True when the provider can set a key to stop working at a time, so the old keys are retired during the
     * rotation itself. False when the old keys must be switched off later, by a retire job from ComplyRobin once
     * the grace period has passed.
     */
    public function expiresKeysItself(): bool;

    /**
     * Retires the identity's other keys after the grace period. A provider that expires keys itself sets them to
     * expire; one that doesn't leaves them working. Returns how many other keys still need retiring.
     *
     * @param  array<string, mixed>  $settings
     * @param  int  $now  the runner's clock, as a Unix time
     */
    public function retireOthers(array $settings, NewKey $key, int $graceSeconds, int $now): int;

    /**
     * Switches off every key of the identity except the one ComplyRobin recorded as the newest. Refuses when that
     * key is gone, so a mistake can never switch off the key the app is using. Returns how many were switched off.
     *
     * @param  array<string, mixed>  $settings
     * @param  int  $now  the runner's clock, as a Unix time
     */
    public function switchOffOthers(array $settings, string $keepKeyId, int $now): int;

    /** A cheap call that proves the runner's access works. */
    public function check(): void;
}
