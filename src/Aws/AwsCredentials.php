<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

/**
 * The runner's own AWS access: an access key, or the short-lived one GitHub's OIDC sign-in
 * (aws-actions/configure-aws-credentials) gives, with its session token. Never printed.
 */
final readonly class AwsCredentials
{
    public function __construct(
        public string $accessKeyId,
        public string $secretAccessKey,
        public ?string $sessionToken = null,
    ) {}

    public function __debugInfo(): array
    {
        return ['accessKeyId' => '[hidden]', 'secretAccessKey' => '[hidden]', 'sessionToken' => '[hidden]'];
    }
}
