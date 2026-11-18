<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Unit;

use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudClient;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudSource;
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

final class GoogleCloudTest extends TestCase
{
    private const string RUN = '0193c0de-0000-7000-8000-000000000003';

    private const string EMAIL = 'crd-0042@acme-prod.iam.gserviceaccount.com';

    private const string KEYS = 'projects/-/serviceAccounts/crd-0042%40acme-prod.iam.gserviceaccount.com/keys';

    private const string NEW_KEY_ID = '1f2e3d4c5b6a79880011223344556677deadbeef';

    private FakeHttpClient $http;

    /** @var list<array{name: string, disabled?: bool}> what Google Cloud holds for the service account */
    private array $keys = [];

    private string $privateKey = "-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC7VJTUt9Us8cKj\nMzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu\n-----END PRIVATE KEY-----\n";

    private string $keyPair;

    /** @var list<string> */
    private array $output = [];

    protected function setUp(): void
    {
        $this->keys = [['name' => 'projects/acme-prod/serviceAccounts/'.self::EMAIL.'/keys/0ldkey0000000000000000000000000000000001']];
        $this->keyPair = sodium_crypto_box_keypair();

        $this->http = (new FakeHttpClient)
            ->on('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => [$this->job()]]), FakeHttpClient::json(['jobs' => []]))
            ->on('POST /runner/acme/reports', FakeHttpClient::json(['recorded' => true]))
            ->on('GET iam.googleapis.com/v1/'.self::KEYS, fn (): Response => FakeHttpClient::json(['keys' => $this->keys]))
            ->on('POST iam.googleapis.com/v1/'.self::KEYS, fn (Request $request): Response => $this->create($request))
            ->on('POST :disable', fn (Request $request): Response => $this->disable($request))
            ->on('DELETE iam.googleapis.com/v1/projects/', fn (Request $request): Response => $this->delete($request))
            ->on('GET /secrets/public-key', FakeHttpClient::json(['data' => ['id' => 'kp-1', 'attributes' => ['public_key' => base64_encode(sodium_crypto_box_publickey($this->keyPair))]]]))
            ->on('PATCH /secrets/', FakeHttpClient::json(['data' => ['id' => 'sec']]))
            ->on('POST /environments/env-1/deployments', FakeHttpClient::json(['data' => ['id' => 'dep-1']]));
    }

    #[Test]
    public function it_rotates_a_service_account_key_and_puts_the_whole_key_file_in_place(): void
    {
        $this->assertSame(Runner::OK, $this->runner()->run());

        $this->assertSame([
            'run' => self::RUN,
            'outcome' => 'succeeded',
            'rotated_at' => '2026-11-17T10:00:00Z',
            'new_key' => ['id' => self::NEW_KEY_ID, 'hint' => 'beef', 'expires_at' => null],
            'old_keys_expire_at' => '2026-11-19T10:00:00Z',
            'retire_later' => true,
            'deployments' => ['dep-1'],
        ], $this->reports()[0]);

        $patch = $this->http->sentTo('PATCH', '/secrets/sec-1')[0];
        $file = json_decode((string) sodium_crypto_box_seal_open(base64_decode($patch->json['value'], true), $this->keyPair), true);
        $this->assertSame(self::EMAIL, $file['client_email']);
        $this->assertSame($this->privateKey, $file['private_key']);
        $this->assertFalse($this->keys[0]['disabled'] ?? false, 'The old key keeps working through the grace period.');
    }

    #[Test]
    public function it_hides_the_key_file_and_its_private_key_from_logs_and_complyrobin(): void
    {
        $masked = [];
        $this->runner(function (string $value) use (&$masked): void {
            $masked[] = $value;
        })->run();

        $this->assertContains($this->privateKey, $masked);
        $this->assertContains('MzEfYyjiWA4R4/M2bS1GB4t7NXp98C3SC6dVMvDuictGeurT8jNbvJZHtCSuYEvu', $masked);

        foreach ($this->http->sentTo('POST', '/runner/acme/') as $request) {
            $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($request->json));
            $this->assertStringNotContainsString('MzEfYyji', (string) json_encode($request->json));
        }
    }

    #[Test]
    public function it_deletes_keys_an_earlier_retire_disabled_before_making_the_new_one(): void
    {
        $this->keys[] = ['name' => 'projects/acme-prod/serviceAccounts/'.self::EMAIL.'/keys/0lderkey000000000000000000000000000000002', 'disabled' => true];

        $this->runner()->run();

        $this->assertSame(['0ldkey0000000000000000000000000000000001', self::NEW_KEY_ID], $this->ids());
    }

    #[Test]
    public function a_retire_job_disables_every_key_but_the_new_one(): void
    {
        $this->keys[] = ['name' => 'projects/acme-prod/serviceAccounts/'.self::EMAIL.'/keys/'.self::NEW_KEY_ID];
        $this->http = $this->withFirstClaim($this->retireJob(self::NEW_KEY_ID));

        $this->assertSame(Runner::OK, $this->runner()->run());

        $this->assertTrue($this->keys[0]['disabled'] ?? false);
        $this->assertFalse($this->keys[1]['disabled'] ?? false);
        $this->assertSame(['run' => self::RUN, 'action' => 'retire', 'outcome' => 'retired', 'retired_keys' => 1], $this->reports()[0]);
    }

    #[Test]
    public function a_retire_job_disables_nothing_when_the_new_key_is_gone(): void
    {
        $this->http = $this->withFirstClaim($this->retireJob(self::NEW_KEY_ID));

        $this->assertSame(Runner::ROTATION_FAILED, $this->runner()->run());

        $this->assertFalse($this->keys[0]['disabled'] ?? false);
        $this->assertSame('retire', $this->reports()[0]['step']);
    }

