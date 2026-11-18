<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Forge;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;

/**
 * The Laravel Forge API calls the runner needs, with the customer's own API token: read and write a site's
 * environment file, start a deployment and follow it.
 */
final readonly class ForgeClient
{
    public const string BASE_URL = 'https://forge.laravel.com/api';

    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $baseUrl = self::BASE_URL,
    ) {}

    public function environment(string $organisation, string $server, string $site): string
    {
        $json = $this->expect($this->send('GET', self::sitePath($organisation, $server, $site).'/environment'));
        $content = $json['data']['attributes']['content'] ?? $json['data']['content'] ?? null;

        if (! is_string($content)) {
            throw new ApiError('Forge', 200, 'The answer had no environment file in it.');
        }

        return $content;
    }

    public function updateEnvironment(string $organisation, string $server, string $site, string $content): void
    {
        $this->expect($this->send('PUT', self::sitePath($organisation, $server, $site).'/environment', ['environment' => $content]));
    }

    /**
     * Starts a deployment of the site's branch. Returns its id.
     */
    public function deploy(string $organisation, string $server, string $site): string
    {
        $response = $this->send('POST', self::sitePath($organisation, $server, $site).'/deployments');
        $id = $this->expect($response)['data']['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw new ApiError('Forge', $response->status, 'The answer had no deployment in it.');
        }

        return (string) $id;
    }

    /**
     * A deployment's status, as Forge names it: pending, queued, deploying, finished, failed or cancelled.
     */
    public function deploymentStatus(string $organisation, string $server, string $site, string $deploymentId): string
    {
        $data = $this->expect($this->send('GET', self::sitePath($organisation, $server, $site).'/deployments/'.rawurlencode($deploymentId)))['data'] ?? [];

        return (string) ($data['attributes']['status'] ?? $data['status'] ?? 'pending');
    }

    /**
     * GET /user: proves the token works.
     */
    public function check(): void
    {
        $this->expect($this->send('GET', '/user'));
    }

    private static function sitePath(string $organisation, string $server, string $site): string
    {
        return '/orgs/'.rawurlencode($organisation).'/servers/'.rawurlencode($server).'/sites/'.rawurlencode($site);
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
            throw ApiError::from('Forge', $response);
        }

        return $response->json();
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '[hidden]'];
    }
}
