<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\Aws\AwsJsonClient;
use ComplyRobin\Runner\Aws\AwsSource;
use ComplyRobin\Runner\Aws\IamClient;
use ComplyRobin\Runner\Aws\ParameterStoreDestination;
use ComplyRobin\Runner\Aws\SecretsManagerDestination;
use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\Destinations\KeyDestination;
use ComplyRobin\Runner\Forge\ForgeClient;
use ComplyRobin\Runner\Forge\ForgeDestination;
use ComplyRobin\Runner\GitHub\GitHubClient;
use ComplyRobin\Runner\GitHub\GitHubDestination;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudClient;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudSource;
use ComplyRobin\Runner\GoogleCloud\SecretManagerDestination;
use ComplyRobin\Runner\Http\CurlHttpClient;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudDestination;
use ComplyRobin\Runner\OpenAi\OpenAiClient;
use ComplyRobin\Runner\OpenAi\OpenAiSource;
use ComplyRobin\Runner\Sources\KeySource;
use ComplyRobin\Runner\Vercel\VercelClient;
use ComplyRobin\Runner\Vercel\VercelDestination;

/**
 * Builds the runner from its settings. A provider or destination is available only when its access is set.
 */
final class Factory
{
    /**
     * @param  (Closure(string): void)|null  $output
     */
    public static function runner(Config $config, ?HttpClient $http = null, ?Closure $output = null): Runner
    {
        $http ??= new CurlHttpClient;

        $mask = $config->inGitHubActions
            ? static function (string $value): void {
                // GitHub replaces the value with *** in every later log line. Printed before anything else could.
                // add-mask takes one line, so a value with line breaks (a key file) is masked line by line. Lines
                // too short to be secret, such as a lone brace, are left alone so logs stay readable.
                foreach (preg_split('/\R/', $value) ?: [] as $line) {
                    if (strlen(trim($line)) >= 8) {
                        fwrite(STDOUT, '::add-mask::'.trim($line).PHP_EOL);
                    }
                }
            }
            : null;

        return new Runner(
            self::complyRobin($config, $http),
            new Rotator(self::sources($config, $http), self::destinations($config, $http), mask: $mask, progress: $output),
            $config->secrets(),
            $output,
            checker: self::checker($config, $http),
        );
    }

    public static function complyRobin(Config $config, ?HttpClient $http = null): ComplyRobinClient
    {
        return new ComplyRobinClient($http ?? new CurlHttpClient, $config->apiUrl, $config->token());
    }

    /**
     * Every service the runner can use, for a test of its access. Destinations that share a provider's access (AWS,
     * Google Cloud) are tested once, under the provider's name.
     */
    public static function checker(Config $config, ?HttpClient $http = null): Checker
    {
        $sources = self::sources($config, $http);
        $destinations = self::destinations($config, $http);

        return new Checker([
            'OpenAI' => $sources['openai'] ?? null,
            'AWS' => $sources['aws'] ?? null,
            'Google Cloud' => $sources['google_cloud'] ?? null,
            'Laravel Cloud' => $destinations['laravel_cloud'] ?? null,
            'GitHub' => $destinations['github'] ?? null,
            'Forge' => $destinations['forge'] ?? null,
            'Vercel' => $destinations['vercel'] ?? null,
        ], $config->secrets());
    }

    /**
     * @return array<string, KeySource> by source type
     */
    public static function sources(Config $config, ?HttpClient $http = null): array
    {
        $http ??= new CurlHttpClient;
        $sources = [];

        if ($config->openAiAdminKey() !== null) {
            $sources['openai'] = new OpenAiSource(new OpenAiClient($http, $config->openAiAdminKey()));
        }

        if ($config->aws() !== null) {
            $sources['aws'] = new AwsSource(new IamClient($http, $config->aws()));
        }

        $google = self::google($config, $http);

        if ($google instanceof GoogleCloudClient) {
            $sources['google_cloud'] = new GoogleCloudSource($google);
        }

        return $sources;
    }

    /**
     * @return array<string, KeyDestination> by destination type
     */
    public static function destinations(Config $config, ?HttpClient $http = null): array
    {
        $http ??= new CurlHttpClient;
        $destinations = [];

        if ($config->laravelCloudToken() !== null) {
            $destinations['laravel_cloud'] = new LaravelCloudDestination(new LaravelCloudClient($http, $config->laravelCloudToken()));
        }

        if ($config->gitHubToken() !== null) {
            $destinations['github'] = new GitHubDestination(new GitHubClient($http, $config->gitHubToken()));
        }

        if ($config->aws() !== null) {
            $json = new AwsJsonClient($http, $config->aws());
            $iam = new IamClient($http, $config->aws());
            $destinations['aws_secrets_manager'] = new SecretsManagerDestination($json, $iam);
            $destinations['aws_parameter_store'] = new ParameterStoreDestination($json, $iam);
        }

        $google = self::google($config, $http);

        if ($google instanceof GoogleCloudClient) {
            $destinations['google_secret_manager'] = new SecretManagerDestination($google);
        }

        if ($config->forgeToken() !== null) {
            $destinations['forge'] = new ForgeDestination(new ForgeClient($http, $config->forgeToken()));
        }

        if ($config->vercelToken() !== null) {
            $destinations['vercel'] = new VercelDestination(new VercelClient($http, $config->vercelToken()));
        }

        return $destinations;
    }

    private static function google(Config $config, HttpClient $http): ?GoogleCloudClient
    {
        if ($config->googleCloudAccessToken() === null && $config->googleCloudCredentials() === null) {
            return null;
        }

        return new GoogleCloudClient($http, $config->googleCloudAccessToken(), $config->googleCloudCredentials());
    }
}
