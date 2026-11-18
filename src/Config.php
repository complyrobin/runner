<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use ComplyRobin\Runner\Aws\AwsCredentials;
use ComplyRobin\Runner\Exceptions\ConfigurationError;

/**
 * The runner's settings, read from environment variables. Tokens are kept out of every message and dump.
 */
final readonly class Config
{
    public const string DEFAULT_API_URL = 'https://api.complyrobin.com/v1';

    public function __construct(
        public string $apiUrl,
        public string $organisation,
        private string $runnerToken,
        private ?string $openAiAdminKey,
        private ?string $laravelCloudToken,
        public bool $inGitHubActions = false,
        private ?AwsCredentials $aws = null,
        private ?string $googleCloudAccessToken = null,
        private ?string $googleCloudCredentials = null,
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

        $token = $value('COMPLYROBIN_RUNNER_TOKEN') ?? throw new ConfigurationError('Set COMPLYROBIN_RUNNER_TOKEN to the token ComplyRobin showed when you added the runner.');
        $organisation = $value('COMPLYROBIN_ORGANISATION') ?? throw new ConfigurationError('Set COMPLYROBIN_ORGANISATION to your organisation\'s name in ComplyRobin addresses (the part after complyrobin.com/).');

        if (preg_match('/^crr_[A-Za-z0-9]{60}$/', $token) !== 1) {
            throw new ConfigurationError('COMPLYROBIN_RUNNER_TOKEN is not a runner token: it starts with crr_ followed by 60 letters and digits.');
        }

        if (preg_match('/^[a-z0-9-]{3,40}$/', $organisation) !== 1) {
            throw new ConfigurationError('COMPLYROBIN_ORGANISATION should be lower-case letters, digits and dashes, as in your ComplyRobin address.');
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
            organisation: $organisation,
            runnerToken: $token,
            openAiAdminKey: $value('OPENAI_ADMIN_KEY'),
            laravelCloudToken: $value('LARAVEL_CLOUD_API_TOKEN'),
            inGitHubActions: $value('GITHUB_ACTIONS') === 'true',
            aws: $awsKeyId !== null && $awsSecret !== null ? new AwsCredentials($awsKeyId, $awsSecret, $value('AWS_SESSION_TOKEN')) : null,
            googleCloudAccessToken: $value('GOOGLE_CLOUD_ACCESS_TOKEN'),
            googleCloudCredentials: $value('GOOGLE_CLOUD_CREDENTIALS'),
        );
    }

    public function runnerToken(): string
    {
        return $this->runnerToken;
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

    /**
     * Every token the runner holds, to keep out of all output.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $googlePrivateKey = json_decode((string) $this->googleCloudCredentials, true)['private_key'] ?? null;

        return array_values(array_filter([
            $this->runnerToken,
            $this->openAiAdminKey,
            $this->laravelCloudToken,
            $this->aws?->secretAccessKey,
            $this->aws?->sessionToken,
            $this->googleCloudAccessToken,
            $this->googleCloudCredentials,
            is_string($googlePrivateKey) ? $googlePrivateKey : null,
        ], is_string(...)));
    }

    public function __debugInfo(): array
    {
        return ['apiUrl' => $this->apiUrl, 'organisation' => $this->organisation, 'tokens' => '[hidden]'];
    }
}
