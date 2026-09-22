<?php

namespace Ephort\Logvaerk\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Ephort\Logvaerk\LogvaerkHandler;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Monolog\Logger;
use RuntimeException;

class LogvaerkHandlerTest extends TestCase
{
    private function makeLogger(array $options = []): Logger
    {
        $handler = new LogvaerkHandler(array_merge([
            'endpoint' => 'https://ingest.example.test/api/ingest',
            'token' => 'secret-token',
            'app_name' => 'my-app',
            'hostname' => 'web-01',
            'client' => $options['client'] ?? $this->fakeClient(),
        ], $options));

        return new Logger('production', [$handler]);
    }

    public function test_it_buffers_events_until_flushed(): void
    {
        $logger = $this->makeLogger();

        $logger->info('Hello');

        $this->assertCount(0, $this->sent);

        $logger->close();

        $this->assertCount(1, $this->sent);
    }

    public function test_it_sends_events_in_the_logvaerk_format(): void
    {
        $logger = $this->makeLogger();
        $logger->setTimezone(new DateTimeZone('Europe/Copenhagen'));

        $logger->error('Payment failed');
        $logger->close();

        $request = $this->sent[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://ingest.example.test/api/ingest', (string) $request->getUri());
        $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));

        $event = $this->sentEvents()[0];
        $this->assertSame('error', $event['severity']);
        $this->assertSame('web-01', $event['hostname']);
        $this->assertSame('my-app', $event['app_name']);
        $this->assertSame('Payment failed', $event['message']);
        $this->assertSame('user', $event['facility']);
        $this->assertSame((string) getmypid(), $event['procid']);
        $this->assertSame('production', $event['msgid']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $event['timestamp']);

