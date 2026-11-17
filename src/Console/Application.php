<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Console;

use Closure;
use ComplyRobin\Runner\Config;
use ComplyRobin\Runner\Exceptions\ConfigurationError;
use ComplyRobin\Runner\Factory;
use ComplyRobin\Runner\Http\HttpClient;
use ComplyRobin\Runner\Runner;
use ComplyRobin\Runner\Support\Redact;
use Throwable;

/**
 * The command line: `complyrobin-runner run` (the default) and `complyrobin-runner check`.
 */
final readonly class Application
{
    private const string HELP = <<<'TXT'
        ComplyRobin rotation runner %s

        Usage:
          complyrobin-runner [run]   Check in with ComplyRobin and rotate every key that is due.
          complyrobin-runner check   Check the settings and that OpenAI and Laravel Cloud accept the tokens. Rotates nothing.

        Settings (environment variables):
          COMPLYROBIN_RUNNER_TOKEN   The runner token ComplyRobin showed you once (crr_…).
          COMPLYROBIN_ORGANISATION   Your organisation's address name in ComplyRobin.
          OPENAI_ADMIN_KEY           An OpenAI admin key, to make and retire service account keys.
          LARAVEL_CLOUD_API_TOKEN    A Laravel Cloud API token, to update secrets and redeploy.
          COMPLYROBIN_API_URL        Optional. Defaults to https://api.complyrobin.com/v1.
        TXT;

    /** @var Closure(string): void */
    private Closure $output;

    /**
     * @param  array<string, string|false|null>  $env
     * @param  (Closure(string): void)|null  $output
     */
    public function __construct(private array $env, ?Closure $output = null, private ?HttpClient $http = null)
    {
        $this->output = $output ?? static function (string $line): void {
            fwrite(STDOUT, $line.PHP_EOL);
        };
    }

    /**
     * @param  list<string>  $arguments  without the program name
     */
    public function run(array $arguments): int
    {
        $command = $arguments[0] ?? 'run';

        if (in_array($command, ['--version', '-V'], true)) {
            ($this->output)('complyrobin-runner '.Runner::VERSION);

            return 0;
        }

        if (! in_array($command, ['run', 'check'], true)) {
            ($this->output)(sprintf(self::HELP, Runner::VERSION));

            return in_array($command, ['help', '--help', '-h'], true) ? 0 : 64;
        }

        try {
            $config = Config::fromEnvironment($this->env);
        } catch (ConfigurationError $error) {
            ($this->output)($error->getMessage());

            return 78;
        }

        return $command === 'check' ? $this->check($config) : Factory::runner($config, $this->http, $this->output)->run();
    }

    private function check(Config $config): int
    {
        [$openAi, $laravelCloud] = Factory::vendors($config, $this->http);
        $healthy = true;

        foreach (['OpenAI' => $openAi, 'Laravel Cloud' => $laravelCloud] as $service => $client) {
            if ($client === null) {
                ($this->output)("{$service}: no token set, so this runner can't use it.");

                continue;
            }

            try {
                $client->check();
                ($this->output)("{$service}: the token works.");
            } catch (Throwable $exception) {
                $healthy = false;
                ($this->output)("{$service}: ".Redact::text($exception->getMessage(), 300, $config->secrets()));
            }
        }

        ($this->output)("ComplyRobin: will check in at {$config->apiUrl} for {$config->organisation}. The token is checked on the first run.");

        return $healthy ? 0 : 1;
    }
}
