<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Unit;

use ComplyRobin\Runner\Config;
use ComplyRobin\Runner\Exceptions\ConfigurationError;
use ComplyRobin\Runner\LaravelCloud\SealedBox;
use ComplyRobin\Runner\NewKey;
use ComplyRobin\Runner\Support\Redact;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SafetyTest extends TestCase
{
    private const string TOKEN = 'crr_0193c0de00007000800000000000000b_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @return array<string, array{string}>
     */
    public static function secrets(): array
    {
        return [
            'OpenAI key' => ['Bad key sk-proj-'.str_repeat('a1B2', 10).' given'],
            'service account key' => ['sk-svcacct-abcdefgh12345678'],
            'runner token' => [self::TOKEN],
            'GitHub token' => ['ghp_'.str_repeat('x', 36)],
            'JWT' => ['eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl'],
            'AWS access key id' => ['Key AKIAIOSFODNN7EXAMPLE is not valid'],
            'Google access token' => ['Bearer ya29.a0AfB_byC1234567890abcdefghij'],
            'base64 blob' => [base64_encode(random_bytes(48)).'Aa1'],
        ];
    }

    #[Test]
    #[DataProvider('secrets')]
    public function it_removes_anything_shaped_like_a_secret(string $text): void
    {
        $this->assertStringContainsString('[redacted]', Redact::text($text));
        $this->assertDoesNotMatchRegularExpression('/sk-|crr_|ghp_|eyJ|AKIA|ya29/', Redact::text($text));
    }

    #[Test]
    public function it_keeps_ids_people_need_to_read(): void
    {
        $text = 'Redeploying environment env-0193c0de-0000-7000-8000-000000000001 failed for key_U5v7CPcnS6FXbE3o.';

        $this->assertSame($text, Redact::text($text));
    }

    #[Test]
    public function it_removes_exact_secrets_and_cuts_to_length(): void
    {
        $this->assertSame('token [redacted] used', Redact::text('token plainvalue used', 500, ['plainvalue']));
        $this->assertSame(10, mb_strlen(Redact::text(str_repeat('word ', 50), 10)));
    }

    #[Test]
    public function it_seals_a_value_only_the_private_key_opens(): void
    {
        $keyPair = sodium_crypto_box_keypair();
        $sealed = SealedBox::seal('the-value', base64_encode(sodium_crypto_box_publickey($keyPair)));

        $this->assertSame('the-value', sodium_crypto_box_seal_open(base64_decode($sealed, true), $keyPair));
        $this->assertFalse(sodium_crypto_box_seal_open(base64_decode($sealed, true), sodium_crypto_box_keypair()));
    }

    #[Test]
    public function it_refuses_a_malformed_public_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SealedBox::seal('the-value', base64_encode('too short'));
    }

    #[Test]
    public function it_wipes_the_new_key_and_never_dumps_it(): void
    {
        $key = new NewKey('key_1', 'sk-svcacct-secretvalue1234', null);

        $this->assertStringNotContainsString('secretvalue', print_r($key, true));
        $this->assertSame('1234', $key->hint());

        $key->forget();

        $this->assertSame('', $key->value());
    }

    #[Test]
    public function it_hides_every_sensitive_piece_of_a_key_and_keeps_only_its_plain_parts(): void
    {
        $key = new NewKey('abc123', '{"private_key": "secret"}', null, '0123', ['key_id' => 'AKIAEXAMPLE'], ['secret-piece']);

        $this->assertSame(['{"private_key": "secret"}', 'secret-piece'], $key->secrets());
        $this->assertSame('0123', $key->hint());
        $this->assertSame('AKIAEXAMPLE', $key->part('key_id'));
        $this->assertNull($key->part('other'));

        $key->forget();

        $this->assertSame([], $key->secrets());
        $this->assertSame('AKIAEXAMPLE', $key->part('key_id'));
    }

    #[Test]
    public function it_reads_aws_and_google_cloud_access_and_hides_it(): void
    {
        $config = Config::fromEnvironment([
            'COMPLYROBIN_TOKEN' => self::TOKEN,
            'AWS_ACCESS_KEY_ID' => 'AKIAIOSFODNN7EXAMPLE',
            'AWS_SECRET_ACCESS_KEY' => 'aws-secret',
            'AWS_SESSION_TOKEN' => 'aws-session',
            'GOOGLE_CLOUD_ACCESS_TOKEN' => 'ya29.token',
        ]);

        $this->assertSame('aws-session', $config->aws()?->sessionToken);
        $this->assertSame([self::TOKEN, 'aws-secret', 'aws-session', 'ya29.token'], $config->secrets());
        $this->assertStringNotContainsString('aws-secret', print_r($config, true));
    }

    #[Test]
    public function it_reads_its_settings_and_hides_the_tokens(): void
    {
        $config = Config::fromEnvironment(['COMPLYROBIN_TOKEN' => self::TOKEN, 'OPENAI_ADMIN_KEY' => 'sk-admin-x', 'GITHUB_ACTIONS' => 'true']);

        $this->assertSame(Config::DEFAULT_API_URL, $config->apiUrl);
        $this->assertTrue($config->inGitHubActions);
        $this->assertNull($config->laravelCloudToken());
        $this->assertSame([self::TOKEN, 'sk-admin-x'], $config->secrets());
        $this->assertStringNotContainsString(self::TOKEN, print_r($config, true));
    }

    #[Test]
    public function it_reads_the_destination_tokens_and_hides_them(): void
    {
        $config = Config::fromEnvironment([
            'COMPLYROBIN_TOKEN' => self::TOKEN,
            'GH_SECRETS_TOKEN' => 'github_pat_x',
            'FORGE_API_TOKEN' => 'forge-x',
            'VERCEL_TOKEN' => 'vercel-x',
        ]);

        $this->assertSame([self::TOKEN, 'github_pat_x', 'forge-x', 'vercel-x'], $config->secrets());
        $this->assertStringNotContainsString('forge-x', print_r($config, true));
    }

    #[Test]
    public function it_redacts_runner_tokens_whole(): void
    {
        $this->assertSame('token [redacted] refused', Redact::text('token '.self::TOKEN.' refused'));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function badSettings(): array
    {
        return [
            'no token' => [[], 'Set COMPLYROBIN_TOKEN'],
            'not a runner token' => [['COMPLYROBIN_TOKEN' => 'sk-proj-abc'], 'is not a runner token'],
            'an old runner token without the organisation' => [['COMPLYROBIN_TOKEN' => 'crr_'.str_repeat('b', 60)], 'is not a runner token'],
            'half of an AWS key' => [['COMPLYROBIN_TOKEN' => self::TOKEN, 'AWS_ACCESS_KEY_ID' => 'AKIAIOSFODNN7EXAMPLE'], 'Set both AWS_ACCESS_KEY_ID'],
        ];
    }

    /**
     * @param  array<string, string>  $env
     */
    #[Test]
    #[DataProvider('badSettings')]
    public function it_explains_a_missing_or_wrong_setting_without_echoing_it(array $env, string $message): void
    {
        try {
            Config::fromEnvironment($env);
            $this->fail('No error.');
        } catch (ConfigurationError $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertStringNotContainsString('sk-proj-abc', $error->getMessage());
        }
    }
}
