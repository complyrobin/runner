<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Console;

use Closure;
use ComplyRobin\Runner\Checker;
use ComplyRobin\Runner\Config;
use ComplyRobin\Runner\Exceptions\ApiError;
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
          complyrobin-runner check   Test the runner's access to each service and send the results to ComplyRobin.
                                     Rotates nothing.

        Settings (environment variables). Set only the ones you use:
          COMPLYROBIN_TOKEN          The runner token ComplyRobin showed you once (crr_…).

          Where keys are made:
          OPENAI_ADMIN_KEY           An OpenAI admin key, to make and retire service account keys.
          AWS_ACCESS_KEY_ID          AWS access that can manage the IAM users' access keys (with
          AWS_SECRET_ACCESS_KEY      AWS_SESSION_TOKEN when it is short-lived, as from GitHub's OIDC sign-in).
          GOOGLE_CLOUD_ACCESS_TOKEN  A Google Cloud access token that can manage service account keys, or
          GOOGLE_CLOUD_CREDENTIALS   the key file (JSON) of a service account that can.

          Where keys go (AWS and Google Cloud secrets use the access above):
          LARAVEL_CLOUD_API_TOKEN    A Laravel Cloud API token, to update secrets and redeploy.
          GH_SECRETS_TOKEN       A GitHub token that can write the repository's Actions secrets.
          FORGE_API_TOKEN            A Laravel Forge API token, to update a site's environment and deploy.
          VERCEL_TOKEN               A Vercel token, to update environment variables and redeploy.

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

    /**
     * Tests every service, then sends the results to ComplyRobin, which also proves the token works.
     */
    private function check(Config $config): int
    {
        $checker = Factory::checker($config, $this->http);
        $results = $checker->run();

        foreach ($results as $result) {
            ($this->output)("{$result['service']}: {$result['message']}");
        }

        try {
            Factory::complyRobin($config, $this->http)->sendCheck($results, $checker->inventory());
            ($this->output)('ComplyRobin: the token works, and the results are on the Rotation runners page.');
        } catch (ApiError $error) {
            ($this->output)('ComplyRobin: '.($error->status === 401 ? 'the token was refused. Copy it again from ComplyRobin, or add a new runner there.' : Redact::text($error->getMessage(), 300, $config->secrets())));

            return Runner::UNREACHABLE;
        } catch (Throwable $exception) {
            ($this->output)('ComplyRobin: '.Redact::text($exception->getMessage(), 300, $config->secrets()));

            return Runner::UNREACHABLE;
        }

        return Checker::healthy($results) ? Runner::OK : Runner::ROTATION_FAILED;
    }
}
