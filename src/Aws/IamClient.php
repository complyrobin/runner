<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Aws;

use Closure;
use ComplyRobin\Runner\Exceptions\ApiError;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The AWS IAM calls the runner needs, signed with the customer's own AWS access: list, make, switch off and delete
 * an IAM user's access keys. IAM answers in XML; the few fields used are read without an XML extension.
 */
final readonly class IamClient
{
    public const string IAM_URL = 'https://iam.amazonaws.com/';

    public const string STS_URL = 'https://sts.amazonaws.com/';

    /** @var Closure(): DateTimeImmutable */
    private Closure $clock;

    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private HttpClient $http,
        private AwsCredentials $credentials,
        ?Closure $clock = null,
        private string $iamUrl = self::IAM_URL,
        private string $stsUrl = self::STS_URL,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * ListAccessKeys. An IAM user has at most two access keys.
     *
     * @return list<array{id: string, status: string, created_at: string}>
     */
    public function listAccessKeys(string $userName): array
    {
        $xml = $this->iam('ListAccessKeys', ['UserName' => $userName]);
        $keys = [];

        foreach (self::all($xml, 'member') as $member) {
            $id = self::first($member, 'AccessKeyId');

            if ($id !== null) {
                $keys[] = ['id' => $id, 'status' => self::first($member, 'Status') ?? '', 'created_at' => self::first($member, 'CreateDate') ?? ''];
            }
        }

        return $keys;
    }

    /**
     * CreateAccessKey. Returns the new key's id and secret.
     *
     * @return array{id: string, secret: string}
     */
    public function createAccessKey(string $userName): array
    {
        $xml = $this->iam('CreateAccessKey', ['UserName' => $userName]);
        $id = self::first($xml, 'AccessKeyId');
        $secret = self::first($xml, 'SecretAccessKey');

        if ($id === null || $secret === null || $secret === '') {
            throw new ApiError('AWS', 200, 'The answer had no new access key in it.');
        }

        return ['id' => $id, 'secret' => $secret];
    }

    /**
     * UpdateAccessKey with Status=Inactive. The key stops working at once but can be switched back on in AWS.
     */
    public function deactivateAccessKey(string $userName, string $accessKeyId): void
    {
        $this->iam('UpdateAccessKey', ['UserName' => $userName, 'AccessKeyId' => $accessKeyId, 'Status' => 'Inactive']);
    }

    public function deleteAccessKey(string $userName, string $accessKeyId): void
    {
        $this->iam('DeleteAccessKey', ['UserName' => $userName, 'AccessKeyId' => $accessKeyId]);
    }

    /**
     * STS GetCallerIdentity signed with a new access key itself: proves AWS accepts it.
     */
    public function verifyKey(string $accessKeyId, string $secretAccessKey): void
    {
        (new self($this->http, new AwsCredentials($accessKeyId, $secretAccessKey), $this->clock, $this->iamUrl, $this->stsUrl))->check();
    }

    /**
     * STS GetCallerIdentity: works with any valid AWS access and needs no permission.
     */
    public function check(): void
    {
        $this->call($this->stsUrl, 'sts', ['Action' => 'GetCallerIdentity', 'Version' => '2011-06-15']);
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function iam(string $action, array $parameters): string
    {
        return $this->call($this->iamUrl, 'iam', ['Action' => $action, 'Version' => '2010-05-08', ...$parameters]);
    }

    /**
     * IAM and STS are global, signed for us-east-1.
     *
     * @param  array<string, string>  $parameters
     */
    private function call(string $url, string $service, array $parameters): string
    {
        $body = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $headers = ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'];
        $headers += SignatureV4::headers($this->credentials, 'POST', $url, $body, $headers, 'us-east-1', $service, ($this->clock)());

        $response = $this->http->send(new Request('POST', $url, ['Accept' => 'text/xml', ...$headers], body: $body));

        if (! $response->successful()) {
            throw self::error($response);
        }

        return $response->body;
    }

    private static function error(Response $response): ApiError
    {
        $code = self::first($response->body, 'Code');
        $message = self::first($response->body, 'Message');

        return new ApiError('AWS', $response->status, trim(($code !== null ? $code.': ' : '').($message ?? '')));
    }

    private static function first(string $xml, string $tag): ?string
    {
        return preg_match('/<'.$tag.'>(.*?)<\/'.$tag.'>/s', $xml, $match) === 1
            ? html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8')
            : null;
    }

    /**
     * @return list<string>
     */
    private static function all(string $xml, string $tag): array
    {
        preg_match_all('/<'.$tag.'>(.*?)<\/'.$tag.'>/s', $xml, $matches);

        return $matches[1];
    }
}
