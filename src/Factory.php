<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\Aws\AwsSource;
use ComplyRobin\Runner\Aws\IamClient;
use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudClient;
use ComplyRobin\Runner\GoogleCloud\GoogleCloudSource;
use ComplyRobin\Runner\Http\CurlHttpClient;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\OpenAi\OpenAiClient;
use ComplyRobin\Runner\OpenAi\OpenAiSource;
use ComplyRobin\Runner\Sources\KeySource;

/**
 * Builds the runner from its settings. A provider is available only when its access is set.
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
            new ComplyRobinClient($http, $config->apiUrl, $config->organisation, $config->runnerToken()),
            new Rotator(self::sources($config, $http), self::laravelCloud($config, $http), mask: $mask),
            $config->secrets(),
            $output,
        );
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

        if ($config->googleCloudAccessToken() !== null || $config->googleCloudCredentials() !== null) {
            $sources['google_cloud'] = new GoogleCloudSource(new GoogleCloudClient($http, $config->googleCloudAccessToken(), $config->googleCloudCredentials()));
        }

        return $sources;
    }

    public static function laravelCloud(Config $config, ?HttpClient $http = null): ?LaravelCloudClient
    {
        return $config->laravelCloudToken() === null ? null : new LaravelCloudClient($http ?? new CurlHttpClient, $config->laravelCloudToken());
    }
}
