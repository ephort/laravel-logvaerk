# Laravel Logværk

Ship your Laravel application logs to [Logværk](https://logvaerk.dk) while keeping your regular `laravel.log`.

- Works with Laravel 8 – 13 (Monolog 2 and 3), PHP 8.0+
- Buffers log records in memory and sends them in batches at the end of each request, queued job and scheduled task. Errors are sent immediately
- Never breaks your app: short timeouts, all errors swallowed, and a circuit breaker that pauses delivery for every worker when Logværk is down. Your local log still has everything
- Redacts passwords, tokens, cookies and similar keys from log context before it leaves the server
- Does nothing until an endpoint and token are configured, and never sends during unit tests, so it is safe to install everywhere

## Installation

```bash
composer require ephort/laravel-logvaerk
```

Add the credentials for your Logværk tenant to `.env`:

```dotenv
LOGVAERK_ENDPOINT=https://ingest.<tenant>.logvaerk.dk/api/ingest
LOGVAERK_TOKEN=<ingest api key>
LOGVAERK_APP_NAME=my-app          # shown as app_name in Logværk, defaults to APP_NAME
```

Then add the `logvaerk` channel to your log stack. The package registers the channel for you.

**Laravel 11+** — only `.env` is needed:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=daily,logvaerk
```

**Laravel 8 – 10** — add it to the `stack` channel in `config/logging.php`:

```php
'stack' => [
    'driver' => 'stack',
    'channels' => ['daily', 'logvaerk'],
    'ignore_exceptions' => false,
],
```

If you cache config in production, remember `php artisan config:cache` after changing `.env`.

## What is sent

Each log record becomes one Logværk event:

| Logværk field | Value |
|---------------|-------|
| `timestamp` | Record time in UTC, millisecond precision |
| `severity` | `debug`, `info`, `notice`, `warning`, `error`, `crit`, `alert`, `emerg` |
| `hostname` | `LOGVAERK_HOSTNAME`, or the server's hostname |
| `app_name` | `LOGVAERK_APP_NAME` |
| `facility` | `user` |
| `procid` | PHP process id |
| `msgid` | Logger channel name (the environment, e.g. `production`) |
| `message` | The message, followed by context and extra as JSON. Exceptions include their stack trace |

### Redaction

Context and extra values whose key contains `password`, `passwd`, `secret`, `token`, `apikey`, `authorization`, `cookie`, `privatekey`, `creditcard`, `cardnumber`, `cvv` or `cvc` are sent as `[redacted]`, at any depth. Keys are compared case-insensitively with separators removed, so `api_token`, `X-Api-Key` and `Set-Cookie` are all caught, while `author` is not.

Change the list with the `redact` option in the published config. Only arrays are searched: objects in the context are sent as Monolog renders them, and the log message itself is never altered, so don't interpolate secrets into messages.

## Configuration

All options can be set through `.env`. To change them in code, publish the config file:

```bash
php artisan vendor:publish --tag=logvaerk-config
```

| Env | Default | |
|-----|---------|-|
| `LOGVAERK_ENDPOINT` | – | Ingest URL. Nothing is sent when empty |
| `LOGVAERK_TOKEN` | – | Ingest API key. Nothing is sent when empty |
| `LOGVAERK_ENABLED` | `true` | Switch delivery off without removing the credentials |
| `LOGVAERK_APP_NAME` | `APP_NAME` | |
| `LOGVAERK_HOSTNAME` | `gethostname()` | |
| `LOGVAERK_LEVEL` | `LOG_LEVEL`, else `debug` | Minimum level sent to Logværk |
| `LOGVAERK_FLUSH_LEVEL` | `error` | Records at or above this level are sent immediately |
| `LOGVAERK_BUFFER_LIMIT` | `100` | Flush when this many records are buffered |
| `LOGVAERK_FLUSH_INTERVAL` | `10` | Flush when the oldest buffered record is older than this (seconds) |
| `LOGVAERK_BATCH_SIZE` | `500` | Max events per HTTP request |
| `LOGVAERK_MAX_BATCH_BYTES` | `1048576` | Max body size per HTTP request. The ingest service rejects bodies over 2 MB |
| `LOGVAERK_TIMEOUT` | `2` | HTTP timeout in seconds |
| `LOGVAERK_MAX_MESSAGE_LENGTH` | `32768` | Longer messages are truncated |
| `LOGVAERK_CIRCUIT_BREAKER_SECONDS` | `30` | Pause delivery this long after a failure. `0` disables the circuit breaker |
| `LOGVAERK_CACHE_STORE` | default store | Cache store used to share the pause between workers |

Options can also be set per channel if you want several Logværk channels, e.g. a separate app name for a worker:

```php
'logvaerk-worker' => [
    'driver' => 'custom',
    'via' => Ephort\Logvaerk\CreateLogvaerkLogger::class,
    'app_name' => 'my-app-worker',
    'level' => 'warning',
],
```

## When logs are sent

Records are buffered and flushed:

- immediately for `error` and above, so they survive a worker that is killed right after (job timeout, out of memory)
- when the request has finished (after the response is sent under PHP-FPM)
- after every queued job (processed or failed) and every scheduled task
- when the buffer reaches `LOGVAERK_BUFFER_LIMIT`, or the oldest buffered record is older than `LOGVAERK_FLUSH_INTERVAL` when the next one is logged
- when the PHP process exits

A long-running process that logs rarely (e.g. a custom daemon loop) only sends when it next logs, finishes a job or exits.

## When Logværk is down

A failed delivery (timeout, connection error or non-2xx response, including `401` for a wrong token) drops that flush and opens a circuit breaker for `LOGVAERK_CIRCUIT_BREAKER_SECONDS`. While it is open nothing is sent, so an outage costs each worker at most one timeout per pause rather than one per request. The pause is stored in the cache, so all FPM and queue workers on the server back off together. If the cache itself is unavailable, each process falls back to its own in-memory pause.

Failures are written as a single line to PHP's `error_log`, never to the Laravel log (to avoid loops). There are no retries: `laravel.log` stays the source of truth.

## Unit tests

Nothing is sent while the application runs its unit tests (`APP_ENV=testing`), even if the Logværk credentials are in `.env`. To send anyway, set `send_during_tests` to `true` in the published config.

## Running the package tests

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
