<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Unit;

use ComplyRobin\Runner\Console\Application;
use ComplyRobin\Runner\Runner;
use ComplyRobin\Runner\Tests\Fakes\FakeHttpClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    private const array ENV = [
        'COMPLYROBIN_TOKEN' => 'crr_0193c0de00007000800000000000000c_cccccccccccccccccccccccccccccccccccccccccccccccc',
        'OPENAI_ADMIN_KEY' => 'sk-admin-test',
        'LARAVEL_CLOUD_API_TOKEN' => 'lc-token',
    ];

    /** @var list<string> */
    private array $output = [];

    #[Test]
    public function it_tests_the_access_without_rotating_anything_and_sends_the_results_to_complyrobin(): void
    {
        $http = (new FakeHttpClient)
            ->on('GET /organization/projects', FakeHttpClient::json(['data' => []]))
            ->on('GET /secrets/public-key', FakeHttpClient::json(['message' => 'Unauthenticated.'], 401))
            ->on('POST /runner/checks', FakeHttpClient::json(['recorded' => true]));

        $this->assertSame(1, $this->app(self::ENV, $http)->run(['check']));
        $this->assertSame([
            'OpenAI: The access works.',
            'AWS: No access set, so this runner can\'t use it.',
            'Google Cloud: No access set, so this runner can\'t use it.',
            'Laravel Cloud: Laravel Cloud answered 401. Unauthenticated.',
            'GitHub: No access set, so this runner can\'t use it.',
            'Forge: No access set, so this runner can\'t use it.',
            'Vercel: No access set, so this runner can\'t use it.',
            'ComplyRobin: the token works, and the results are on the Rotation runners page.',
        ], $this->output);

        $sent = $http->sentTo('POST', '/runner/checks');
        $this->assertCount(1, $sent);
        $this->assertSame(['service' => 'Laravel Cloud', 'status' => 'failed', 'message' => 'Laravel Cloud answered 401. Unauthenticated.'], $sent[0]->json['services'][3]);
        $this->assertSame([], $http->sentTo('POST', '/claims'));
    }

    #[Test]
    public function it_says_so_when_complyrobin_refuses_the_token_during_a_check(): void
    {
        $http = (new FakeHttpClient)
            ->on('GET /organization/projects', FakeHttpClient::json(['data' => []]))
            ->on('GET /secrets/public-key', FakeHttpClient::json(['data' => ['id' => 'kp', 'attributes' => ['public_key' => base64_encode(str_repeat('k', 32))]]]))
            ->on('POST /runner/checks', FakeHttpClient::json(['message' => 'Unauthenticated.'], 401));

        $this->assertSame(Runner::UNREACHABLE, $this->app(self::ENV, $http)->run(['check']));
        $this->assertStringStartsWith('ComplyRobin: the token was refused.', end($this->output));
    }

    #[Test]
    public function it_runs_by_default(): void
    {
        $http = (new FakeHttpClient)->on('POST /runner/claims', FakeHttpClient::json(['jobs' => []]));

        $this->assertSame(Runner::OK, $this->app(self::ENV, $http)->run([]));
        $this->assertSame(['Nothing is due for rotation.'], $this->output);
    }

    #[Test]
    public function it_stops_with_a_clear_message_when_a_setting_is_missing(): void
    {
        $this->assertSame(78, $this->app([], new FakeHttpClient)->run(['run']));
        $this->assertStringStartsWith('Set COMPLYROBIN_TOKEN', $this->output[0]);
    }

    #[Test]
    public function it_prints_help_for_an_unknown_command(): void
    {
        $this->assertSame(64, $this->app(self::ENV, new FakeHttpClient)->run(['rotate-everything']));
        $this->assertStringContainsString('complyrobin-runner check', $this->output[0]);
    }

    /**
     * @param  array<string, string>  $env
     */
    private function app(array $env, FakeHttpClient $http): Application
    {
        return new Application($env, function (string $line): void {
            $this->output[] = $line;
        }, $http);
    }
}
