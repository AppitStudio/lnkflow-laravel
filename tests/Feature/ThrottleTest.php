<?php

declare(strict_types=1);

use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\Visitor;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

/*
 * The client-side throttle mirrors the API's named rate limiters, which are per
 * endpoint group: creates are governed by a far tighter per-user budget than
 * reads, and conversion and journey writes have their own much larger per-team
 * budgets. It always fails open — the server's own 429 plus Retry-After stays
 * the authority.
 */

beforeEach(function (): void {
    config()->set('lnkflow.connections.default.throttle', [
        'enabled' => true,
        'max_wait_milliseconds' => 0,
        'store' => 'array',
        'budgets' => [
            'default' => 60,
            'link_creation' => 20,
            'conversions' => 600,
            'journeys' => 600,
        ],
    ]);
    Cache::store('array')->clear();
});

/** Records the throttle buckets the SDK touches, through the cache's own events. */
function throttleKeys(): ArrayObject
{
    $keys = new ArrayObject;

    Event::listen(KeyWritten::class, function (KeyWritten $event) use ($keys): void {
        if (str_starts_with((string) $event->key, 'lnkflow:throttle:')) {
            $keys[] = (string) $event->key;
        }
    });

    return $keys;
}

/** @return list<string> */
function budgetsIn(ArrayObject $keys): array
{
    // lnkflow:throttle:<token hash>:<budget>:<window>
    return array_values(array_unique(array_map(
        fn (string $key): string => explode(':', $key)[3] ?? '',
        $keys->getArrayCopy(),
    )));
}

it('counts a campaign create against the tight link-creation budget', function (): void {
    Http::fake(['*' => Fixture::response('campaigns-store/201')]);
    $keys = throttleKeys();

    app(Client::class)->campaigns()->create(new CreateCampaign('Summer Launch'), 'campaign:summer');

    expect(budgetsIn($keys))->toBe(['link_creation']);
});

it('counts a link create against the tight link-creation budget', function (): void {
    Http::fake(['*' => Fixture::response('campaign-links-store/201')]);
    $keys = throttleKeys();

    app(Client::class)->links()->create(1, new CreateLink('https://storefront.example/spring'), 'link:1');

    expect(budgetsIn($keys))->toBe(['link_creation']);
});

it('does not count a read of the same resource against the create budget', function (): void {
    Http::fake(['*' => Fixture::response('campaigns-index/200')]);
    $keys = throttleKeys();

    app(Client::class)->campaigns()->list();

    expect(budgetsIn($keys))->toBe(['default']);
});

it('does not count a side-effect-free preview against the create budget', function (): void {
    // `POST /links/preview` writes nothing and is not governed by the server's
    // link-creation limiter, so charging it there would starve real creates.
    Http::fake(['*' => Fixture::response('links-preview/200')]);
    $keys = throttleKeys();

    app(Client::class)->links()->preview(new CreateLink('https://storefront.example/preview'));

    expect(budgetsIn($keys))->toBe(['default']);
});

it('counts a conversion write against the conversions budget', function (): void {
    Http::fake(['*' => Fixture::response('track-sale/201')]);
    $keys = throttleKeys();

    app(Client::class)->conversions()->sale(new Sale('invoice_42', 2500, 'usd'));

    expect(budgetsIn($keys))->toBe(['conversions']);
});

it('counts a journey write against the journeys budget', function (): void {
    Http::fake(['*' => Http::response(['data' => ['visitor_id' => 'visitor-1']])]);
    $keys = throttleKeys();

    app(Client::class)->journeys()->revoke(new Visitor('visitor-1'));

    expect(budgetsIn($keys))->toBe(['journeys']);
});

it('keeps budgets in separate buckets so one cannot starve another', function (): void {
    Http::fake(['*' => Http::response(['data' => ['id' => 1]], 201)]);
    $keys = throttleKeys();
    $client = app(Client::class);

    $client->campaigns()->create(new CreateCampaign('One'), 'k1');
    $client->campaigns()->list();
    $client->conversions()->sale(new Sale('invoice_1', 1, 'usd'));

    expect(budgetsIn($keys))->toHaveCount(3)
        ->and(budgetsIn($keys))->toContain('link_creation', 'default', 'conversions');
});

it('separates buckets per token so two connections never share one', function (): void {
    config()->set('lnkflow.connections.other', [
        ...config('lnkflow.connections.default'),
        'link_token' => 'other-link-token',
    ]);
    Http::fake(['*' => Fixture::response('campaigns-store/201')]);
    $keys = throttleKeys();

    app(Client::class)->campaigns()->create(new CreateCampaign('One'), 'k1');
    app(Client::class)->connection('other')->campaigns()->create(new CreateCampaign('Two'), 'k2');

    expect($keys)->toHaveCount(2)
        ->and(budgetsIn($keys))->toBe(['link_creation']);
});

it('fails open instead of blocking once a budget is spent', function (): void {
    config()->set('lnkflow.connections.default.throttle.budgets.link_creation', 1);
    config()->set('lnkflow.connections.default.throttle.max_wait_milliseconds', 20);
    Http::fake(['*' => Fixture::response('campaigns-store/201')]);
    $client = app(Client::class);
    $startedAt = microtime(true);

    // The second and third creates are over budget for the rest of the minute.
    // The SDK waits at most `max_wait_milliseconds` and then lets the request
    // go out, so the server's 429 — not a client-side guess — decides.
    $client->campaigns()->create(new CreateCampaign('One'), 'k1');
    $client->campaigns()->create(new CreateCampaign('Two'), 'k2');
    $client->campaigns()->create(new CreateCampaign('Three'), 'k3');

    expect(microtime(true) - $startedAt)->toBeLessThan(1.0);
    Http::assertSentCount(3);
});

it('does nothing at all when the throttle is disabled', function (): void {
    config()->set('lnkflow.connections.default.throttle.enabled', false);
    Http::fake(['*' => Fixture::response('campaigns-store/201')]);
    $keys = throttleKeys();

    app(Client::class)->campaigns()->create(new CreateCampaign('Summer Launch'), 'campaign:summer');

    expect($keys)->toHaveCount(0);
    Http::assertSentCount(1);
});

it('fails open when the configured cache store does not exist', function (): void {
    config()->set('lnkflow.connections.default.throttle.store', 'nonexistent');
    Http::fake(['*' => Fixture::response('campaigns-store/201')]);

    expect(app(Client::class)->campaigns()->create(new CreateCampaign('Summer Launch'), 'k')->id)->toBe(3);
    Http::assertSentCount(1);
});

/**
 * The shipped budgets exist to mirror the server's named limiters, so a wrong
 * number here is worse than no number: it throttles a caller for a limit the
 * API does not have, and that surfaces as unexplained slowness rather than as
 * a 429 anyone can diagnose.
 *
 * `default` was null until 2026-07 because the server's `throttle:api` limiter
 * was defined and wired to no route. It is wired now.
 */
it('ships budgets that mirror the server limiters', function (): void {
    $config = require __DIR__.'/../../config/lnkflow.php';

    expect($config['connections']['default']['throttle']['budgets'])->toBe([
        'default' => 60,        // throttle:api, 60/min per token
        'link_creation' => 20,  // throttle:link-creation, 20/min per user
        'conversions' => 600,   // throttle:conversion-tracking, 600/min per team
        'journeys' => 600,      // throttle:journey-capture, 600/min per team
    ]);
});
