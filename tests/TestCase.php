<?php

namespace Ephort\Logvaerk\Tests;

use Ephort\Logvaerk\LogvaerkServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends Orchestra
{
    /** @var array<int, array{request: RequestInterface}> */
    protected array $sent = [];

    protected MockHandler $mock;

    protected function getPackageProviders($app): array
    {
        return [LogvaerkServiceProvider::class];
    }

    protected function fakeClient(int $responses = 50): Client
    {
        $this->sent = [];
        $this->mock = new MockHandler(array_fill(0, $responses, new Response(202, [], '{"accepted":1}')));

        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->sent));

        return new Client(['handler' => $stack]);
    }

    /**
     * Decoded JSON bodies of every request sent so far.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function sentBatches(): array
    {
        return array_map(
            fn (array $entry) => json_decode((string) $entry['request']->getBody(), true),
            $this->sent
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sentEvents(): array
    {
        return array_merge([], ...$this->sentBatches());
    }
}
