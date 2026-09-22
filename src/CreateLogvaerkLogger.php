<?php

namespace Ephort\Logvaerk;

use Illuminate\Contracts\Cache\Repository;
use Monolog\Logger;
use Throwable;

/**
 * Factory for Laravel's "custom" log driver: 'driver' => 'custom', 'via' => CreateLogvaerkLogger::class.
 *
 * Options set on the channel itself take precedence over config/logvaerk.php.
 */
class CreateLogvaerkLogger
{
    public function __invoke(array $config): Logger
    {
        $options = array_merge(config('logvaerk', []), array_filter($config, fn ($value) => $value !== null));

        if (app()->runningUnitTests() && ! ($options['send_during_tests'] ?? false)) {
            $options['enabled'] = false;
        }

        if (app()->bound(LogvaerkHandler::HTTP_CLIENT)) {
            $options['client'] = app(LogvaerkHandler::HTTP_CLIENT);
        }

        $options['cache'] = $this->cacheStore($options['circuit_breaker_store'] ?? null);

        return new Logger($options['name'] ?? app()->environment(), [new LogvaerkHandler($options)]);
    }

    private function cacheStore(?string $store): ?Repository
    {
        try {
            return app('cache')->store($store);
        } catch (Throwable $e) {
            return null;
        }
    }
}
