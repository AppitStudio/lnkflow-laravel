<?php

declare(strict_types=1);

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Facades\LnkFlow;
use LnkFlow\Laravel\Services\Client;

/*
 * The transport guard the integration release gate requires. `TestCase::setUp`
 * calls `Http::preventStrayRequests()` for every test in the suite, so a code
 * path that starts talking to a real host fails loudly instead of quietly
 * reaching the internet from CI.
 */

it('refuses any request this suite did not explicitly fake', function (): void {
    expect(fn () => app(Client::class)->identity()->me())
        ->toThrow(StrayRequestException::class);
});

it('refuses a stray request even when another endpoint is faked', function (): void {
    Http::fake(['app.lnkflow.test/api/v1/me' => Http::response(['data' => ['id' => 1]])]);

    app(Client::class)->identity()->me();

    expect(fn () => app(Client::class)->campaigns()->list())
        ->toThrow(StrayRequestException::class);
});

it('never reaches the network at all through the host-facing fake', function (): void {
    LnkFlow::fake();

    LnkFlow::client()->campaigns()->list();
    LnkFlow::trackSale(new Sale('invoice_1', 100, 'usd'));
    LnkFlow::trackEvent(new NamedEvent('trial_started', 'customer_7'));

    LnkFlow::assertSaleTracked(fn (array $request): bool => $request['json']['invoice_id'] === 'invoice_1');
    LnkFlow::assertEventTracked(fn (array $request): bool => $request['json']['event_name'] === 'trial_started');
    Http::assertNothingSent();
});

it('lets a host prove its code sent nothing to LnkFlow', function (): void {
    LnkFlow::fake();

    LnkFlow::assertNothingSent();
    Http::assertNothingSent();
});
