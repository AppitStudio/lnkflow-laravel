<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use LnkFlow\Laravel\Data\IdentityChange;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Data\Visitor;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Testing\FakeTransport;

it('exposes the complete typed endpoint surface with correct scopes and paths', function (): void {
    $fake = new FakeTransport;
    $client = new Client($fake);
    $visitor = (string) Str::uuid();
    $click = (string) Str::uuid();

    $client->identity()->me();
    $client->websites()->list();
    $client->websites()->get(2);
    $client->websites()->create(['name' => 'Docs']);
    $client->websites()->update(2, ['name' => 'Documentation']);
    $client->domains()->list(usable: true);
    $client->influencers()->list();
    $client->influencers()->get(3);
    $client->influencers()->create(['name' => 'Partner']);
    $client->influencers()->update(3, ['name' => 'Updated partner']);
    $client->stats()->summary();
    $client->stats()->breakdown();
    $client->stats()->compare();
    $client->stats()->influencers();
    $client->stats()->websites();
    $client->stats()->conversions();
    $client->stats()->campaign(4);
    $client->stats()->link(5);
    $client->journeys()->capture(new Touchpoint(
        $visitor,
        $click,
        ['storage' => 'granted'],
        websiteId: 10,
    ));
    $client->journeys()->identify(new IdentityChange($visitor, 'customer_42', 10));
    $client->journeys()->unidentify(new Visitor($visitor, 10));
    $client->journeys()->revoke(new Visitor($visitor, 10));
    $client->conversions()->lead(new Lead('customer_42', 'signup'));
    $client->conversions()->sale(new Sale('invoice_42', 2500, 'USD', 'customer_42'));
    $client->conversions()->refund(new Refund('invoice_42', 'refund_1', 500, 'USD'));
    $client->conversions()->events();
    $client->conversions()->journey(9);

    $paths = array_column($fake->requests(), 'path');

    expect($paths)->toContain(
        'me',
        'websites',
        'websites/2',
        'domains',
        'influencers',
        'stats/summary',
        'stats/conversions',
        'campaigns/4/stats',
        'links/5/stats',
        'journeys/touchpoints',
        'journeys/identify',
        'journeys/unidentify',
        'journeys/revoke',
        'track/lead',
        'track/sale',
        'track/refund',
        'track/events',
        'track/events/9/journey',
    );

    $conversionRequests = array_values(array_filter(
        $fake->requests(),
        fn (array $request): bool => str_starts_with((string) $request['path'], 'track/'),
    ));
    expect(array_unique(array_column($conversionRequests, 'purpose')))
        ->toBe(['conversions']);
});

it('validates monetary DTOs and normalizes currencies', function (): void {
    expect((new Sale('invoice_1', 0, 'EUR'))->toArray()['currency'])->toBe('eur')
        ->and((new Refund('invoice_1', 'refund_1', 1, 'USD'))->toArray()['currency'])->toBe('usd')
        ->and(fn () => new Sale('invoice_1', -1, 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Refund('invoice_1', 'refund_1', 0, 'USD'))->toThrow(InvalidArgumentException::class);
});
