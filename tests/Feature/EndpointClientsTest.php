<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\IdentityChange;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\SocialPlatform;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Data\UpdateCampaign;
use LnkFlow\Laravel\Data\UpdateInfluencer;
use LnkFlow\Laravel\Data\UpdateLink;
use LnkFlow\Laravel\Data\UpdateWebsite;
use LnkFlow\Laravel\Data\Visitor;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Testing\FakeTransport;

it('exposes the complete typed endpoint surface with correct scopes and paths', function (): void {
    $fake = new FakeTransport;
    $client = new Client($fake);
    $visitor = (string) Str::uuid();
    $click = (string) Str::uuid();
    $consent = new Consent(ConsentState::Granted, ConsentState::Granted, ConsentState::Denied);

    $client->identity()->me();
    $client->search()->query('nova');
    $client->workspace()->bootstrap();
    $client->campaigns()->list();
    $client->campaigns()->get(4);
    $client->campaigns()->create(new CreateCampaign('Release'), 'campaign:release');
    $client->campaigns()->update(4, new UpdateCampaign(['name' => 'Renamed']));
    $client->links()->list();
    $client->links()->forCampaign(4);
    $client->links()->get(5);
    $client->links()->preview(new CreateLink('https://example.com/docs'));
    $client->links()->create(4, new CreateLink('https://example.com/docs', slug: 'docs'), 'link:docs');
    $client->links()->update(5, new UpdateLink(['name' => 'Docs']));
    $client->links()->deactivate(5);
    $client->websites()->list();
    $client->websites()->get(2);
    $client->websites()->create(new CreateWebsite('Docs'));
    $client->websites()->update(2, new UpdateWebsite(['name' => 'Documentation']));
    $client->domains()->list(usable: true);
    $client->influencers()->list();
    $client->influencers()->get(3);
    $client->influencers()->create(new CreateInfluencer('Partner', primaryPlatform: SocialPlatform::TikTok));
    $client->influencers()->update(3, new UpdateInfluencer(['name' => 'Updated partner']));
    $client->influencers()->commissions(3);
    $client->influencers()->commissionsCsv(3);
    $client->stats()->summary();
    $client->stats()->breakdown();
    $client->stats()->compare();
    $client->stats()->influencers();
    $client->stats()->websites();
    $client->stats()->conversions();
    $client->stats()->campaign(4);
    $client->stats()->link(5);
    $client->journeys()->capture(new Touchpoint($visitor, $click, $consent, websiteId: 10));
    $client->journeys()->identify(new IdentityChange($visitor, 'customer_42', 10));
    $client->journeys()->unidentify(new Visitor($visitor, 10));
    $client->journeys()->revoke(new Visitor($visitor, 10));
    $client->conversions()->event(new NamedEvent('trial_started', 'customer_42'));
    $client->conversions()->lead(new Lead('customer_42', 'signup'));
    $client->conversions()->sale(new Sale('invoice_42', 2500, 'USD', 'customer_42'));
    $client->conversions()->refund(new Refund('invoice_42', 'refund_1', 500));
    $client->conversions()->events();
    $client->conversions()->journey(9);

    $paths = array_column($fake->requests(), 'path');

    expect($paths)->toContain(
        'me',
        'search',
        'browser-extension/bootstrap',
        'campaigns',
        'campaigns/4',
        'links',
        'campaigns/4/links',
        'links/5',
        'links/preview',
        'websites',
        'websites/2',
        'domains',
        'influencers',
        'influencers/3',
        'influencers/3/commissions',
        'stats/summary',
        'stats/breakdown',
        'stats/compare',
        'stats/influencers',
        'stats/websites',
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

    // Analytics reads live on the stats client, which uses the general token,
    // even though their paths sit under a resource.
    $purposeFor = fn (string $prefix): array => array_values(array_unique(array_column(array_filter(
        $fake->requests(),
        fn (array $request): bool => str_starts_with((string) $request['path'], $prefix)
            && ! str_ends_with((string) $request['path'], '/stats'),
    ), 'purpose')));

    expect($purposeFor('track/'))->toBe(['conversions'])
        ->and($purposeFor('journeys/'))->toBe(['journeys'])
        ->and($purposeFor('campaigns'))->toBe(['links'])
        ->and($purposeFor('links'))->toBe(['links'])
        ->and($purposeFor('websites'))->toBe(['links'])
        ->and($purposeFor('influencers'))->toBe(['links'])
        ->and($purposeFor('domains'))->toBe(['links'])
        ->and($purposeFor('stats/'))->toBe(['api'])
        ->and($purposeFor('me'))->toBe(['api'])
        ->and($purposeFor('search'))->toBe(['api']);
});

it('validates monetary DTOs and normalizes currencies', function (): void {
    expect((new Sale('invoice_1', 0, 'EUR'))->toArray()['currency'])->toBe('eur')
        ->and(fn () => new Sale('invoice_1', -1, 'USD'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new Refund('invoice_1', 'refund_1', 0))->toThrow(InvalidArgumentException::class);
});
