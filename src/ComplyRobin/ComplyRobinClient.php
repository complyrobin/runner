<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\ComplyRobin;

use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;

/**
 * The runner endpoints: claim due work, report how it went, and send the results of a test. None of them ever
 * receives a key. The token names the organisation, so the addresses don't.
 */
final readonly class ComplyRobinClient
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
        private string $token,
    ) {}

    /**
     * @throws ApiError
     */
    public function claim(): Claim
    {
        $response = $this->post('claims', null);

        if (! $response->successful()) {
            throw ApiError::from('ComplyRobin', $response);
        }

        $json = $response->json();
        $jobs = $json['jobs'] ?? [];

        return new Claim(
            array_map(Job::fromArray(...), array_values(array_filter(is_array($jobs) ? $jobs : [], is_array(...)))),
            ($json['check_requested'] ?? false) === true,
        );
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
     * Sends the results of a test of the runner's access, which ComplyRobin shows on the Rotation runners page.
     *
     * @param  list<array{service: string, status: string, message: string}>  $results
     *
     * @throws ApiError
     */
    public function sendCheck(array $results): void
    {
        $response = $this->post('checks', ['services' => $results]);

        if (! $response->successful()) {
            throw ApiError::from('ComplyRobin', $response);
        }
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    private function post(string $endpoint, ?array $json): Response
    {
        return $this->http->send(new Request(
            'POST',
            rtrim($this->baseUrl, '/').'/runner/'.$endpoint,
            ['Authorization' => 'Bearer '.$this->token],
            $json,
        ));
    }
}
