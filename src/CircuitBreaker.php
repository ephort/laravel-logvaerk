<?php

namespace Ephort\Logvaerk;

use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Stops sending for a while after Logværk fails, so an outage cannot tie up PHP workers.
 *
 * The open state is kept in memory and, when a cache store is available, shared through it
 * so every FPM and queue worker backs off together. A broken cache falls back to in-memory.
 */
class CircuitBreaker
{
    private float $openUntil = 0.0;

    /** @var callable(): float */
    private $clock;

    public function __construct(
        private int $seconds,
        private string $key,
        private ?Repository $cache = null
    ) {
        $this->clock = fn (): float => microtime(true);
    }

    public static function forEndpoint(string $endpoint, int $seconds, ?Repository $cache = null): self
    {
        return new self($seconds, 'logvaerk:circuit:'.sha1($endpoint), $cache);
    }

    /**
     * @param  callable(): float  $clock
     */
    public function setClock(callable $clock): void
    {
        $this->clock = $clock;
    }

    public function isOpen(): bool
    {
        if ($this->seconds <= 0) {
            return false;
        }

        $now = ($this->clock)();

        if ($this->openUntil > $now) {
            return true;
        }

        if ($this->cache === null) {
            return false;
        }

        try {
            $sharedUntil = (float) $this->cache->get($this->key, 0);
        } catch (Throwable $e) {
            return false;
        }

        if ($sharedUntil > $now) {
            $this->openUntil = $sharedUntil;

            return true;
        }

        return false;
    }

    public function trip(): void
    {
        if ($this->seconds <= 0) {
            return;
        }

        $this->openUntil = ($this->clock)() + $this->seconds;

        try {
            $this->cache?->put($this->key, $this->openUntil, $this->seconds);
        } catch (Throwable $e) {
            // The in-memory state still protects this process.
        }
    }
}
