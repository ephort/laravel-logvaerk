<?php

namespace Ephort\Logvaerk;

use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Contracts\Cache\Repository;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Throwable;
use WeakMap;

/**
 * Buffers log records in memory and ships them to the Logværk ingest API in batches.
 *
 * Supports Monolog 2 (array records) and Monolog 3 (LogRecord objects): the record
 * parameters are intentionally untyped, which PHP accepts as a widening of either.
 *
 * Sending never throws. If Logværk fails, the batch is dropped, a single line is written
 * to PHP's error_log and the circuit breaker pauses sending for a while. The app's regular
 * log channel still has everything.
 */
class LogvaerkHandler extends AbstractHandler
{
    /** Container binding used to swap the HTTP client, e.g. in tests. */
    public const HTTP_CLIENT = 'logvaerk.http-client';

    public const DEFAULT_REDACT = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'authorization',
        'cookie',
        'privatekey',
        'creditcard',
        'cardnumber',
        'cvv',
        'cvc',
    ];

    private const SEVERITIES = [
        'DEBUG' => 'debug',
        'INFO' => 'info',
        'NOTICE' => 'notice',
        'WARNING' => 'warning',
        'ERROR' => 'error',
        'CRITICAL' => 'crit',
        'ALERT' => 'alert',
        'EMERGENCY' => 'emerg',
    ];

    private const TRUNCATED = ' [truncated]';

    private const REDACTED = '[redacted]';

    /** @var WeakMap<self, true>|null */
    private static ?WeakMap $instances = null;

    private bool $enabled;

    private ?string $endpoint;

    private ?string $token;

    private string $appName;

    private string $hostname;

    private string $facility;

    private int $flushLevel;

    private int $bufferLimit;

    private int $batchSize;

    private int $maxBatchBytes;

    private float $flushInterval;

    private float $timeout;

    private int $maxMessageLength;

    /** @var array<int, string> */
    private array $redact;

    private ?ClientInterface $client;

    private CircuitBreaker $circuitBreaker;

    private LineFormatter $messageFormatter;

    /** @var callable(): float */
    private $clock;

    /** @var array<int, array<string, string>> */
    private array $buffer = [];

    private ?float $oldestBufferedAt = null;

    /**
     * @param  array{
     *     enabled?: bool,
     *     endpoint?: string|null,
     *     token?: string|null,
     *     app_name?: string|null,
     *     hostname?: string|null,
     *     facility?: string|null,
     *     level?: int|string,
     *     flush_level?: int|string,
     *     bubble?: bool,
     *     buffer_limit?: int,
     *     batch_size?: int,
     *     max_batch_bytes?: int,
     *     flush_interval?: int|float,
     *     timeout?: int|float,
     *     max_message_length?: int,
     *     redact?: array<int, string>,
     *     circuit_breaker_seconds?: int,
     *     cache?: Repository|null,
     *     client?: ClientInterface|null,
     * }  $options
     */
    public function __construct(array $options = [])
    {
        parent::__construct(
            Logger::toMonologLevel($options['level'] ?? 'debug'),
            $options['bubble'] ?? true
        );

        $this->endpoint = ($options['endpoint'] ?? null) ?: null;
        $this->token = ($options['token'] ?? null) ?: null;
        $this->enabled = (bool) ($options['enabled'] ?? true) && $this->endpoint !== null && $this->token !== null;
        $this->appName = ($options['app_name'] ?? null) ?: 'laravel';
        $this->hostname = ($options['hostname'] ?? null) ?: (gethostname() ?: 'unknown');
        $this->facility = ($options['facility'] ?? null) ?: 'user';
        $this->flushLevel = self::levelValue($options['flush_level'] ?? 'error');
        $this->bufferLimit = max(1, (int) ($options['buffer_limit'] ?? 100));
        $this->batchSize = max(1, (int) ($options['batch_size'] ?? 500));
        $this->maxBatchBytes = max(1, (int) ($options['max_batch_bytes'] ?? 1048576));
        $this->flushInterval = (float) ($options['flush_interval'] ?? 10);
        $this->timeout = (float) ($options['timeout'] ?? 2);
        $this->maxMessageLength = max(strlen(self::TRUNCATED) + 1, (int) ($options['max_message_length'] ?? 32768));
        $this->redact = array_values(array_filter(array_map(
            [self::class, 'normalizeKey'],
            $options['redact'] ?? self::DEFAULT_REDACT
        )));
        $this->client = $options['client'] ?? null;
        $this->circuitBreaker = CircuitBreaker::forEndpoint(
            (string) $this->endpoint,
            (int) ($options['circuit_breaker_seconds'] ?? 30),
            $options['cache'] ?? null
        );
        $this->clock = fn (): float => microtime(true);

        $this->messageFormatter = new LineFormatter('%message% %context% %extra%', null, true, true);
        $this->messageFormatter->includeStacktraces(true);

        self::$instances ??= new WeakMap();
        self::$instances[$this] = true;
    }

    /**
     * Flush every live handler. Called at the end of requests, queued jobs and scheduled tasks.
     */
    public static function flushAll(): void
    {
        foreach (self::$instances ?? [] as $handler => $_) {
            $handler->flush();
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param  array<string, mixed>|\Monolog\LogRecord  $record
     */
    public function handle($record): bool
    {
        if (! $this->enabled || ! $this->isHandling($record)) {
            return false;
        }

        $event = $this->toEvent($record);
        $now = ($this->clock)();
        $this->oldestBufferedAt ??= $now;
        $this->buffer[] = $event;

        if ($this->shouldFlush($record, $now)) {
            $this->flush();
        }

        return $this->bubble === false;
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];
        $this->oldestBufferedAt = null;

        if ($this->circuitBreaker->isOpen()) {
            return;
        }

        $remaining = count($events);

        foreach ($this->batches($events) as [$count, $body]) {
            $failure = $this->send($body);

            if ($failure !== null) {
                $this->circuitBreaker->trip();
                error_log(sprintf('[logvaerk] Dropped %d log event(s), pausing delivery: %s', $remaining, $failure));

                return;
            }

            $remaining -= $count;
        }
    }

    public function close(): void
    {
        $this->flush();
    }

    public function reset(): void
    {
        $this->flush();

        parent::reset();
    }

    public function getClient(): ClientInterface
    {
        return $this->client ??= new Client();
    }

    /**
     * @param  callable(): float  $clock
     */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
        $this->circuitBreaker->setClock($clock);
    }

    /**
     * @param  int|string|\Monolog\Level  $level
     */
    private static function levelValue($level): int
    {
        $level = Logger::toMonologLevel($level);

        return is_int($level) ? $level : $level->value;
    }

    private static function normalizeKey($key): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $key));
    }

    /**
     * @param  array<string, mixed>|\Monolog\LogRecord  $record
     */
    private function shouldFlush($record, float $now): bool
    {
        $level = is_array($record) ? $record['level'] : $record->level->value;

        return $level >= $this->flushLevel
            || count($this->buffer) >= $this->bufferLimit
            || $now - $this->oldestBufferedAt >= $this->flushInterval;
    }

    /**
     * @param  array<string, mixed>|\Monolog\LogRecord  $record
     * @return array<string, string>
     */
    private function toEvent($record): array
    {
        $record = $this->redactRecord($record);
        $data = is_array($record) ? $record : $record->toArray();

        return [
            'timestamp' => $data['datetime']->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'severity' => self::SEVERITIES[$data['level_name']] ?? strtolower($data['level_name']),
            'facility' => $this->facility,
            'hostname' => $this->hostname,
            'app_name' => $this->appName,
            'procid' => (string) getmypid(),
            'msgid' => (string) $data['channel'],
            'message' => $this->truncate(trim($this->messageFormatter->format($record))),
        ];
    }

    /**
     * @param  array<string, mixed>|\Monolog\LogRecord  $record
     * @return array<string, mixed>|\Monolog\LogRecord
     */
    private function redactRecord($record)
    {
        if ($this->redact === []) {
            return $record;
        }

        if (is_array($record)) {
            $record['context'] = $this->redactArray($record['context']);
            $record['extra'] = $this->redactArray($record['extra']);

            return $record;
        }

        return $record->with(
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra)
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redactArray($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = self::normalizeKey($key);

        foreach ($this->redact as $pattern) {
            if (str_contains($key, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $message): string
    {
        if (strlen($message) <= $this->maxMessageLength) {
            return $message;
        }

        return mb_strcut($message, 0, $this->maxMessageLength - strlen(self::TRUNCATED), 'UTF-8').self::TRUNCATED;
    }

    /**
     * Split events into JSON array bodies capped by event count and byte size.
     * An event larger than the byte cap on its own is sent alone.
     *
     * @param  array<int, array<string, string>>  $events
     * @return iterable<array{int, string}>
     */
    private function batches(array $events): iterable
    {
        $encoded = [];
        $bytes = 2;

        foreach ($events as $event) {
            $json = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $size = strlen($json) + 1;

            if ($encoded !== [] && (count($encoded) >= $this->batchSize || $bytes + $size > $this->maxBatchBytes)) {
                yield [count($encoded), '['.implode(',', $encoded).']'];
                $encoded = [];
                $bytes = 2;
            }

            $encoded[] = $json;
            $bytes += $size;
        }

        if ($encoded !== []) {
            yield [count($encoded), '['.implode(',', $encoded).']'];
        }
    }

    /**
     * @return string|null The failure reason, or null when Logværk accepted the batch.
     */
    private function send(string $body): ?string
    {
        try {
            $response = $this->getClient()->request('POST', $this->endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => $body,
                'timeout' => $this->timeout,
                'connect_timeout' => min($this->timeout, 1.0),
                'http_errors' => false,
            ]);

            return $response->getStatusCode() < 300 ? null : 'HTTP '.$response->getStatusCode();
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }
}
