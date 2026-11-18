<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use ComplyRobin\Runner\Aws\AwsCredentials;
use ComplyRobin\Runner\Exceptions\ConfigurationError;

/**
 * The runner's settings, read from environment variables. Tokens are kept out of every message and dump.
 *
 * The ComplyRobin token names its organisation (crr_, the organisation's id, _, then 48 random characters), so it is
 * the only ComplyRobin setting a runner needs.
 */
final readonly class Config
{
    public const string DEFAULT_API_URL = 'https://api.complyrobin.com/v1';

    public const string TOKEN_PATTERN = '/^crr_[0-9a-f]{32}_[A-Za-z0-9]{48}$/';

    public function __construct(
        public string $apiUrl,
        private string $token,
        private ?string $openAiAdminKey = null,
        private ?string $laravelCloudToken = null,
        public bool $inGitHubActions = false,
        private ?AwsCredentials $aws = null,
        private ?string $googleCloudAccessToken = null,
        private ?string $googleCloudCredentials = null,
        private ?string $gitHubToken = null,
        private ?string $forgeToken = null,
        private ?string $vercelToken = null,
    ) {}

    /**
     * @param  array<string, string|false|null>  $env
     */
    public static function fromEnvironment(array $env): self
    {
        $value = static function (string $name) use ($env): ?string {
            $found = $env[$name] ?? null;

            return is_string($found) && trim($found) !== '' ? trim($found) : null;
        };

        $token = $value('COMPLYROBIN_TOKEN') ?? throw new ConfigurationError('Set COMPLYROBIN_TOKEN to the token ComplyRobin showed when you added the runner.');

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new ConfigurationError('COMPLYROBIN_TOKEN is not a runner token. Copy it again from ComplyRobin: it starts with crr_ and is 85 characters long.');
        }

        $apiUrl = $value('COMPLYROBIN_API_URL') ?? self::DEFAULT_API_URL;

        if (filter_var($apiUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationError('COMPLYROBIN_API_URL is not a web address.');
        }

        $awsKeyId = $value('AWS_ACCESS_KEY_ID');
        $awsSecret = $value('AWS_SECRET_ACCESS_KEY');

        if (($awsKeyId === null) !== ($awsSecret === null)) {
            throw new ConfigurationError('Set both AWS_ACCESS_KEY_ID and AWS_SECRET_ACCESS_KEY, or neither.');
        }

        return new self(
            apiUrl: $apiUrl,
            token: $token,
            openAiAdminKey: $value('OPENAI_ADMIN_KEY'),
            laravelCloudToken: $value('LARAVEL_CLOUD_API_TOKEN'),
            inGitHubActions: $value('GITHUB_ACTIONS') === 'true',
            aws: $awsKeyId !== null && $awsSecret !== null ? new AwsCredentials($awsKeyId, $awsSecret, $value('AWS_SESSION_TOKEN')) : null,
            googleCloudAccessToken: $value('GOOGLE_CLOUD_ACCESS_TOKEN'),
            googleCloudCredentials: $value('GOOGLE_CLOUD_CREDENTIALS'),
            gitHubToken: $value('GH_SECRETS_TOKEN'),
            forgeToken: $value('FORGE_API_TOKEN'),
            vercelToken: $value('VERCEL_TOKEN'),
        );
    }

    public function token(): string
    {
        return $this->token;
    }

    public function openAiAdminKey(): ?string
    {
        return $this->openAiAdminKey;
    }

    public function laravelCloudToken(): ?string
    {
        return $this->laravelCloudToken;
    }

    public function aws(): ?AwsCredentials
    {
        return $this->aws;
    }

    public function googleCloudAccessToken(): ?string
    {
        return $this->googleCloudAccessToken;
    }

    public function googleCloudCredentials(): ?string
    {
        return $this->googleCloudCredentials;
    }

    public function gitHubToken(): ?string
    {
        return $this->gitHubToken;
    }

    public function forgeToken(): ?string
    {
        return $this->forgeToken;
    }

    public function vercelToken(): ?string
    {
        return $this->vercelToken;
    }

    /**
     * Every token the runner holds, to keep out of all output.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $googlePrivateKey = json_decode((string) $this->googleCloudCredentials, true)['private_key'] ?? null;

        return array_values(array_filter([
            $this->token,
            $this->openAiAdminKey,
            $this->laravelCloudToken,
            $this->aws?->secretAccessKey,
            $this->aws?->sessionToken,
            $this->googleCloudAccessToken,
            $this->googleCloudCredentials,
            is_string($googlePrivateKey) ? $googlePrivateKey : null,
            $this->gitHubToken,
            $this->forgeToken,
            $this->vercelToken,
        ], is_string(...)));
    }

    public function __debugInfo(): array
    {
        return ['apiUrl' => $this->apiUrl, 'tokens' => '[hidden]'];
    }
}
