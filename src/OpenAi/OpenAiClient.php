<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\OpenAi;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use ComplyRobin\Runner\NewKey;

/**
 * The OpenAI Admin API calls the runner needs, with an admin key from the customer's own organisation:
 * make a key for a service account, list a project's keys, set when a key expires, delete a key.
 */
final readonly class OpenAiClient
{
    public const string BASE_URL = 'https://api.openai.com/v1';

    public function __construct(
        private HttpClient $http,
        private string $adminKey,
        private string $baseUrl = self::BASE_URL,
    ) {}

    /**
     * POST /organization/projects/{project}/service_accounts/{service_account}/api_keys
     *
     * @param  array<string, string>  $identity  passed on to the key, for ComplyRobin to keep
     */
    public function createServiceAccountKey(string $projectId, string $serviceAccountId, string $name, ?int $expiresInSeconds, array $identity = []): NewKey
    {
        $body = ['name' => $name];

        if ($expiresInSeconds !== null) {
            $body['expires_in_seconds'] = $expiresInSeconds;
        }

        $json = $this->expect($this->send('POST', '/organization/projects/'.rawurlencode($projectId).'/service_accounts/'.rawurlencode($serviceAccountId).'/api_keys', $body));

        if (! is_string($json['id'] ?? null) || ! is_string($json['value'] ?? null) || $json['value'] === '') {
            throw new ApiError('OpenAI', 200, 'The answer had no new key in it.');
        }

        return new NewKey($json['id'], $json['value'], is_int($json['expires_at'] ?? null) ? $json['expires_at'] : null, identity: $identity);
    }

    /**
     * POST /organization/projects/{project}/service_accounts: makes a service account, which comes with its first
     * key. That key is the new key of the rotation.
     *
     * @return array{id: string, key: NewKey}
     */
    public function createServiceAccount(string $projectId, string $name, ?int $expiresInSeconds): array
    {
        $body = ['name' => $name];

        if ($expiresInSeconds !== null) {
            $body['expires_in_seconds'] = $expiresInSeconds;
        }

        $json = $this->expect($this->send('POST', '/organization/projects/'.rawurlencode($projectId).'/service_accounts', $body));
        $key = is_array($json['api_key'] ?? null) ? $json['api_key'] : [];

        if (! is_string($json['id'] ?? null) || ! is_string($key['id'] ?? null) || ! is_string($key['value'] ?? null) || $key['value'] === '') {
            throw new ApiError('OpenAI', 200, 'The answer had no new service account key in it.');
        }

        return ['id' => $json['id'], 'key' => new NewKey($key['id'], $key['value'], is_int($key['expires_at'] ?? null) ? $key['expires_at'] : null, identity: ['service_account_id' => $json['id']])];
    }

    /**
     * The organisation's active projects, by id and name (up to 1,000).
     *
     * @return list<array{id: string, name: string}>
     */
    public function projects(): array
    {
        return $this->names('/organization/projects', 10);
    }

    /**
     * A project's service accounts, by id and name (up to 500).
     *
     * @return list<array{id: string, name: string}>
     */
    public function serviceAccounts(string $projectId): array
    {
        return $this->names('/organization/projects/'.rawurlencode($projectId).'/service_accounts', 5);
    }

    /**
     * Every enabled key in the project, following the list's pages.
     *
     * @return list<array<string, mixed>>
     */
    public function listProjectKeys(string $projectId): array
    {
        $keys = [];
        $after = null;

        for ($page = 0; $page < 100; $page++) {
            $query = ['limit' => 100, 'owner_project_access' => 'any'] + ($after !== null ? ['after' => $after] : []);
            $json = $this->expect($this->send('GET', '/organization/projects/'.rawurlencode($projectId).'/api_keys?'.http_build_query($query)));

            foreach (is_array($json['data'] ?? null) ? $json['data'] : [] as $key) {
                if (is_array($key)) {
                    $keys[] = $key;
                }
            }

            if (($json['has_more'] ?? false) !== true || ! is_string($json['last_id'] ?? null)) {
                break;
            }

            $after = $json['last_id'];
        }

        return $keys;
    }

    /**
     * POST /organization/projects/{project}/api_keys/{key} with expires_in_seconds.
     */
    public function expireKeyIn(string $projectId, string $keyId, int $seconds): void
    {
        $this->expect($this->send('POST', '/organization/projects/'.rawurlencode($projectId).'/api_keys/'.rawurlencode($keyId), ['expires_in_seconds' => $seconds]));
    }

    public function deleteKey(string $projectId, string $keyId): void
    {
        $this->expect($this->send('DELETE', '/organization/projects/'.rawurlencode($projectId).'/api_keys/'.rawurlencode($keyId)));
    }

    /**
     * GET /v1/models with the new key itself. Only a 401 means the key doesn't work: a key whose service account may
     * not list models is answered 403, which still proves OpenAI accepts it.
     */
    public function verifyKey(string $key): void
    {
        $response = $this->http->send(new Request('GET', rtrim($this->baseUrl, '/').'/models?limit=1', ['Authorization' => 'Bearer '.$key]));

        if ($response->status !== 403 && ! $response->successful()) {
            throw ApiError::from('OpenAI', $response);
        }
    }

    /**
     * A cheap call that proves the admin key works.
     */
    public function check(): void
    {
        $this->expect($this->send('GET', '/organization/projects?limit=1'));
    }

    /**
     * The id and name of each item of a list, following its pages.
     *
     * @return list<array{id: string, name: string}>
     */
    private function names(string $path, int $maxPages): array
    {
        $items = [];
        $after = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $query = ['limit' => 100] + ($after !== null ? ['after' => $after] : []);
            $json = $this->expect($this->send('GET', $path.'?'.http_build_query($query)));

            foreach (is_array($json['data'] ?? null) ? $json['data'] : [] as $item) {
                if (is_array($item) && is_string($item['id'] ?? null)) {
                    $items[] = ['id' => $item['id'], 'name' => is_string($item['name'] ?? null) && $item['name'] !== '' ? $item['name'] : $item['id']];
                }
            }

            if (($json['has_more'] ?? false) !== true || ! is_string($json['last_id'] ?? null)) {
                break;
            }

            $after = $json['last_id'];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, ?array $json = null): Response
    {
        return $this->http->send(new Request($method, rtrim($this->baseUrl, '/').$path, ['Authorization' => 'Bearer '.$this->adminKey], $json));
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(Response $response): array
    {
        if (! $response->successful()) {
            throw ApiError::from('OpenAI', $response);
        }

        return $response->json();
    }
}