        $sentAt = new DateTimeImmutable($event['timestamp']);
        $this->assertLessThan(5, abs($sentAt->getTimestamp() - time()));
    }

    public function test_it_maps_monolog_levels_to_syslog_severities(): void
    {
        $logger = $this->makeLogger();

        $expected = [
            'debug' => 'debug',
            'info' => 'info',
            'notice' => 'notice',
            'warning' => 'warning',
            'error' => 'error',
            'critical' => 'crit',
            'alert' => 'alert',
            'emergency' => 'emerg',
        ];

        foreach (array_keys($expected) as $method) {
            $logger->{$method}($method);
        }
        $logger->close();

        $severities = array_column($this->sentEvents(), 'severity', 'message');
        $this->assertSame($expected, $severities);
    }

    public function test_it_includes_context_and_exception_traces_in_the_message(): void
    {
        $logger = $this->makeLogger();

        $logger->warning('Order sync failed', ['order_id' => 42]);
        $logger->error('Boom', ['exception' => new RuntimeException('Database gone away')]);
        $logger->close();

        [$warning, $error] = $this->sentEvents();

        $this->assertStringStartsWith('Order sync failed', $warning['message']);
        $this->assertStringContainsString('"order_id":42', $warning['message']);

        $this->assertStringStartsWith('Boom', $error['message']);
        $this->assertStringContainsString('RuntimeException', $error['message']);
        $this->assertStringContainsString('Database gone away', $error['message']);
        $this->assertStringContainsString('LogvaerkHandlerTest.php', $error['message']);
    }

    public function test_it_flushes_automatically_when_the_buffer_limit_is_reached(): void
    {
        $logger = $this->makeLogger(['buffer_limit' => 3]);

        $logger->info('one');
        $logger->info('two');
        $this->assertCount(0, $this->sent);

        $logger->info('three');
        $this->assertCount(1, $this->sent);
        $this->assertCount(3, $this->sentEvents());
    }

    public function test_it_flushes_when_the_oldest_buffered_event_exceeds_the_flush_interval(): void
    {
        $handler = new LogvaerkHandler([
            'endpoint' => 'https://ingest.example.test/api/ingest',
            'token' => 'secret-token',
            'flush_interval' => 5,
            'client' => $this->fakeClient(),
        ]);
        $logger = new Logger('production', [$handler]);

        $now = 1000.0;
        $handler->setClock(function () use (&$now) {
            return $now;
        });

        $logger->info('first');
        $now += 4;
        $logger->info('second');
        $this->assertCount(0, $this->sent);

        $now += 2;
        $logger->info('third');
        $this->assertCount(1, $this->sent);
        $this->assertCount(3, $this->sentEvents());
    }

    public function test_it_splits_large_flushes_into_batches(): void
    {
        $logger = $this->makeLogger(['buffer_limit' => 1000, 'batch_size' => 2]);

        for ($i = 0; $i < 5; $i++) {
            $logger->info("event {$i}");
        }
        $logger->close();

        $this->assertSame([2, 2, 1], array_map('count', $this->sentBatches()));
    }

    public function test_it_never_throws_when_logvaerk_is_unreachable(): void
    {
        $client = $this->fakeClient(0);
        $this->mock->append(
            new ConnectException('Connection refused', new Request('POST', 'https://ingest.example.test')),
            new Response(500),
            new Response(202)
        );
        $logger = $this->makeLogger(['client' => $client, 'buffer_limit' => 1, 'circuit_breaker_seconds' => 0]);

        $logger->info('first');
        $logger->info('second');
        $logger->info('third');

        $this->assertCount(3, $this->sent);
        $this->assertSame('third', $this->sentBatches()[2][0]['message']);
    }

    public function test_it_is_a_no_op_without_endpoint_or_token(): void
    {
        foreach ([['endpoint' => null], ['token' => '']] as $missing) {
            $logger = $this->makeLogger($missing);

            $logger->error('Should not be sent');
            $logger->close();
        }

        $this->assertCount(0, $this->sent);
    }

    public function test_it_ignores_records_below_the_configured_level(): void
    {
        $logger = $this->makeLogger(['level' => 'warning']);

        $logger->info('ignored');
        $logger->warning('kept');
        $logger->close();

        $this->assertSame(['kept'], array_column($this->sentEvents(), 'message'));
    }

    public function test_it_truncates_very_long_messages(): void
    {
        $logger = $this->makeLogger(['max_message_length' => 100]);

        $logger->info(str_repeat('a', 500));
        $logger->close();

        $message = $this->sentEvents()[0]['message'];
        $this->assertLessThanOrEqual(100, strlen($message));
        $this->assertStringEndsWith('[truncated]', $message);
    }

    public function test_flush_all_flushes_every_live_handler(): void
    {
        $first = $this->makeLogger();
        $second = $this->makeLogger(['client' => $first->getHandlers()[0]->getClient()]);

        $first->info('from first');
        $second->info('from second');

        LogvaerkHandler::flushAll();

        $this->assertEqualsCanonicalizing(['from first', 'from second'], array_column($this->sentEvents(), 'message'));
    }

    public function test_it_sends_error_and_above_immediately(): void
    {
        $logger = $this->makeLogger();

        $logger->info('buffered');
        $logger->warning('still buffered');
        $this->assertCount(0, $this->sent);

        $logger->error('urgent');
        $this->assertSame(['buffered', 'still buffered', 'urgent'], array_column($this->sentEvents(), 'message'));

        $logger->critical('also urgent');
        $this->assertCount(2, $this->sent);
    }

    public function test_the_immediate_flush_level_is_configurable(): void
    {
        $logger = $this->makeLogger(['flush_level' => 'critical']);

        $logger->error('buffered');
        $this->assertCount(0, $this->sent);

        $logger->critical('urgent');
        $this->assertCount(1, $this->sent);
    }

    public function test_it_caps_each_batch_by_size(): void
    {
        $logger = $this->makeLogger(['buffer_limit' => 1000, 'max_batch_bytes' => 2000]);

        for ($i = 0; $i < 10; $i++) {
            $logger->info(str_repeat('x', 400));
        }
        $logger->close();

        $this->assertGreaterThan(1, count($this->sent));
        $this->assertCount(10, $this->sentEvents());
        foreach ($this->sent as $entry) {
            $this->assertLessThanOrEqual(2000, $entry['request']->getBody()->getSize());
        }
    }

    public function test_a_single_event_larger_than_the_batch_cap_is_still_sent_alone(): void
    {
        $logger = $this->makeLogger(['max_batch_bytes' => 100]);

        $logger->info('small');
        $logger->info(str_repeat('x', 500));
        $logger->close();

        $this->assertSame([1, 1], array_map('count', $this->sentBatches()));
    }

    public function test_a_failed_send_opens_the_circuit_breaker(): void
    {
        $client = $this->fakeClient(0);
        $this->mock->append(new Response(503), new Response(202));

        $handler = new LogvaerkHandler([
            'endpoint' => 'https://ingest.example.test/api/ingest',
            'token' => 'secret-token',
            'circuit_breaker_seconds' => 30,
            'client' => $client,
        ]);
        $logger = new Logger('production', [$handler]);

        $now = 1000.0;
        $handler->setClock(function () use (&$now) {
            return $now;
        });

        $logger->error('fails');
        $this->assertCount(1, $this->sent);

        $now += 29;
        $logger->error('dropped while open');
        $this->assertCount(1, $this->sent);

        $now += 2;
        $logger->error('sent after cool down');
        $this->assertCount(2, $this->sent);
        $this->assertSame(['sent after cool down'], array_column($this->sentBatches()[1], 'message'));
    }

    public function test_an_open_circuit_is_shared_between_processes_through_the_cache(): void
    {
        $cache = new Repository(new ArrayStore());

        $client = $this->fakeClient(0);
        $this->mock->append(new Response(503), new Response(202));

        $first = $this->makeLogger(['client' => $client, 'cache' => $cache]);
        $second = $this->makeLogger(['client' => $client, 'cache' => $cache]);

        $first->error('fails');
        $second->error('skipped, another worker saw Logværk fail');

        $this->assertCount(1, $this->sent);
    }

    public function test_the_circuit_breaker_is_scoped_to_the_endpoint(): void
    {
        $cache = new Repository(new ArrayStore());

        $client = $this->fakeClient(0);
        $this->mock->append(new Response(503), new Response(202));

        $first = $this->makeLogger(['client' => $client, 'cache' => $cache]);
        $other = $this->makeLogger(['client' => $client, 'cache' => $cache, 'endpoint' => 'https://ingest.other.test/api/ingest']);

        $first->error('fails');
        $other->error('different tenant, still sent');

        $this->assertCount(2, $this->sent);
    }

    public function test_a_broken_cache_does_not_break_logging(): void
    {
        $cache = $this->createStub(CacheRepository::class);
        $cache->method('get')->willThrowException(new RuntimeException('Redis down'));
        $cache->method('put')->willThrowException(new RuntimeException('Redis down'));

        $client = $this->fakeClient(0);
        $this->mock->append(new Response(202), new Response(503), new Response(202));

        $logger = $this->makeLogger(['client' => $client, 'cache' => $cache]);

        $logger->error('sent');
        $logger->error('fails');
        $logger->error('dropped by the in-memory breaker');

        $this->assertCount(2, $this->sent);
    }

    public function test_it_redacts_sensitive_context_keys(): void
    {
        $logger = $this->makeLogger();

        $logger->info('Login attempt', [
            'email' => 'user@example.com',
            'password' => 'hunter2',
            'author' => 'Jane',
            'request' => [
                'headers' => ['Authorization' => 'Bearer abc', 'X-Api-Key' => 'k-123', 'Accept' => 'json'],
                'api_token' => 'tok-456',
                'client_secret' => 's3cr3t',
                'Set-Cookie' => 'laravel_session=xyz',
            ],
        ]);
        $logger->close();

        $message = $this->sentEvents()[0]['message'];

        foreach (['hunter2', 'Bearer abc', 'k-123', 'tok-456', 's3cr3t', 'laravel_session=xyz'] as $secret) {
            $this->assertStringNotContainsString($secret, $message);
        }
        $this->assertStringContainsString('user@example.com', $message);
        $this->assertStringContainsString('"author":"Jane"', $message);
        $this->assertStringContainsString('"Accept":"json"', $message);
        $this->assertStringContainsString('"password":"[redacted]"', $message);
    }

    public function test_redacted_keys_are_configurable(): void
    {
        $logger = $this->makeLogger(['redact' => ['cpr']]);

        $logger->info('Citizen lookup', ['cpr_number' => '010190-1234', 'password' => 'visible-now']);
        $logger->close();

        $message = $this->sentEvents()[0]['message'];
        $this->assertStringNotContainsString('010190-1234', $message);
        $this->assertStringContainsString('visible-now', $message);
    }

    public function test_it_is_a_no_op_when_disabled(): void
    {
        $logger = $this->makeLogger(['enabled' => false]);

        $logger->error('Should not be sent');
        $logger->close();

        $this->assertCount(0, $this->sent);
    }
}
