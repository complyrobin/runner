<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Fakes;

use Closure;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use RuntimeException;

/**
 * Answers requests from a list of "METHOD url-substring" routes and records every request it was sent.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<Request> */
    public array $sent = [];

    /** @var array<string, list<Response|Closure(Request): Response>> */
    private array $routes = [];

    /**
     * Queues answers for a route. The last answer repeats once the queue is used up.
     */
    public function on(string $route, Response|Closure ...$answers): self
    {
        $this->routes[$route] = [...($this->routes[$route] ?? []), ...$answers];

        return $this;
    }

    public function send(Request $request): Response
    {
        $this->sent[] = $request;

        foreach ($this->routes as $route => &$answers) {
            [$method, $fragment] = explode(' ', $route, 2);

            if ($method === $request->method && str_contains($request->url, $fragment)) {
                $answer = count($answers) > 1 ? array_shift($answers) : $answers[0];

                return $answer instanceof Closure ? $answer($request) : $answer;
            }
        }

        throw new RuntimeException("No fake answer for {$request->method} {$request->url}");
    }

    /**
     * @return list<Request>
     */
    public function sentTo(string $method, string $fragment): array
    {
        return array_values(array_filter($this->sent, fn (Request $request): bool => $request->method === $method && str_contains($request->url, $fragment)));
    }

    public static function json(array $body, int $status = 200): Response
    {
        return new Response($status, json_encode($body, JSON_THROW_ON_ERROR));
    }
}
