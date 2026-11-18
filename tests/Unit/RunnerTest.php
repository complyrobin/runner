<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Unit;

use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Http\Response;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\OpenAi\OpenAiClient;
use ComplyRobin\Runner\OpenAi\OpenAiSource;
use ComplyRobin\Runner\Rotator;
use ComplyRobin\Runner\Runner;
use ComplyRobin\Runner\Tests\Fakes\FakeHttpClient;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunnerTest extends TestCase
{
    private const string RUNNER_TOKEN = 'crr_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string RUN = '0193c0de-0000-7000-8000-000000000001';

    private FakeHttpClient $http;

    private string $newKey;

    private string $secretKey;

    /** @var list<string> */
    private array $output = [];

    protected function setUp(): void
    {
        $this->newKey = 'sk-svcacct-'.str_repeat('Ab3xYz9Q', 8).'WXYZ';
        $keyPair = sodium_crypto_box_keypair();
        $this->secretKey = $keyPair;  // crypto_box_seal_open takes the whole key pair

        $this->http = (new FakeHttpClient)
            ->on('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => [$this->job()]]), FakeHttpClient::json(['jobs' => []]))
            ->on('POST /runner/acme/reports', FakeHttpClient::json(['recorded' => true]))
            ->on('POST /service_accounts/svc_acct_abc/api_keys', fn (Request $request): Response => FakeHttpClient::json([
                'object' => 'organization.project.service_account.api_key',
                'id' => 'key_new',
                'name' => $request->json['name'],
                'value' => $this->newKey,
                'created_at' => 1_763_373_600,
                'expires_at' => isset($request->json['expires_in_seconds']) ? 1_763_373_600 + $request->json['expires_in_seconds'] : null,
            ]))
            ->on('GET /projects/proj_abc/api_keys', FakeHttpClient::json([
                'object' => 'list',
                'data' => [
                    $this->projectKey('key_new', 'svc_acct_abc'),
                    $this->projectKey('key_old', 'svc_acct_abc'),
                    $this->projectKey('key_someone_elses', 'svc_acct_other'),
                    ['id' => 'key_person', 'owner' => ['type' => 'user', 'user' => ['id' => 'user_1']], 'expires_at' => null],
                ],
                'has_more' => false,
                'last_id' => 'key_person',
            ]))
            ->on('POST /projects/proj_abc/api_keys/', FakeHttpClient::json(['id' => 'key_old']))
            ->on('DELETE /projects/proj_abc/api_keys/', FakeHttpClient::json(['deleted' => true]))
            ->on('GET /secrets/public-key', FakeHttpClient::json(['data' => [
                'id' => 'kp-1',
                'type' => 'keyPairs',
                'attributes' => ['public_key' => base64_encode(sodium_crypto_box_publickey($keyPair))],
            ]]))
            ->on('PATCH /secrets/sec-1', FakeHttpClient::json(['data' => ['id' => 'sec-1', 'attributes' => ['key' => 'OPENAI_API_KEY']]]))
            ->on('POST /environments/env-1/deployments', FakeHttpClient::json(['data' => ['id' => 'dep-1', 'attributes' => ['status' => 'pending']]]));
    }

    #[Test]
    public function it_rotates_a_due_key_and_reports_in_the_agreed_shape(): void
    {
        $this->assertSame(Runner::OK, $this->runner()->run());

        $this->assertSame([
            'run' => self::RUN,
            'outcome' => 'succeeded',
            'rotated_at' => '2026-11-17T10:00:00Z',
            'new_key' => ['id' => 'key_new', 'hint' => 'WXYZ', 'expires_at' => null],
            'old_keys_expire_at' => '2026-11-19T10:00:00Z',
            'deployments' => ['dep-1'],
        ], $this->reports()[0]);
    }

    #[Test]
    public function it_writes_the_key_to_laravel_cloud_sealed_and_redeploys(): void
    {
        $this->runner()->run();

        $patch = $this->http->sentTo('PATCH', '/secrets/sec-1');
        $this->assertCount(1, $patch);
        $this->assertSame('OPENAI_API_KEY', $patch[0]->json['key']);
        $this->assertSame('kp-1', $patch[0]->json['key_pair_id']);
        $this->assertNotSame($this->newKey, $patch[0]->json['value']);
        $this->assertSame($this->newKey, sodium_crypto_box_seal_open(base64_decode($patch[0]->json['value'], true), $this->secretKey));
        $this->assertCount(1, $this->http->sentTo('POST', '/environments/env-1/deployments'));
    }

    #[Test]
    public function it_retires_only_the_other_keys_of_the_credentials_own_service_account(): void
    {
        $this->runner()->run();

        $expired = $this->http->sentTo('POST', '/projects/proj_abc/api_keys/');
        $this->assertCount(1, $expired);
        $this->assertStringEndsWith('/api_keys/key_old', $expired[0]->url);
        $this->assertSame(['expires_in_seconds' => 48 * 3600], $expired[0]->json);
    }

    #[Test]
    public function it_never_sends_the_new_key_to_complyrobin_or_prints_it(): void
    {
        $this->runner()->run();

        foreach ($this->http->sent as $request) {
            if (str_contains($request->url, 'complyrobin')) {
                $this->assertStringNotContainsString($this->newKey, json_encode($request->json));
                $this->assertStringNotContainsString(substr($this->newKey, 0, 20), json_encode($request->json));
            }
        }

        $this->assertStringNotContainsString($this->newKey, implode("\n", $this->output));
        $this->assertStringContainsString('CRD-0042 (OpenAI production key): rotated.', implode("\n", $this->output));
    }

    #[Test]
    public function it_hides_the_new_key_from_ci_logs_as_soon_as_it_exists(): void
    {
        $masked = [];
        $this->runner(mask: function (string $value) use (&$masked): void {
            $masked[] = $value;
        })->run();

        $this->assertSame([$this->newKey], $masked);
    }

    #[Test]
    public function it_asks_openai_for_a_key_that_expires_when_the_credential_has_a_lifetime(): void
    {
        $this->http = $this->withFirstClaim($this->job(['source' => ['key_lifetime_days' => 90]]));

        $this->runner()->run();

        $this->assertSame(90 * 86400, $this->http->sentTo('POST', '/service_accounts/svc_acct_abc/api_keys')[0]->json['expires_in_seconds']);
        $this->assertSame(gmdate('Y-m-d\\TH:i:s\\Z', 1_763_373_600 + 90 * 86400), $this->reports()[0]['new_key']['expires_at']);
    }

    #[Test]
    public function it_reports_a_failed_push_deletes_the_unused_key_and_keeps_the_old_one(): void
    {
        $this->http = $this->replace('PATCH /secrets/sec-1', FakeHttpClient::json(['message' => 'The given data was invalid.'], 422));

        $this->assertSame(Runner::ROTATION_FAILED, $this->runner()->run());

        $report = $this->reports()[0];
        $this->assertSame('failed', $report['outcome']);
        $this->assertSame('push', $report['step']);
        $this->assertSame('Could not update the Laravel Cloud secret OPENAI_API_KEY. Laravel Cloud answered 422. The given data was invalid.', $report['message']);
        $this->assertCount(1, $this->http->sentTo('DELETE', '/api_keys/key_new'));
        $this->assertSame([], $this->http->sentTo('POST', '/deployments'));
        $this->assertSame([], $this->http->sentTo('POST', '/projects/proj_abc/api_keys/'));
    }

    #[Test]
    public function it_keeps_the_old_keys_working_when_the_redeploy_fails(): void
    {
        $this->http = $this->replace('POST /environments/env-1/deployments', FakeHttpClient::json(['message' => 'Server Error'], 500));

        $this->assertSame(Runner::ROTATION_FAILED, $this->runner()->run());

        $this->assertSame('deploy', $this->reports()[0]['step']);
        $this->assertSame([], $this->http->sentTo('POST', '/projects/proj_abc/api_keys/'));
    }

    #[Test]
    public function it_reports_a_failed_mint_without_touching_laravel_cloud(): void
    {
        $this->http = $this->replace('POST /service_accounts/svc_acct_abc/api_keys', FakeHttpClient::json(['error' => ['message' => 'Service account not found.']], 404));

        $this->runner()->run();

        $this->assertSame(['run' => self::RUN, 'outcome' => 'failed', 'step' => 'mint', 'message' => 'Could not make a new key. OpenAI answered 404. Service account not found.'], $this->reports()[0]);
        $this->assertSame([], $this->http->sentTo('PATCH', '/secrets/'));
    }

    #[Test]
    public function it_refuses_a_destination_it_does_not_know(): void
    {
        $this->http = $this->withFirstClaim($this->job(['destinations' => [['type' => 'forge', 'secret_id' => 'x', 'secret_name' => 'KEY']]]));

        $this->runner()->run();

        $this->assertSame('push', $this->reports()[0]['step']);
        $this->assertStringContainsString("can't update forge secrets yet", $this->reports()[0]['message']);
    }

    #[Test]
    public function it_says_which_access_is_missing_for_a_provider_it_knows(): void
    {
        $this->http = $this->withFirstClaim($this->job(['source' => ['type' => 'aws', 'user_name' => 'complyrobin-crd-0042']]));

        $this->runner()->run();

        $this->assertSame('mint', $this->reports()[0]['step']);
        $this->assertStringStartsWith('The runner has no AWS access. Set AWS_ACCESS_KEY_ID', $this->reports()[0]['message']);
    }

    #[Test]
    public function it_leaves_an_old_key_alone_that_already_expires_sooner(): void
    {
        $soon = (new DateTimeImmutable('2026-11-17T12:00:00Z'))->getTimestamp();
        $this->http = $this->replace('GET /projects/proj_abc/api_keys', FakeHttpClient::json([
            'data' => [$this->projectKey('key_new', 'svc_acct_abc'), $this->projectKey('key_old', 'svc_acct_abc', $soon)],
            'has_more' => false,
        ]));

        $this->runner()->run();

        $this->assertSame([], $this->http->sentTo('POST', '/projects/proj_abc/api_keys/'));
        $this->assertArrayNotHasKey('old_keys_expire_at', $this->reports()[0]);
    }

    #[Test]
    public function it_follows_every_page_of_project_keys(): void
    {
        $this->http = $this->replace(
            'GET /projects/proj_abc/api_keys',
            FakeHttpClient::json(['data' => [$this->projectKey('key_old', 'svc_acct_abc')], 'has_more' => true, 'last_id' => 'key_old']),
            FakeHttpClient::json(['data' => [$this->projectKey('key_older', 'svc_acct_abc')], 'has_more' => false, 'last_id' => 'key_older']),
        );

        $this->runner()->run();

        $this->assertStringContainsString('after=key_old', $this->http->sentTo('GET', '/projects/proj_abc/api_keys')[1]->url);
        $this->assertCount(2, $this->http->sentTo('POST', '/projects/proj_abc/api_keys/'));
    }

    #[Test]
    public function it_retries_a_report_complyrobin_could_not_take(): void
    {
        $this->http = $this->replace('POST /runner/acme/reports', new Response(503, ''), FakeHttpClient::json(['recorded' => true]));

        $this->assertSame(Runner::OK, $this->runner()->run());
        $this->assertCount(2, $this->reports());
    }

    #[Test]
    public function it_resends_only_the_essentials_when_complyrobin_refuses_part_of_a_success(): void
    {
        $this->http = $this->replace('POST /runner/acme/reports', FakeHttpClient::json(['message' => 'Invalid.', 'errors' => ['deployments.0' => ['Looks like a key.']]], 422), FakeHttpClient::json(['recorded' => true]));

        $this->assertSame(Runner::OK, $this->runner()->run());
        $this->assertSame(['run', 'outcome', 'rotated_at', 'new_key', 'old_keys_expire_at'], array_keys($this->reports()[1]));
    }

    #[Test]
    public function it_says_so_when_nothing_is_due(): void
    {
        $this->http = $this->replace('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => []]));

        $this->assertSame(Runner::OK, $this->runner()->run());
        $this->assertSame(['Nothing is due for rotation.'], $this->output);
    }

    #[Test]
    public function it_stops_when_complyrobin_refuses_the_token(): void
    {
        $this->http = $this->replace('POST /runner/acme/claims', FakeHttpClient::json(['message' => 'Unauthenticated.'], 401));

        $this->assertSame(Runner::UNREACHABLE, $this->runner()->run());
        $this->assertSame(['Could not check in with ComplyRobin. ComplyRobin answered 401. Unauthenticated.'], $this->output);
    }

    #[Test]
    public function it_sends_the_runner_token_only_to_complyrobin(): void
    {
        $this->runner()->run();

        foreach ($this->http->sent as $request) {
            $carriesToken = str_contains($request->headers['Authorization'] ?? '', self::RUNNER_TOKEN);
            $this->assertSame(str_starts_with($request->url, 'https://api.complyrobin.com/v1/'), $carriesToken, $request->url);
        }
    }

    private function runner(?\Closure $mask = null): Runner
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17T10:00:00', new DateTimeZone('UTC'));

        return new Runner(
            new ComplyRobinClient($this->http, 'https://api.complyrobin.com/v1', 'acme', self::RUNNER_TOKEN),
            new Rotator(['openai' => new OpenAiSource(new OpenAiClient($this->http, 'sk-admin-test'))], new LaravelCloudClient($this->http, 'lc-token'), $clock, $mask),
            [self::RUNNER_TOKEN],
            function (string $line): void {
                $this->output[] = $line;
            },
            static function (int $seconds): void {},
        );
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
        $routes = (new \ReflectionProperty(FakeHttpClient::class, 'routes'))->getValue($this->http);
        $routes[$route] = $answers;
        (new \ReflectionProperty(FakeHttpClient::class, 'routes'))->setValue($this->http, $routes);

        return $this->http;
    }

    private function withFirstClaim(array $job): FakeHttpClient
    {
        return $this->replace('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => [$job]]), FakeHttpClient::json(['jobs' => []]));
    }

    private function job(array $overrides = []): array
    {
        return [
            'run' => self::RUN,
            'credential' => ['reference' => 'CRD-0042', 'name' => 'OpenAI production key'],
            'source' => array_merge(['type' => 'openai', 'project_id' => 'proj_abc', 'service_account_id' => 'svc_acct_abc', 'key_lifetime_days' => null], $overrides['source'] ?? []),
            'destinations' => $overrides['destinations'] ?? [
                ['type' => 'laravel_cloud', 'secret_id' => 'sec-1', 'secret_name' => 'OPENAI_API_KEY', 'environment_ids' => ['env-1'], 'redeploy' => true],
            ],
            'grace_hours' => 48,
        ];
    }

    private function projectKey(string $id, string $serviceAccount, ?int $expiresAt = null): array
    {
        return [
            'object' => 'organization.project.api_key',
            'id' => $id,
            'name' => $id,
            'redacted_value' => 'sk-svc...abcd',
            'owner' => ['type' => 'service_account', 'service_account' => ['id' => $serviceAccount, 'name' => 'ComplyRobin', 'role' => 'member']],
            'owner_project_access' => 'active',
            'expires_at' => $expiresAt,
        ];
    }
}
