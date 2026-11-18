<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

use Closure;
use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AWS services that speak JSON (Secrets Manager, Systems Manager), signed with the customer's own AWS access. The
 * region is checked before it becomes part of the address, so a setting can never send a signed request elsewhere.
 */
final readonly class AwsJsonClient
{
    public const string REGION = '/^[a-z]{2}(-[a-z]+)+-\d{1,2}$/';

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     * @param  string|null  $endpoint  for tests: replaces https://{service}.{region}.amazonaws.com/
     */
    public function __construct(
        private HttpClient $http,
        private AwsCredentials $credentials,
        ?Closure $clock = null,
        private ?string $endpoint = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Calls one action, such as secretsmanager / secretsmanager.PutSecretValue.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    public function call(string $service, string $region, string $target, array $parameters): array
    {
        if (preg_match(self::REGION, $region) !== 1) {
            throw new InvalidArgumentException("\"{$region}\" is not an AWS region, such as eu-west-1.");
        }

        $url = $this->endpoint ?? "https://{$service}.{$region}.amazonaws.com/";
        $body = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/x-amz-json-1.1', 'X-Amz-Target' => $target];
        $headers += SignatureV4::headers($this->credentials, 'POST', $url, $body, $headers, $region, $service, ($this->clock)());

        $response = $this->http->send(new Request('POST', $url, $headers, body: $body));

        if (! $response->successful()) {
            $json = $response->json();
            $type = is_string($json['__type'] ?? null) ? preg_replace('/^.*#/', '', $json['__type']) : null;
            $message = $json['message'] ?? $json['Message'] ?? '';

            throw new ApiError('AWS', $response->status, trim(($type !== null ? $type.': ' : '').(is_string($message) ? $message : '')));
        }

        return $response->json();
    }
}
