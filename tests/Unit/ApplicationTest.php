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
        'COMPLYROBIN_RUNNER_TOKEN' => 'crr_cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc',
        'COMPLYROBIN_ORGANISATION' => 'acme',
        'OPENAI_ADMIN_KEY' => 'sk-admin-test',
        'LARAVEL_CLOUD_API_TOKEN' => 'lc-token',
    ];

    /** @var list<string> */
    private array $output = [];

    #[Test]
    public function it_checks_the_tokens_without_rotating_anything(): void
    {
        $http = (new FakeHttpClient)
            ->on('GET /organization/projects', FakeHttpClient::json(['data' => []]))
            ->on('GET /secrets/public-key', FakeHttpClient::json(['message' => 'Unauthenticated.'], 401));

        $this->assertSame(1, $this->app(self::ENV, $http)->run(['check']));
        $this->assertSame([
            'OpenAI: the token works.',
            'Laravel Cloud: Laravel Cloud answered 401. Unauthenticated.',
            'ComplyRobin: will check in at https://api.complyrobin.com/v1 for acme. The token is checked on the first run.',
        ], $this->output);
        $this->assertSame([], $http->sentTo('POST', ''));
    }

    #[Test]
    public function it_runs_by_default(): void
    {
        $http = (new FakeHttpClient)->on('POST /runner/acme/claims', FakeHttpClient::json(['jobs' => []]));

        $this->assertSame(Runner::OK, $this->app(self::ENV, $http)->run([]));
        $this->assertSame(['Nothing is due for rotation.'], $this->output);
    }

    #[Test]
    public function it_stops_with_a_clear_message_when_a_setting_is_missing(): void
    {
        $this->assertSame(78, $this->app([], new FakeHttpClient)->run(['run']));
        $this->assertStringStartsWith('Set COMPLYROBIN_RUNNER_TOKEN', $this->output[0]);
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
