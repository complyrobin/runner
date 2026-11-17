<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\LaravelCloud;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;

/**
 * The Laravel Cloud API calls the runner needs, with the customer's own API token: the organisation's sealing key,
 * updating a secret with a sealed value, and starting a deployment.
 */
final class LaravelCloudClient
{
    public const string BASE_URL = 'https://cloud.laravel.com/api';

    /** @var array{id: string, public_key: string}|null */
    private ?array $keyPair = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    /**
     * GET /secrets/public-key, once per run.
     *
     * @return array{id: string, public_key: string}
     */
    public function keyPair(): array
    {
        if ($this->keyPair !== null) {
            return $this->keyPair;
        }

        $data = $this->expect($this->send('GET', '/secrets/public-key'))['data'] ?? [];
        $id = $data['id'] ?? null;
        $publicKey = $data['attributes']['public_key'] ?? null;

        if (! is_string($id) || ! is_string($publicKey)) {
            throw new ApiError('Laravel Cloud', 200, 'The answer had no public key in it.');
        }

        return $this->keyPair = ['id' => $id, 'public_key' => $publicKey];
    }

    /**
     * PATCH /secrets/{secret}: the value is sealed here, just before it is sent.
     */
    public function updateSecret(string $secretId, string $secretName, string $value): void
    {
        $keyPair = $this->keyPair();

        $this->expect($this->send('PATCH', '/secrets/'.rawurlencode($secretId), [
            'key' => $secretName,
            'key_pair_id' => $keyPair['id'],
            'value' => SealedBox::seal($value, $keyPair['public_key']),
        ]));
    }

    /**
     * POST /environments/{environment}/deployments. Returns the deployment's id.
     */
    public function deploy(string $environmentId): string
    {
        $id = $this->expect($this->send('POST', '/environments/'.rawurlencode($environmentId).'/deployments'))['data']['id'] ?? null;

        return is_string($id) ? $id : '';
    }

    /**
     * A cheap call that proves the token works.
     */
    public function check(): void
    {
        $this->keyPair();
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, ?array $json = null): Response
    {
        return $this->http->send(new Request($method, rtrim($this->baseUrl, '/').$path, ['Authorization' => 'Bearer '.$this->token], $json));
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(Response $response): array
    {
        if (! $response->successful()) {
            throw ApiError::from('Laravel Cloud', $response);
        }

        return $response->json();
    }
}
