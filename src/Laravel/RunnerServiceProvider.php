<?php

declare(strict_types=1);

namespace ComplyRobin\Runner\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * Adds the runner to a Laravel app: config/complyrobin-runner.php, `php artisan complyrobin:rotate`, and an hourly
 * run from the app's scheduler once a token is set. Laravel finds this provider by itself (package discovery).
 */
final class RunnerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/complyrobin-runner.php', 'complyrobin-runner');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([RotateCommand::class]);

        $this->publishes([
            __DIR__.'/../../config/complyrobin-runner.php' => $this->app->configPath('complyrobin-runner.php'),
        ], 'complyrobin-runner-config');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app['config'];

            if ($config->get('complyrobin-runner.schedule') && is_string($config->get('complyrobin-runner.token')) && $config->get('complyrobin-runner.token') !== '') {
                $schedule->command(RotateCommand::class)->hourlyAt(17)->withoutOverlapping(120);
            }
        });
    }
}
