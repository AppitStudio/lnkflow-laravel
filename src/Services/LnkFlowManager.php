<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Testing\FakeTransport;
use PHPUnit\Framework\Assert;

final class LnkFlowManager
{
    private ?FakeTransport $fake = null;

    public function __construct(
        private readonly Application $app,
        private Client $client,
    ) {}

    public function client(): Client
    {
        return $this->client;
    }

    public function connection(string $connection): Client
    {
        return $this->client->connection($connection);
    }

    public function forTeam(int|string|null $team): Client
    {
        return $this->client->forTeam($team);
    }

    public function fake(): FakeTransport
    {
        $this->fake = new FakeTransport;
        $this->client = new Client($this->fake);
        $this->app->instance(Client::class, $this->client);

        return $this->fake;
    }

    public function trackEvent(NamedEvent $event): void
    {
        if ($this->fake instanceof FakeTransport) {
            $this->client->conversions()->event($event);

            return;
        }

        $this->app->make(ConversionDispatcher::class)->event($event);
    }

    public function trackLead(Lead $lead): void
    {
        if ($this->fake instanceof FakeTransport) {
            $this->client->conversions()->lead($lead);

            return;
        }

        $this->app->make(ConversionDispatcher::class)->lead($lead);
    }

    public function trackSale(Sale $sale): void
    {
        if ($this->fake instanceof FakeTransport) {
            $this->client->conversions()->sale($sale);

            return;
        }

        $this->app->make(ConversionDispatcher::class)->sale($sale);
    }

    public function trackRefund(Refund $refund): void
    {
        if ($this->fake instanceof FakeTransport) {
            $this->client->conversions()->refund($refund);

            return;
        }

        $this->app->make(ConversionDispatcher::class)->refund($refund);
    }

    public function assertSent(string $method, string $path, ?Closure $callback = null): void
    {
        $matches = array_filter(
            $this->requests(),
            static fn (array $request): bool => $request['method'] === mb_strtoupper($method)
                && ($request['path'] === $path || fnmatch($path, (string) $request['path']))
                && ($callback === null || $callback($request) === true),
        );

        Assert::assertNotEmpty($matches, "Expected a LnkFlow request [{$method} {$path}] was not sent.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->requests(), 'Unexpected LnkFlow requests were sent.');
    }

    /** @return list<array<string, mixed>> */
    private function requests(): array
    {
        Assert::assertInstanceOf(FakeTransport::class, $this->fake, 'Call LnkFlow::fake() before assertions.');

        return $this->fake->requests();
    }
}
