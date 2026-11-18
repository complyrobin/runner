<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Vercel;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;

/**
 * The Vercel API calls the runner needs, with the customer's own token: set a project's environment variable,
 * redeploy its current production deployment, and follow that deployment.
 */
final readonly class VercelClient
{
    public const string BASE_URL = 'https://api.vercel.com';

    public function __construct(
        private HttpClient $http,
        private string $token,
        private string $baseUrl = self::BASE_URL,
    ) {}

    /**
     * POST /v10/projects/{project}/env?upsert=true: creates the variable or replaces its value, encrypted.
     *
     * @param  list<string>  $targets  production, preview, development
     */
    public function setVariable(string $project, ?string $team, string $name, string $value, array $targets): void
    {
        $this->expect($this->send('POST', '/v10/projects/'.rawurlencode($project).'/env', $team, ['upsert' => 'true'], [
            'key' => $name,
            'value' => $value,
            'type' => 'encrypted',
            'target' => $targets,
        ]));
    }

    /**
     * Redeploys the project's newest ready production deployment: the same code, built again with the new value.
     * Returns the new deployment's id.
     */
    public function redeployProduction(string $project, ?string $team): string
    {
        $latest = $this->expect($this->send('GET', '/v6/deployments', $team, [
            'projectId' => $project,
            'target' => 'production',
            'state' => 'READY',
            'limit' => '1',
        ]))['deployments'][0] ?? null;

        if (! is_array($latest) || ! is_string($latest['uid'] ?? null)) {
            throw new ApiError('Vercel', 200, 'The project has no ready production deployment to redeploy.');
        }

        $response = $this->send('POST', '/v13/deployments', $team, [], [
            'name' => (string) ($latest['name'] ?? $project),
            'deploymentId' => $latest['uid'],
            'target' => 'production',
        ]);
        $id = $this->expect($response)['id'] ?? null;

        if (! is_string($id)) {
            throw new ApiError('Vercel', $response->status, 'The answer had no deployment in it.');
        }

        return $id;
    }

    /**
     * A deployment's state, as Vercel names it: QUEUED, INITIALIZING, BUILDING, READY, ERROR or CANCELED.
     */
    public function deploymentState(string $deploymentId, ?string $team): string
    {
        $json = $this->expect($this->send('GET', '/v13/deployments/'.rawurlencode($deploymentId), $team));

        return (string) ($json['readyState'] ?? $json['status'] ?? 'QUEUED');
    }

    /**
     * GET /v2/user: proves the token works.
     */
    public function check(): void
    {
        $this->expect($this->send('GET', '/v2/user', null));
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, ?string $team, array $query = [], ?array $json = null): Response
    {
        $query += $team !== null ? ['teamId' => $team] : [];
        $url = rtrim($this->baseUrl, '/').$path.($query === [] ? '' : '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        return $this->http->send(new Request($method, $url, ['Authorization' => 'Bearer '.$this->token], $json));
    }

    /**
     * @return array<string, mixed>
     */
    private function expect(Response $response): array
    {
        if (! $response->successful()) {
            throw ApiError::from('Vercel', $response);
        }

        return $response->json();
    }

    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'token' => '[hidden]'];
    }
}
