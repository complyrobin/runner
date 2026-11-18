<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Tests\Unit;

use ComplyRobin\Runner\Aws\AwsCredentials;
use ComplyRobin\Runner\Checker;
use ComplyRobin\Runner\Aws\AwsJsonClient;
use ComplyRobin\Runner\Aws\IamClient;
use ComplyRobin\Runner\Aws\ParameterStoreDestination;
use ComplyRobin\Runner\Aws\SecretsManagerDestination;
use ComplyRobin\Runner\Forge\EnvFile;
use ComplyRobin\Runner\Forge\ForgeClient;
use ComplyRobin\Runner\Forge\ForgeDestination;
use ComplyRobin\Runner\GitHub\GitHubClient;
use ComplyRobin\Runner\GitHub\GitHubDestination;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudClient;
use ComplyRobin\Runner\GoogleCloud\SecretManagerDestination;
use ComplyRobin\Runner\Http\Request;
use ComplyRobin\Runner\Laravel\LaravelSettings;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudDestination;
use ComplyRobin\Runner\Tests\Fakes\FakeHttpClient;
use ComplyRobin\Runner\Vercel\VercelClient;
use ComplyRobin\Runner\Vercel\VercelDestination;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DestinationsTest extends TestCase
{
    private const string KEY = 'sk-svcacct-Ab3xYz9QAb3xYz9QAb3xYz9QWXYZ';

    #[Test]
    public function it_lists_laravel_cloud_apps_environments_and_secret_names_and_github_repositories(): void
    {
        $http = (new FakeHttpClient)
            ->on('GET /api/applications/app-1/environments', FakeHttpClient::json([
                'data' => [['id' => 'env-1', 'type' => 'environments', 'attributes' => ['name' => 'production'], 'relationships' => ['secrets' => ['data' => [['type' => 'secrets', 'id' => 'sec-1']]]]]],
                'included' => [['type' => 'secrets', 'id' => 'sec-1', 'attributes' => ['key' => 'OPENAI_API_KEY', 'value' => 'never sent by Cloud']]],
            ]))
            ->on('GET /api/applications/app-2/environments', FakeHttpClient::json(['data' => []]))
            ->on('GET /api/applications', FakeHttpClient::json(['data' => [['id' => 'app-1', 'attributes' => ['name' => 'Project A']]], 'links' => ['next' => 'https://cloud.laravel.com/api/applications?page=2']]), FakeHttpClient::json(['data' => [['id' => 'app-2', 'attributes' => ['name' => 'Project B']]], 'links' => ['next' => null]]))
            ->on('GET /user/repos', FakeHttpClient::json([['full_name' => 'acme/api'], ['full_name' => 'acme/web']]));

        $inventory = (new Checker([
            'Laravel Cloud' => new LaravelCloudDestination(new LaravelCloudClient($http, 'cloud-token')),
            'GitHub' => new GitHubDestination(new GitHubClient($http, 'github_pat_x')),
        ]))->inventory();

        $this->assertSame([
            'laravel_cloud' => ['applications' => [
                ['id' => 'app-1', 'name' => 'Project A', 'environments' => [['id' => 'env-1', 'name' => 'production', 'secrets' => [['id' => 'sec-1', 'name' => 'OPENAI_API_KEY']]]]],
                ['id' => 'app-2', 'name' => 'Project B', 'environments' => []],
            ]],
            'github' => ['repositories' => ['acme/api', 'acme/web']],
        ], $inventory);
        $this->assertStringEndsWith('/api/applications?page=2', $http->sentTo('GET', '/api/applications?')[0]->url);
    }

    #[Test]
    public function it_leaves_out_a_list_it_could_not_read(): void
    {
        $http = (new FakeHttpClient)->on('GET /user/repos', FakeHttpClient::json(['message' => 'Bad credentials'], 401));

        $this->assertSame([], (new Checker(['GitHub' => new GitHubDestination(new GitHubClient($http, 'github_pat_x')), 'Vercel' => null]))->inventory());
    }

    #[Test]
    public function it_seals_a_github_secret_to_the_repositorys_public_key(): void
    {
        $keyPair = sodium_crypto_box_keypair();
        $http = (new FakeHttpClient)
            ->on('GET /repos/acme/api/actions/secrets/public-key', FakeHttpClient::json(['key_id' => 'kid-1', 'key' => base64_encode(sodium_crypto_box_publickey($keyPair))]))
            ->on('PUT /repos/acme/api/actions/secrets/OPENAI_API_KEY', FakeHttpClient::json([], 201));

        (new GitHubDestination(new GitHubClient($http, 'github_pat_x')))->put(['repository' => 'acme/api', 'secret_name' => 'OPENAI_API_KEY'], self::KEY);

        $put = $http->sentTo('PUT', '/actions/secrets/OPENAI_API_KEY')[0];
        $this->assertSame('kid-1', $put->json['key_id']);
        $this->assertSame(self::KEY, sodium_crypto_box_seal_open(base64_decode($put->json['encrypted_value'], true), $keyPair));
        $this->assertSame('Bearer github_pat_x', $put->headers['Authorization']);
    }

    #[Test]
    public function it_writes_a_github_environment_secret_when_an_environment_is_named(): void
    {
        $http = (new FakeHttpClient)
            ->on('GET /repos/acme/api/environments/production/secrets/public-key', FakeHttpClient::json(['key_id' => 'kid-2', 'key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()))]))
            ->on('PUT /repos/acme/api/environments/production/secrets/OPENAI_API_KEY', FakeHttpClient::json([], 204));

        (new GitHubDestination(new GitHubClient($http, 'github_pat_x')))->put(['repository' => 'acme/api', 'environment' => 'production', 'secret_name' => 'OPENAI_API_KEY'], self::KEY);

        $this->assertCount(1, $http->sentTo('PUT', '/environments/production/secrets/OPENAI_API_KEY'));
    }

    #[Test]
    public function it_refuses_a_repository_that_could_point_the_token_elsewhere(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GitHubDestination(new GitHubClient(new FakeHttpClient, 'github_pat_x')))->put(['repository' => '../../evil.example', 'secret_name' => 'X'], self::KEY);
    }

    #[Test]
    public function it_puts_a_value_in_aws_secrets_manager_signed_for_the_region(): void
    {
        $http = (new FakeHttpClient)->on('POST secretsmanager.eu-west-1.amazonaws.com', FakeHttpClient::json(['ARN' => 'arn:aws:secretsmanager:eu-west-1:1:secret:openai', 'VersionId' => 'v2']));

        $this->secretsManager($http)->put(['region' => 'eu-west-1', 'secret_id' => 'prod/openai'], self::KEY);

        $sent = $http->sentTo('POST', 'secretsmanager.eu-west-1.amazonaws.com')[0];
        $this->assertSame('secretsmanager.PutSecretValue', $sent->headers['X-Amz-Target']);
        $this->assertSame(['SecretId' => 'prod/openai', 'SecretString' => self::KEY], json_decode((string) $sent->body, true));
        $this->assertStringContainsString('/eu-west-1/secretsmanager/aws4_request', $sent->headers['Authorization']);
    }

    #[Test]
    public function it_explains_an_aws_error_by_its_type(): void
    {
        $http = (new FakeHttpClient)->on('POST secretsmanager.', FakeHttpClient::json(['__type' => 'com.amazonaws.secretsmanager#ResourceNotFoundException', 'message' => "Secrets Manager can't find the specified secret."], 400));

        $this->expectExceptionMessage("AWS answered 400. ResourceNotFoundException: Secrets Manager can't find the specified secret.");

        $this->secretsManager($http)->put(['region' => 'eu-west-1', 'secret_id' => 'missing'], self::KEY);
    }

    #[Test]
    public function it_refuses_a_region_that_is_not_one(): void
    {
        $http = new FakeHttpClient;

        try {
            $this->secretsManager($http)->put(['region' => 'evil.example.com/x', 'secret_id' => 'prod/openai'], self::KEY);
            $this->fail('No error.');
        } catch (InvalidArgumentException) {
            $this->assertSame([], $http->sent);
        }
    }

    #[Test]
    public function it_writes_an_encrypted_parameter_to_parameter_store(): void
    {
        $http = (new FakeHttpClient)->on('POST ssm.us-east-1.amazonaws.com', FakeHttpClient::json(['Version' => 3]));
        $credentials = new AwsCredentials('AKIARUNNER0000000001', 'runner-secret');

        (new ParameterStoreDestination(new AwsJsonClient($http, $credentials), new IamClient($http, $credentials)))->put(['region' => 'us-east-1', 'parameter_name' => '/prod/OPENAI_API_KEY'], self::KEY);

        $sent = $http->sentTo('POST', 'ssm.us-east-1.amazonaws.com')[0];
        $this->assertSame('AmazonSSM.PutParameter', $sent->headers['X-Amz-Target']);
        $this->assertSame(['Name' => '/prod/OPENAI_API_KEY', 'Value' => self::KEY, 'Type' => 'SecureString', 'Overwrite' => true], json_decode((string) $sent->body, true));
    }

    #[Test]
    public function it_adds_a_new_version_to_a_google_cloud_secret(): void
    {
        $http = (new FakeHttpClient)->on('POST secretmanager.googleapis.com/v1/projects/acme-prod/secrets/openai-key:addVersion', FakeHttpClient::json(['name' => 'projects/1/secrets/openai-key/versions/4']));

        (new SecretManagerDestination(new GoogleCloudClient($http, 'ya29.runner')))->put(['project_id' => 'acme-prod', 'secret_id' => 'openai-key'], self::KEY);

        $sent = $http->sentTo('POST', ':addVersion')[0];
        $this->assertSame(['payload' => ['data' => base64_encode(self::KEY)]], $sent->json);
        $this->assertSame('Bearer ya29.runner', $sent->headers['Authorization']);
    }

    #[Test]
    public function it_sends_google_cloud_access_only_to_google(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GoogleCloudClient(new FakeHttpClient, 'ya29.runner'))->call('GET', 'https://googleapis.com.evil.example/x');
    }

    #[Test]
    public function it_changes_one_line_of_a_forge_sites_environment_and_follows_the_deployment(): void
    {
        $http = (new FakeHttpClient)
            ->on('GET /orgs/acme/servers/11/sites/22/environment', FakeHttpClient::json(['data' => ['attributes' => ['content' => "APP_NAME=Acme\nOPENAI_API_KEY=sk-old\nDB_PASSWORD=\"p w\"\n"]]]))
            ->on('PUT /orgs/acme/servers/11/sites/22/environment', FakeHttpClient::json([]))
            ->on('POST /orgs/acme/servers/11/sites/22/deployments', FakeHttpClient::json(['data' => ['id' => 99, 'attributes' => ['status' => 'pending']]]))
            ->on('GET /orgs/acme/servers/11/sites/22/deployments/99', FakeHttpClient::json(['data' => ['id' => 99, 'attributes' => ['status' => 'deploying']]]), FakeHttpClient::json(['data' => ['id' => 99, 'attributes' => ['status' => 'finished']]]));
        $forge = new ForgeDestination(new ForgeClient($http, 'forge-token'));
        $settings = ['organisation' => 'acme', 'server_id' => 11, 'site_id' => '22', 'secret_name' => 'OPENAI_API_KEY', 'redeploy' => true];

        $forge->put($settings, self::KEY);
        [$target] = $forge->redeployTargets($settings);
        $id = $forge->deploy($target);

        $this->assertSame("APP_NAME=Acme\nOPENAI_API_KEY=".self::KEY."\nDB_PASSWORD=\"p w\"\n", $http->sentTo('PUT', '/environment')[0]->json['environment']);
        $this->assertSame('99', $id);
        $this->assertTrue($forge->deployment($target, $id)->isRunning());
        $this->assertTrue($forge->deployment($target, $id)->succeededNow());
    }

    #[Test]
    public function it_writes_values_a_dotenv_reader_reads_back_unchanged(): void
    {
        $this->assertSame("A=1\nKEY=plain-value_1.2\n", EnvFile::set("A=1\n", 'KEY', 'plain-value_1.2'));
        $this->assertSame('KEY="a \\"quoted\\" \\$value\\\\n\\nline two"', EnvFile::set('KEY=old', 'KEY', "a \"quoted\" \$value\\n\nline two"));
        $this->assertSame("KEY=new\n", EnvFile::set("export KEY=old\n", 'KEY', 'new'));
        $this->assertSame("KEY=new\n\n", EnvFile::set("KEY=old\nKEY=older\n", 'KEY', 'new'));
    }

    #[Test]
    public function it_sets_a_vercel_variable_and_redeploys_the_current_production_build(): void
    {
        $http = (new FakeHttpClient)
            ->on('POST /v10/projects/prj_1/env', FakeHttpClient::json(['created' => []]))
            ->on('GET /v6/deployments', FakeHttpClient::json(['deployments' => [['uid' => 'dpl_old', 'name' => 'acme-web']]]))
            ->on('POST /v13/deployments', FakeHttpClient::json(['id' => 'dpl_new']))
            ->on('GET /v13/deployments/dpl_new', FakeHttpClient::json(['readyState' => 'BUILDING']), FakeHttpClient::json(['readyState' => 'READY']));
        $vercel = new VercelDestination(new VercelClient($http, 'vercel-token'));
        $settings = ['project_id' => 'prj_1', 'team_id' => 'team_9', 'secret_name' => 'OPENAI_API_KEY', 'targets' => ['production', 'preview'], 'redeploy' => true];

        $vercel->put($settings, self::KEY);
        [$target] = $vercel->redeployTargets($settings);
        $id = $vercel->deploy($target);

        $env = $http->sentTo('POST', '/v10/projects/prj_1/env')[0];
        $this->assertStringContainsString('upsert=true', $env->url);
        $this->assertStringContainsString('teamId=team_9', $env->url);
        $this->assertSame(['key' => 'OPENAI_API_KEY', 'value' => self::KEY, 'type' => 'encrypted', 'target' => ['production', 'preview']], $env->json);
        $this->assertSame(['name' => 'acme-web', 'deploymentId' => 'dpl_old', 'target' => 'production'], $http->sentTo('POST', '/v13/deployments')[0]->json);
        $this->assertSame('dpl_new', $id);
        $this->assertTrue($vercel->deployment($target, $id)->isRunning());
        $this->assertTrue($vercel->deployment($target, $id)->succeededNow());
    }

    #[Test]
    public function it_does_not_redeploy_vercel_when_production_does_not_use_the_variable(): void
    {
        $vercel = new VercelDestination(new VercelClient(new FakeHttpClient, 'vercel-token'));

        $this->assertSame([], $vercel->redeployTargets(['project_id' => 'prj_1', 'secret_name' => 'X', 'targets' => ['preview'], 'redeploy' => true]));
    }

    #[Test]
    public function it_reads_the_laravel_config_and_never_the_apps_own_keys(): void
    {
        $environment = LaravelSettings::environment([
            'token' => 'crr_x',
            'api_url' => null,
            'schedule' => true,
            'openai_admin_key' => 'sk-admin',
            'aws' => ['access_key_id' => 'AKIARUNNER', 'secret_access_key' => 'runner-secret', 'session_token' => ''],
            'google_cloud' => ['access_token' => null, 'credentials' => null],
            'forge_token' => 'forge-token',
        ]);

        $this->assertSame([
            'COMPLYROBIN_TOKEN' => 'crr_x',
            'OPENAI_ADMIN_KEY' => 'sk-admin',
            'AWS_ACCESS_KEY_ID' => 'AKIARUNNER',
            'AWS_SECRET_ACCESS_KEY' => 'runner-secret',
            'FORGE_API_TOKEN' => 'forge-token',
        ], $environment);
    }

    private function secretsManager(FakeHttpClient $http): SecretsManagerDestination
    {
        $credentials = new AwsCredentials('AKIARUNNER0000000001', 'runner-secret');
        $clock = static fn (): DateTimeImmutable => new DateTimeImmutable('2026-11-17T10:00:00Z');

        return new SecretsManagerDestination(new AwsJsonClient($http, $credentials, $clock), new IamClient($http, $credentials, $clock));
    }
}
