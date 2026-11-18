<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Destinations;

/**
 * A place the runner puts a new key, with the customer's own access to it: a Laravel Cloud secret, a GitHub secret,
 * an AWS or Google Cloud secret, a Forge site's environment, or a Vercel environment variable. Settings are the ids
 * ComplyRobin sends in a job's destination, never a key.
 *
 * Some destinations can also redeploy the app so it starts using the key. The runner waits for every redeploy to
 * finish before the old key is retired.
 */
interface KeyDestination
{
    /** The service's name, as people know it. */
    public function name(): string;

    /**
     * What this destination is, for messages: e.g. "the Laravel Cloud secret OPENAI_API_KEY". Ids only.
     *
     * @param  array<string, mixed>  $settings
     */
    public function describe(array $settings): string;

    /**
     * Writes the value. The value must never appear in an exception message.
     *
     * @param  array<string, mixed>  $settings
     */
    public function put(array $settings, string $value): void;

    /**
     * What to redeploy after the value changed, as targets deploy() understands. Empty when this destination
     * doesn't redeploy (the app reads the value itself, or the customer redeploys some other way).
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function redeployTargets(array $settings): array;

    /**
     * Starts a deployment of a target. Returns the deployment's id.
     */
    public function deploy(string $target): string;

    /**
     * How a deployment is going.
     */
    public function deployment(string $target, string $deploymentId): DeploymentState;

    /** A cheap call that proves the runner's access works. */
    public function check(): void;
}