    #[Test]
    public function it_trades_a_service_account_key_file_for_a_token_at_googles_own_address(): void
    {
        $admin = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($admin, $adminPem);
        $credentials = (string) json_encode([
            'type' => 'service_account',
            'client_email' => 'rotator@acme-prod.iam.gserviceaccount.com',
            'private_key_id' => 'abc',
            'private_key' => $adminPem,
            'token_uri' => 'https://attacker.example/token',
        ]);

        $http = (new FakeHttpClient)
            ->on('POST oauth2.googleapis.com/token', FakeHttpClient::json(['access_token' => 'ya29.minted', 'expires_in' => 3599]))
            ->on('GET iam.googleapis.com', FakeHttpClient::json(['keys' => []]));

        $client = new GoogleCloudClient($http, null, $credentials, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17T10:00:00Z'));
        $client->listKeys(self::EMAIL);
        $client->listKeys(self::EMAIL);

        $this->assertCount(1, $http->sentTo('POST', 'oauth2.googleapis.com/token'), 'One token per run.');
        $this->assertSame([], $http->sentTo('POST', 'attacker.example'));
        $this->assertSame('Bearer ya29.minted', $http->sentTo('GET', 'iam.googleapis.com')[0]->headers['Authorization']);

        parse_str((string) $http->sentTo('POST', 'oauth2.googleapis.com/token')[0]->body, $form);
        [$header, $claims, $signature] = explode('.', $form['assertion']);
        $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'abc'], json_decode($decode($header), true));
        $this->assertSame('rotator@acme-prod.iam.gserviceaccount.com', json_decode($decode($claims), true)['iss']);
        $this->assertSame(1, openssl_verify($header.'.'.$claims, $decode($signature), openssl_pkey_get_details($admin)['key'], OPENSSL_ALGO_SHA256));
    }

    private function runner(?\Closure $mask = null): Runner
    {
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17T10:00:00', new DateTimeZone('UTC'));

        return new Runner(
            new ComplyRobinClient($this->http, 'https://api.complyrobin.com/v1', 'acme', 'crr_'.str_repeat('a', 60)),
            new Rotator(['google_cloud' => new GoogleCloudSource(new GoogleCloudClient($this->http, 'ya29.runner-token'))], new LaravelCloudClient($this->http, 'lc-token'), $clock, $mask),
            ['ya29.runner-token'],
            function (string $line): void {
                $this->output[] = $line;
            },
            static function (int $seconds): void {},
        );
    }

    private function create(Request $request): Response
    {
        $this->assertSame('TYPE_GOOGLE_CREDENTIALS_FILE', $request->json['privateKeyType']);
        $name = 'projects/acme-prod/serviceAccounts/'.self::EMAIL.'/keys/'.self::NEW_KEY_ID;
        $this->keys[] = ['name' => $name];

        return FakeHttpClient::json([
            'name' => $name,
            'privateKeyType' => 'TYPE_GOOGLE_CREDENTIALS_FILE',
            'privateKeyData' => base64_encode((string) json_encode([
                'type' => 'service_account',
                'project_id' => 'acme-prod',
                'private_key_id' => self::NEW_KEY_ID,
                'private_key' => $this->privateKey,
                'client_email' => self::EMAIL,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
            'validAfterTime' => '2026-11-17T10:00:00Z',
            'validBeforeTime' => '9999-12-31T23:59:59Z',
        ]);
    }

    private function disable(Request $request): Response
    {
        foreach ($this->keys as $index => $key) {
            if (str_contains(rawurldecode($request->url), $key['name'].':disable')) {
                $this->keys[$index]['disabled'] = true;
            }
        }

        return FakeHttpClient::json([]);
    }

    private function delete(Request $request): Response
    {
        $id = basename(rawurldecode($request->url));
        $this->keys = array_values(array_filter($this->keys, static fn (array $key): bool => basename($key['name']) !== $id));

        return FakeHttpClient::json([]);
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        return array_map(static fn (array $key): string => basename($key['name']), $this->keys);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reports(): array
    {
        return array_map(fn (Request $request): array => $request->json, $this->http->sentTo('POST', '/runner/acme/reports'));
    }

    private function withFirstClaim(array $job): FakeHttpClient
    {
        $property = new \ReflectionProperty(FakeHttpClient::class, 'routes');
        $routes = $property->getValue($this->http);
        $routes['POST /runner/acme/claims'] = [FakeHttpClient::json(['jobs' => [$job]]), FakeHttpClient::json(['jobs' => []])];
        $property->setValue($this->http, $routes);

        return $this->http;
    }

    private function job(): array
    {
        return [
            'run' => self::RUN,
            'credential' => ['reference' => 'CRD-0042', 'name' => 'Firebase admin key'],
            'source' => ['type' => 'google_cloud', 'service_account_email' => self::EMAIL],
            'destinations' => [['type' => 'laravel_cloud', 'secret_id' => 'sec-1', 'secret_name' => 'GOOGLE_CREDENTIALS_JSON', 'environment_ids' => ['env-1'], 'redeploy' => true]],
            'grace_hours' => 48,
        ];
    }

    private function retireJob(string $keep): array
    {
        return [
            'run' => self::RUN,
            'action' => 'retire',
            'credential' => ['reference' => 'CRD-0042', 'name' => 'Firebase admin key'],
            'source' => ['type' => 'google_cloud', 'service_account_email' => self::EMAIL],
            'keep_key' => $keep,
        ];
    }
}
