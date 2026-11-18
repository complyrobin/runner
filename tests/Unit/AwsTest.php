<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Unit;

use ComplyRobin\Runner\Aws\AwsCredentials;
use ComplyRobin\Runner\Aws\AwsSource;
use ComplyRobin\Runner\Aws\IamClient;
use ComplyRobin\Runner\Aws\SignatureV4;
use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\Rotator;
use ComplyRobin\Runner\Runner;
use ComplyRobin\Runner\Tests\Fakes\FakeHttpClient;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AwsTest extends TestCase
{
    private const string RUN = '0193c0de-0000-7000-8000-000000000002';

    private const string NEW_ID = 'AKIANEWKEY0000000001';

    private const string NEW_SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYNEWSECRET1';

    private FakeHttpClient $http;

    /** @var list<array{id: string, status: string}> what IAM holds for the user */
    private array $keys = [];

    /** @var list<string> */
    private array $output = [];

    private string $keyPair;

    protected function setUp(): void
    {
        $this->keys = [['id' => 'AKIAOLDKEY0000000009', 'status' => 'Active']];
        $this->keyPair = sodium_crypto_box_keypair();

        $this->http = (new FakeHttpClient)
            ->on('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => [$this->job()]]), FakeHttpClient::json(['jobs' => []]))
            ->on('POST /runner/acme/reports', FakeHttpClient::json(['recorded' => true]))
            ->on('POST iam.amazonaws.com', fn (Request $request): Response => $this->iam($request))
            ->on('GET /secrets/public-key', FakeHttpClient::json(['data' => ['id' => 'kp-1', 'attributes' => ['public_key' => base64_encode(sodium_crypto_box_publickey($this->keyPair))]]]))
            ->on('PATCH /secrets/', FakeHttpClient::json(['data' => ['id' => 'sec']]))
            ->on('POST /environments/env-1/deployments', FakeHttpClient::json(['data' => ['id' => 'dep-1']]));
    }

    #[Test]
    public function it_signs_requests_exactly_as_aws_documents(): void
    {
        // AWS's own worked example for Signature Version 4 (IAM ListUsers, 30 August 2015).
        $headers = SignatureV4::headers(
            new AwsCredentials('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'),
            'GET',
            'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08',
            '',
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            'us-east-1',
            'iam',
            new DateTimeImmutable('2015-08-30T12:36:00Z'),
        );

        $this->assertSame('20150830T123600Z', $headers['X-Amz-Date']);
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, SignedHeaders=content-type;host;x-amz-date, Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7',
            $headers['Authorization'],
        );
    }

    #[Test]
    public function it_signs_the_session_token_of_short_lived_access(): void
    {
        $headers = SignatureV4::headers(new AwsCredentials('ASIAEXAMPLE', 'secret', 'session'), 'POST', 'https://iam.amazonaws.com/', 'a=b', [], 'us-east-1', 'iam', new DateTimeImmutable('2026-11-17T10:00:00Z'));

        $this->assertSame('session', $headers['X-Amz-Security-Token']);
        $this->assertStringContainsString('SignedHeaders=host;x-amz-date;x-amz-security-token,', $headers['Authorization']);
    }

    #[Test]
    public function it_rotates_an_iam_users_key_puts_both_parts_in_place_and_leaves_the_old_key_for_later(): void
    {
        $this->assertSame(Runner::OK, $this->runner()->run());

        $this->assertSame([
            'run' => self::RUN,
            'outcome' => 'succeeded',
            'rotated_at' => '2026-11-17T10:00:00Z',
            'new_key' => ['id' => 'AKIA…0001', 'hint' => '0001', 'expires_at' => null],
            'old_keys_expire_at' => '2026-11-19T10:00:00Z',
            'retire_later' => true,
            'deployments' => ['dep-1'],
        ], $this->reports()[0]);

        $this->assertSame(self::NEW_ID, $this->opened('sec-id'));
        $this->assertSame(self::NEW_SECRET, $this->opened('sec-secret'));
        $this->assertSame('Active', $this->keyStatus('AKIAOLDKEY0000000009'), 'The old key keeps working through the grace period.');
        $this->assertStringContainsString('Old keys keep working until 2026-11-19T10:00:00Z, then the runner switches them off.', implode("\n", $this->output));
    }

    #[Test]
    public function it_never_sends_the_secret_or_the_whole_key_id_to_complyrobin(): void
    {
        $this->runner()->run();

        foreach ($this->http->sentTo('POST', '/runner/acme/') as $request) {
            $this->assertStringNotContainsString(self::NEW_SECRET, (string) json_encode($request->json));
            $this->assertStringNotContainsString(self::NEW_ID, (string) json_encode($request->json));
        }

        $this->assertStringNotContainsString(self::NEW_SECRET, implode("\n", $this->output));
    }

    #[Test]
    public function it_deletes_a_key_an_earlier_retire_switched_off_before_making_the_new_one(): void
    {
        $this->keys = [['id' => 'AKIAOLDKEY0000000009', 'status' => 'Active'], ['id' => 'AKIAOLDERKEY00000008', 'status' => 'Inactive']];

        $this->assertSame(Runner::OK, $this->runner()->run());

        $this->assertNull($this->keyStatus('AKIAOLDERKEY00000008'));
        $this->assertSame('Active', $this->keyStatus(self::NEW_ID));
    }

    #[Test]
    public function it_refuses_to_rotate_when_the_user_already_has_two_working_keys(): void
    {
        $this->keys = [['id' => 'AKIAOLDKEY0000000009', 'status' => 'Active'], ['id' => 'AKIAOTHERKEY00000007', 'status' => 'Active']];

        $this->assertSame(Runner::ROTATION_FAILED, $this->runner()->run());

        $this->assertSame('mint', $this->reports()[0]['step']);
        $this->assertStringContainsString('already has two active access keys', $this->reports()[0]['message']);
        $this->assertSame([], $this->http->sentTo('PATCH', '/secrets/'));
    }

    #[Test]
    public function it_deletes_the_new_key_when_no_secret_took_it(): void
    {
        $this->http = $this->replace('PATCH /secrets/', FakeHttpClient::json(['message' => 'Not found.'], 404));

        $this->assertSame(Runner::ROTATION_FAILED, $this->runner()->run());

        $this->assertSame('push', $this->reports()[0]['step']);
        $this->assertNull($this->keyStatus(self::NEW_ID));
        $this->assertSame('Active', $this->keyStatus('AKIAOLDKEY0000000009'));
    }

    #[Test]
    public function a_retire_job_switches_off_every_key_but_the_new_one(): void
    {
        $this->keys = [['id' => 'AKIAOLDKEY0000000009', 'status' => 'Active'], ['id' => self::NEW_ID, 'status' => 'Active']];
        $this->http = $this->withFirstClaim($this->retireJob('AKIA…0001'));

        $this->assertSame(Runner::OK, $this->runner()->run());

        $this->assertSame('Inactive', $this->keyStatus('AKIAOLDKEY0000000009'));
        $this->assertSame('Active', $this->keyStatus(self::NEW_ID));
        $this->assertSame(['run' => self::RUN, 'action' => 'retire', 'outcome' => 'retired', 'retired_keys' => 1], $this->reports()[0]);
        $this->assertSame([], $this->http->sentTo('PATCH', '/secrets/'));
    }

    #[Test]
    public function a_retire_job_switches_nothing_off_when_the_new_key_is_gone(): void
    {
        $this->keys = [['id' => 'AKIAOLDKEY0000000009', 'status' => 'Active']];
        $this->http = $this->withFirstClaim($this->retireJob('AKIA…0001'));

        $this->assertSame(Runner::ROTATION_FAILED, $this->runner()->run());

        $this->assertSame('Active', $this->keyStatus('AKIAOLDKEY0000000009'));
        $report = $this->reports()[0];
        $this->assertSame(['run', 'action', 'outcome', 'step', 'message'], array_keys($report));
        $this->assertSame('retire', $report['step']);
        $this->assertStringContainsString('no longer active', $report['message']);
    }

    #[Test]
    public function it_sends_aws_access_only_to_aws_and_signed(): void
    {
        $this->runner()->run();

        foreach ($this->http->sent as $request) {
            $toAws = str_starts_with($request->url, 'https://iam.amazonaws.com/');
            $this->assertSame($toAws, str_contains($request->headers['Authorization'] ?? '', 'AKIARUNNERACCESS0001'), $request->url);

            if ($toAws) {
                $this->assertStringNotContainsString('runner-secret', implode(' ', $request->headers).$request->body);
            }
        }
    }

    private function runner(): Runner
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17T10:00:00', new DateTimeZone('UTC'));
        $iam = new IamClient($this->http, new AwsCredentials('AKIARUNNERACCESS0001', 'runner-secret'), $clock);

        return new Runner(
            new ComplyRobinClient($this->http, 'https://api.complyrobin.com/v1', 'acme', 'crr_'.str_repeat('a', 60)),
            new Rotator(['aws' => new AwsSource($iam)], new LaravelCloudClient($this->http, 'lc-token'), $clock),
            ['runner-secret'],
            function (string $line): void {
                $this->output[] = $line;
            },
            static function (int $seconds): void {},
        );
    }

    /**
     * A small IAM: answers the four access key actions from $this->keys, in IAM's XML.
     */
    private function iam(Request $request): Response
    {
        parse_str((string) $request->body, $parameters);
        $this->assertSame('complyrobin-crd-0042', $parameters['UserName'] ?? null);

        switch ($parameters['Action'] ?? '') {
            case 'ListAccessKeys':
                $members = implode('', array_map(static fn (array $key): string => "<member><UserName>complyrobin-crd-0042</UserName><AccessKeyId>{$key['id']}</AccessKeyId><Status>{$key['status']}</Status><CreateDate>2026-08-01T00:00:00Z</CreateDate></member>", $this->keys));

                return new Response(200, "<ListAccessKeysResponse><ListAccessKeysResult><AccessKeyMetadata>{$members}</AccessKeyMetadata><IsTruncated>false</IsTruncated></ListAccessKeysResult></ListAccessKeysResponse>");
            case 'CreateAccessKey':
                $this->keys[] = ['id' => self::NEW_ID, 'status' => 'Active'];

                return new Response(200, '<CreateAccessKeyResponse><CreateAccessKeyResult><AccessKey><UserName>complyrobin-crd-0042</UserName><AccessKeyId>'.self::NEW_ID.'</AccessKeyId><Status>Active</Status><SecretAccessKey>'.self::NEW_SECRET.'</SecretAccessKey></AccessKey></CreateAccessKeyResult></CreateAccessKeyResponse>');
            case 'UpdateAccessKey':
                foreach ($this->keys as $index => $key) {
                    if ($key['id'] === $parameters['AccessKeyId']) {
                        $this->keys[$index]['status'] = $parameters['Status'];
                    }
                }

                return new Response(200, '<UpdateAccessKeyResponse/>');
            case 'DeleteAccessKey':
                $this->keys = array_values(array_filter($this->keys, static fn (array $key): bool => $key['id'] !== $parameters['AccessKeyId']));

                return new Response(200, '<DeleteAccessKeyResponse/>');
        }

        return new Response(400, '<ErrorResponse><Error><Code>InvalidAction</Code><Message>Unknown.</Message></Error></ErrorResponse>');
    }

    private function keyStatus(string $id): ?string
    {
        foreach ($this->keys as $key) {
            if ($key['id'] === $id) {
                return $key['status'];
            }
        }

        return null;
    }

    private function opened(string $secretId): string
    {
        $patch = $this->http->sentTo('PATCH', '/secrets/'.$secretId);
        $this->assertCount(1, $patch);

        return (string) sodium_crypto_box_seal_open(base64_decode($patch[0]->json['value'], true), $this->keyPair);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reports(): array
    {
        return array_map(fn (Request $request): array => $request->json, $this->http->sentTo('POST', '/runner/acme/reports'));
    }

    private function replace(string $route, Response ...$answers): FakeHttpClient
    {
        $property = new \ReflectionProperty(FakeHttpClient::class, 'routes');
        $routes = $property->getValue($this->http);
        $routes[$route] = $answers;
        $property->setValue($this->http, $routes);

        return $this->http;
    }

    private function withFirstClaim(array $job): FakeHttpClient
    {
        return $this->replace('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => [$job]]), FakeHttpClient::json(['jobs' => []]));
    }

    private function job(): array
    {
        return [
            'run' => self::RUN,
            'credential' => ['reference' => 'CRD-0042', 'name' => 'S3 uploads key'],
            'source' => ['type' => 'aws', 'user_name' => 'complyrobin-crd-0042'],
            'destinations' => [
                ['type' => 'laravel_cloud', 'secret_id' => 'sec-id', 'secret_name' => 'AWS_ACCESS_KEY_ID', 'value' => 'key_id', 'environment_ids' => ['env-1'], 'redeploy' => true],
                ['type' => 'laravel_cloud', 'secret_id' => 'sec-secret', 'secret_name' => 'AWS_SECRET_ACCESS_KEY', 'value' => 'key', 'environment_ids' => ['env-1'], 'redeploy' => true],
            ],
            'grace_hours' => 48,
        ];
    }

    private function retireJob(string $keep): array
    {
        return [
            'run' => self::RUN,
            'action' => 'retire',
            'credential' => ['reference' => 'CRD-0042', 'name' => 'S3 uploads key'],
            'source' => ['type' => 'aws', 'user_name' => 'complyrobin-crd-0042'],
            'keep_key' => $keep,
        ];
    }
}
