<?php

namespace Ephort\Logvaerk\Tests;

use Ephort\Logvaerk\LogvaerkHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LaravelIntegrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('logvaerk.endpoint', 'https://ingest.example.test/api/ingest');
        $app['config']->set('logvaerk.token', 'secret-token');
        $app['config']->set('logvaerk.app_name', 'my-app');
        $app['config']->set('logvaerk.send_during_tests', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.channels.daily.path', $this->logPath().'/laravel.log');

        $app->instance(LogvaerkHandler::HTTP_CLIENT, $this->fakeClient());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->logPath());

        parent::tearDown();
    }

    private function logPath(): string
    {
        return sys_get_temp_dir().'/logvaerk-tests';
    }

    public function test_the_logvaerk_channel_is_registered_automatically(): void
    {
        Log::channel('logvaerk')->error('Something broke');
        $this->app->terminate();

        $event = $this->sentEvents()[0];
        $this->assertSame('Something broke', $event['message']);
        $this->assertSame('my-app', $event['app_name']);
        $this->assertSame('error', $event['severity']);
        $this->assertSame('testing', $event['msgid']);
    }

    public function test_an_app_defined_channel_is_not_overwritten(): void
    {
        $this->app['config']->set('logging.channels.logvaerk', [
            'driver' => 'single',
            'path' => $this->logPath().'/custom.log',
        ]);

        $this->app->register(\Ephort\Logvaerk\LogvaerkServiceProvider::class, true);

        $this->assertSame('single', config('logging.channels.logvaerk.driver'));
    }

    public function test_channel_options_override_package_config(): void
    {
        $this->app['config']->set('logging.channels.logvaerk.app_name', 'overridden');

        Log::channel('logvaerk')->info('hi');
        LogvaerkHandler::flushAll();

        $this->assertSame('overridden', $this->sentEvents()[0]['app_name']);
    }

    public function test_a_stack_still_writes_to_the_daily_laravel_log(): void
    {
        $this->app['config']->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['daily', 'logvaerk'],
        ]);
        $this->app['config']->set('logging.default', 'stack');

        Log::warning('Disk almost full');
        $this->app->terminate();

        $files = File::glob($this->logPath().'/laravel-*.log');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('Disk almost full', File::get($files[0]));

        $this->assertSame(['Disk almost full'], array_column($this->sentEvents(), 'message'));
    }

    public function test_buffer_is_flushed_after_each_queued_job(): void
    {
        $job = $this->createStub(Job::class);

        Log::channel('logvaerk')->info('processed');
        event(new JobProcessed('redis', $job));
        $this->assertSame(['processed'], array_column($this->sentEvents(), 'message'));

        Log::channel('logvaerk')->error('failed');
        event(new JobFailed('redis', $job, new RuntimeException('nope')));
        $this->assertSame(['processed', 'failed'], array_column($this->sentEvents(), 'message'));
    }

    public function test_nothing_is_sent_when_not_configured(): void
    {
        $this->app['config']->set('logvaerk.token', null);

        Log::channel('logvaerk')->error('Not configured');
        $this->app->terminate();

        $this->assertCount(0, $this->sent);
    }

    public function test_nothing_is_sent_while_running_unit_tests_by_default(): void
    {
        $this->app['config']->set('logvaerk.send_during_tests', false);

        Log::channel('logvaerk')->error('From a test run');
        $this->app->terminate();

        $this->assertCount(0, $this->sent);
    }

    public function test_it_can_be_switched_off(): void
    {
        $this->app['config']->set('logvaerk.enabled', false);

        Log::channel('logvaerk')->error('Switched off');
        $this->app->terminate();

        $this->assertCount(0, $this->sent);
    }

    public function test_the_level_defaults_to_the_apps_log_level(): void
    {
        $original = [getenv('LOG_LEVEL'), getenv('LOGVAERK_LEVEL')];
        putenv('LOG_LEVEL=warning');
        putenv('LOGVAERK_LEVEL');

        try {
            $config = require __DIR__.'/../config/logvaerk.php';
            $this->assertSame('warning', $config['level']);

            putenv('LOGVAERK_LEVEL=error');
            $config = require __DIR__.'/../config/logvaerk.php';
            $this->assertSame('error', $config['level']);
        } finally {
            putenv($original[0] === false ? 'LOG_LEVEL' : 'LOG_LEVEL='.$original[0]);
            putenv($original[1] === false ? 'LOGVAERK_LEVEL' : 'LOGVAERK_LEVEL='.$original[1]);
        }
    }

    public function test_the_circuit_breaker_is_shared_through_the_app_cache(): void
    {
        $this->mock->reset();
        $this->mock->append(new Response(503));

        Log::channel('logvaerk')->error('fails');

        $key = 'logvaerk:circuit:'.sha1('https://ingest.example.test/api/ingest');
        $this->assertNotNull(Cache::get($key));
    }
}
