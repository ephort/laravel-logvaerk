<?php

namespace Ephort\Logvaerk;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\ServiceProvider;

class LogvaerkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/logvaerk.php', 'logvaerk');

        if (! $this->app['config']->has('logging.channels.logvaerk')) {
            $this->app['config']->set('logging.channels.logvaerk', [
                'driver' => 'custom',
                'via' => CreateLogvaerkLogger::class,
            ]);
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/logvaerk.php' => config_path('logvaerk.php'),
            ], 'logvaerk-config');
        }

        $flush = static function (): void {
            LogvaerkHandler::flushAll();
        };

        $this->app->terminating($flush);

        $this->app['events']->listen([
            JobProcessed::class,
            JobFailed::class,
            ScheduledTaskFinished::class,
            ScheduledTaskFailed::class,
        ], $flush);
    }
}
