<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Logværk ingest
    |--------------------------------------------------------------------------
    |
    | Nothing is sent unless both the endpoint and token are set, so it is safe
    | to install the package everywhere and only configure it in production.
    |
    | Nothing is sent while running unit tests either, even if the endpoint and
    | token are set in .env. Set send_during_tests to true to override that.
    |
    */

    'enabled' => (bool) env('LOGVAERK_ENABLED', true),

    'endpoint' => env('LOGVAERK_ENDPOINT'),

    'token' => env('LOGVAERK_TOKEN'),

    'send_during_tests' => false,

    /*
    |--------------------------------------------------------------------------
    | Event fields
    |--------------------------------------------------------------------------
    |
    | app_name and hostname are the main filters in the Logværk viewer, so keep
    | them short and consistent across servers. hostname defaults to the
    | machine's hostname.
    |
    */

    'app_name' => env('LOGVAERK_APP_NAME', env('APP_NAME', 'laravel')),

    'hostname' => env('LOGVAERK_HOSTNAME'),

    'level' => env('LOGVAERK_LEVEL', env('LOG_LEVEL', 'debug')),

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Context and extra values whose key contains one of these words are sent
    | as "[redacted]". Keys are compared case-insensitively with separators
    | removed, so "token" also matches "api_token" and "X-Api-Token". Only
    | arrays are searched; objects are sent as the formatter renders them.
    |
    */

    'redact' => [
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | Events are buffered in memory and sent at the end of each request, queued
    | job and scheduled task. They are sent earlier when a record at or above
    | flush_level is logged, the buffer fills up, or the oldest buffered event
    | is older than flush_interval seconds.
    |
    | Each request is capped at batch_size events and max_batch_bytes; the
    | ingest service rejects bodies over 2 MB.
    |
    */

    'flush_level' => env('LOGVAERK_FLUSH_LEVEL', 'error'),

    'buffer_limit' => (int) env('LOGVAERK_BUFFER_LIMIT', 100),

    'flush_interval' => (float) env('LOGVAERK_FLUSH_INTERVAL', 10),

    'batch_size' => (int) env('LOGVAERK_BATCH_SIZE', 500),

    'max_batch_bytes' => (int) env('LOGVAERK_MAX_BATCH_BYTES', 1048576),

    'timeout' => (float) env('LOGVAERK_TIMEOUT', 2),

    'max_message_length' => (int) env('LOGVAERK_MAX_MESSAGE_LENGTH', 32768),

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After a failed delivery, stop sending for this many seconds so a Logværk
    | outage cannot tie up PHP workers. The pause is shared between workers
    | through this cache store (null = the default store). Set to 0 to disable.
    |
    */

    'circuit_breaker_seconds' => (int) env('LOGVAERK_CIRCUIT_BREAKER_SECONDS', 30),

    'circuit_breaker_store' => env('LOGVAERK_CACHE_STORE'),

];
