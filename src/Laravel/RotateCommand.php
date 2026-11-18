<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Laravel;

use ComplyRobin\Runner\Console\Application;
use Illuminate\Console\Command;

/**
 * `php artisan complyrobin:rotate`: the runner, inside a Laravel app. With --check it tests the access and rotates
 * nothing.
 */
final class RotateCommand extends Command
{
    /** @var string */
    protected $signature = 'complyrobin:rotate {--check : Test the runner\'s access to each service and rotate nothing}';

    /** @var string */
    protected $description = 'Rotate the API keys ComplyRobin says are due';

    public function handle(): int
    {
        /** @var array<string, mixed> $config */
        $config = (array) $this->laravel['config']->get('complyrobin-runner', []);

        $application = new Application(LaravelSettings::environment($config), function (string $line): void {
            $this->line($line);
        });

        return $application->run([$this->option('check') ? 'check' : 'run']);
    }
}
