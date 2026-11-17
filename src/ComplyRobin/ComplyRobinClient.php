<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\ComplyRobin;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;

/**
 * The two runner endpoints: claim due work, report how it went. Neither ever receives a key.
 */
final readonly class ComplyRobinClient
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $organisation,
        private string $token,
    ) {}

    /**
     * @return list<Job>
     *
     * @throws ApiError
     */
    public function claim(): array
    {
        $response = $this->post('claims', null);

        if (! $response->successful()) {
            throw ApiError::from('ComplyRobin', $response);
        }

        $jobs = $response->json()['jobs'] ?? [];

        return array_map(Job::fromArray(...), array_values(array_filter(is_array($jobs) ? $jobs : [], is_array(...))));
    }

    /**
     * Sends a report. The answer is returned as is: 200 and 409 both mean ComplyRobin has the run's outcome.
     *
     * @param  array<string, mixed>  $report
     */
    public function report(array $report): Response
    {
        return $this->post('reports', $report);
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function post(string $endpoint, ?array $json): Response
    {
        return $this->http->send(new Request(
            'POST',
            rtrim($this->baseUrl, '/').'/runner/'.rawurlencode($this->organisation).'/'.$endpoint,
            ['Authorization' => 'Bearer '.$this->token],
            $json,
        ));
    }
}
