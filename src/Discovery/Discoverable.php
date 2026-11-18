<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Discovery;

/**
 * A service that can list what the runner's access to it can see: projects, apps, environments, repositories. The
 * runner sends these lists to ComplyRobin with a test, so people pick from them when they set up a credential instead
 * of copying ids. Names and ids only: never a key, a token or a secret's value.
 */
interface Discoverable
{
    /** The list's name in the test results, such as openai or laravel_cloud. */
    public function inventoryName(): string;

    /**
     * @return array<string, mixed>
     */
    public function discover(): array;
}
