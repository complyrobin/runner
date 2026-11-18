<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\GitHub;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use ComplyRobin\Runner\LaravelCloud\SealedBox;

/**
 * The GitHub API calls the runner needs, with the customer's own token: a repository's (or one of its
 * environments') public key, and writing an Actions secret sealed to it. GitHub can't show a secret's value back.
 */
final class GitHubClient
{
    public const string BASE_URL = 'https://api.github.com';

    /** @var array<string, array{key_id: string, key: string}> by secrets path */
    private array $publicKeys = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $token,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    /**
     * PUT /repos/{owner}/{repo}/actions/secrets/{name}, or the environment's secrets when there is one.
     */
    public function putSecret(string $repository, ?string $environment, string $name, string $value): void
    {
        $path = self::secretsPath($repository, $environment);
        $publicKey = $this->publicKey($path);

        $this->expect($this->send('PUT', $path.'/'.rawurlencode($name), [
            'encrypted_value' => SealedBox::seal($value, $publicKey['key'], 'GitHub'),
            'key_id' => $publicKey['key_id'],
        ]));
    }

    /**
     * The repositories the token can see, as owner/name (up to 300).
     *
     * @return list<string>
     */
    public function repositories(): array
    {
        $repositories = [];

        for ($page = 1; $page <= 3; $page++) {
            $json = $this->expect($this->send('GET', '/user/repos?'.http_build_query(['per_page' => 100, 'page' => $page, 'sort' => 'full_name'])));

            foreach ($json as $repository) {
                if (is_array($repository) && is_string($repository['full_name'] ?? null)) {
                    $repositories[] = $repository['full_name'];
                }
            }

            if (count($json) < 100) {
                break;
            }
        }

        return $repositories;
    }

    /**
     * GET /rate_limit: answers any valid token, whatever it may do.
     */
    public function check(): void
    {
        $this->expect($this->send('GET', '/rate_limit'));
    }

    /**
     * @return array{key_id: string, key: string}
     */
    private function publicKey(string $path): array
    {
        if (isset($this->publicKeys[$path])) {
            return $this->publicKeys[$path];
        }

        $json = $this->expect($this->send('GET', $path.'/public-key'));

        if (! is_string($json['key_id'] ?? null) || ! is_string($json['key'] ?? null)) {
            throw new ApiError('GitHub', 200, 'The answer had no public key in it.');
        }

        return $this->publicKeys[$path] = ['key_id' => $json['key_id'], 'key' => $json['key']];
    }

    private static function secretsPath(string $repository, ?string $environment): string
    {
        [$owner, $name] = explode('/', $repository, 2);
        $path = '/repos/'.rawurlencode($owner).'/'.rawurlencode($name);

        return $environment === null ? $path.'/actions/secrets' : $path.'/environments/'.rawurlencode($environment).'/secrets';
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, ?array $json = null): Response
    {
        return $this->http->send(new Request($method, rtrim($this->baseUrl, '/').$path, [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ], $json));
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(Response $response): array
    {
        if (! $response->successful()) {
            throw ApiError::from('GitHub', $response);
        }

        return $response->json();
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '[hidden]'];
    }
}
