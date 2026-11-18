<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\LaravelCloud;

use ComplyRobin\Runner\Destinations\DeploymentState;
use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;

/**
 * The Laravel Cloud API calls the runner needs, with the customer's own API token: the organisation's sealing key,
 * updating a secret with a sealed value, and starting and following a deployment.
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
     * POST /environments/{environment}/deployments. Returns the deployment's id. Laravel Cloud deploys the newest
     * commit on the environment's branch. While the environment's resources are still being set up, it queues the
     * deployment instead (202): that id is returned as "queued:{id}".
     */
    public function deploy(string $environmentId): string
    {
        $response = $this->send('POST', '/environments/'.rawurlencode($environmentId).'/deployments');
        $data = $this->expect($response)['data'] ?? [];
        $id = $data['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw new ApiError('Laravel Cloud', $response->status, 'The answer had no deployment in it.');
        }

        return ($response->status === 202 || ($data['type'] ?? null) === 'queuedDeployments' ? 'queued:' : '').$id;
    }

    /**
     * GET /deployments/{id}, or /queued-deployments/{id} for a queued one.
     */
    public function deployment(string $deploymentId): DeploymentState
    {
        if (str_starts_with($deploymentId, 'queued:')) {
            return $this->queuedDeployment(substr($deploymentId, 7));
        }

        $attributes = $this->expect($this->send('GET', '/deployments/'.rawurlencode($deploymentId)))['data']['attributes'] ?? [];
        $status = (string) ($attributes['status'] ?? 'pending');
        $reason = is_string($attributes['failure_reason'] ?? null) ? $attributes['failure_reason'] : '';

        return match (true) {
            $status === 'deployment.succeeded' => DeploymentState::succeeded(),
            in_array($status, ['failed', 'build.failed', 'deployment.failed', 'cancelled'], true) => DeploymentState::failed($reason !== '' ? $reason : "It ended as {$status}."),
            default => DeploymentState::running(),
        };
    }

    private function queuedDeployment(string $queuedId): DeploymentState
    {
        $json = $this->expect($this->send('GET', '/queued-deployments/'.rawurlencode($queuedId).'?include=deployment'));
        $attributes = $json['data']['attributes'] ?? [];
        $status = (string) ($attributes['status'] ?? 'pending');

        if ($status === 'promoted') {
            $deploymentId = $json['data']['relationships']['deployment']['data']['id'] ?? null;

            return is_string($deploymentId) || is_int($deploymentId) ? $this->deployment((string) $deploymentId) : DeploymentState::running();
        }

        if ($status === 'pending') {
            return DeploymentState::running();
        }

        $reason = is_string($attributes['failure_reason'] ?? null) ? $attributes['failure_reason'] : "The queued deployment ended as {$status}.";

        return DeploymentState::failed($reason);
    }

    /**
     * A cheap call that proves the token works.
     */
    public function check(): void
    {
        $this->keyPair();
    }

    /**
     * POST /secrets: makes a secret, sealed like an update, and returns its id.
     */
    public function createSecret(string $secretName, string $value): string
    {
        $keyPair = $this->keyPair();
        $response = $this->send('POST', '/secrets', [
            'key' => $secretName,
            'key_pair_id' => $keyPair['id'],
            'value' => SealedBox::seal($value, $keyPair['public_key']),
        ]);
        $id = $this->expect($response)['data']['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            throw new ApiError('Laravel Cloud', $response->status, 'The answer had no secret in it.');
        }

        return (string) $id;
    }

    /**
     * POST /environments/{environment}/secrets: attaches secrets to an environment, which reads them on its next
     * deployment.
     *
     * @param  list<string>  $secretIds
     */
    public function attachSecrets(string $environmentId, array $secretIds): void
    {
        $this->expect($this->send('POST', '/environments/'.rawurlencode($environmentId).'/secrets', ['secrets' => $secretIds]));
    }

    /**
     * The secrets attached to an environment, by id and name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function environmentSecrets(string $environmentId): array
    {
        $json = $this->expect($this->send('GET', '/environments/'.rawurlencode($environmentId).'?include=secrets'));

        return self::secretsOf(is_array($json['data'] ?? null) ? $json['data'] : [], is_array($json['included'] ?? null) ? $json['included'] : []);
    }

    /**
     * The organisation's applications, by id and name (up to 500).
     *
     * @return list<array{id: string, name: string}>
     */
    public function applications(): array
    {
        $applications = [];

        foreach ($this->pages('/applications', 5) as $json) {
            foreach (is_array($json['data'] ?? null) ? $json['data'] : [] as $application) {
                if (is_array($application) && (is_string($application['id'] ?? null) || is_int($application['id'] ?? null))) {
                    $applications[] = ['id' => (string) $application['id'], 'name' => self::nameOf($application)];
                }
            }
        }

        return $applications;
    }

    /**
     * An application's environments, each with the secrets attached to it (up to 100 environments).
     *
     * @return list<array{id: string, name: string, secrets: list<array{id: string, name: string}>}>
     */
    public function environments(string $applicationId): array
    {
        $environments = [];

        foreach ($this->pages('/applications/'.rawurlencode($applicationId).'/environments?include=secrets', 1) as $json) {
            $included = is_array($json['included'] ?? null) ? $json['included'] : [];

            foreach (is_array($json['data'] ?? null) ? $json['data'] : [] as $environment) {
                if (is_array($environment) && (is_string($environment['id'] ?? null) || is_int($environment['id'] ?? null))) {
                    $environments[] = ['id' => (string) $environment['id'], 'name' => self::nameOf($environment), 'secrets' => self::secretsOf($environment, $included)];
                }
            }
        }

        return $environments;
    }

    /**
     * Each page of a list, following its next links on this same address.
     *
     * @return list<array<string, mixed>>
     */
    private function pages(string $path, int $maxPages): array
    {
        $pages = [];

        for ($page = 0; $page < $maxPages; $page++) {
            $json = $this->expect($this->send('GET', $path));
            $pages[] = $json;
            $next = $json['links']['next'] ?? null;
            $query = is_string($next) ? parse_url($next, PHP_URL_QUERY) : null;

            if (! is_string($query) || $query === '') {
                break;
            }

            parse_str((string) parse_url($path, PHP_URL_QUERY), $current);
            parse_str($query, $wanted);
            $path = strtok($path, '?').'?'.http_build_query($wanted + $current);
        }

        return $pages;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function nameOf(array $item): string
    {
        $name = $item['attributes']['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : (string) $item['id'];
    }

    /**
     * @param  array<string, mixed>  $environment
     * @param  array<mixed>  $included
     * @return list<array{id: string, name: string}>
     */
    private static function secretsOf(array $environment, array $included): array
    {
        $ids = [];

        foreach ((array) ($environment['relationships']['secrets']['data'] ?? []) as $reference) {
            if (is_array($reference) && (is_string($reference['id'] ?? null) || is_int($reference['id'] ?? null))) {
                $ids[(string) $reference['id']] = true;
            }
        }

        $secrets = [];

        foreach ($included as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'secrets' && isset($ids[(string) ($item['id'] ?? '')]) && is_string($item['attributes']['key'] ?? null)) {
                $secrets[] = ['id' => (string) $item['id'], 'name' => $item['attributes']['key']];
            }
        }

        return $secrets;
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
