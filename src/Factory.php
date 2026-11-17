<?php

declare(strict_types=1);

namespace ComplyRobin\Runner;

use Closure;
use ComplyRobin\Runner\ComplyRobin\ComplyRobinClient;
use ComplyRobin\Runner\Http\CurlHttpClient;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\LaravelCloud\LaravelCloudClient;
use ComplyRobin\Runner\OpenAi\OpenAiClient;

/**
 * Builds the runner from its settings.
 */
final class Factory
{
    /**
     * @param  (Closure(string): void)|null  $output
     */
    public static function runner(Config $config, ?HttpClient $http = null, ?Closure $output = null): Runner
    {
        $http ??= new CurlHttpClient;
        [$openAi, $laravelCloud] = self::vendors($config, $http);

        $mask = $config->inGitHubActions
            ? static function (string $value): void {
                // GitHub replaces the value with *** in every later log line. Printed before anything else could.
                fwrite(STDOUT, '::add-mask::'.$value.PHP_EOL);
            }
            : null;

        return new Runner(
            new ComplyRobinClient($http, $config->apiUrl, $config->organisation, $config->runnerToken()),
            new Rotator($openAi, $laravelCloud, mask: $mask),
            $config->secrets(),
            $output,
        );
    }

    /**
     * @return array{0: OpenAiClient|null, 1: LaravelCloudClient|null}
     */
    public static function vendors(Config $config, ?HttpClient $http = null): array
    {
        $http ??= new CurlHttpClient;

        return [
            $config->openAiAdminKey() === null ? null : new OpenAiClient($http, $config->openAiAdminKey()),
            $config->laravelCloudToken() === null ? null : new LaravelCloudClient($http, $config->laravelCloudToken()),
        ];
    }
}
