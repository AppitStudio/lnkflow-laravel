<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\Campaign;
use LnkFlow\Laravel\Data\Commission;
use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Data\ConversionEvent;
use LnkFlow\Laravel\Data\ConversionStats;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\Domain;
use LnkFlow\Laravel\Data\Identity;
use LnkFlow\Laravel\Data\Influencer;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\Link;
use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Resource;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\SearchMatch;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Data\UpdateLink;
use LnkFlow\Laravel\Data\Website;
use LnkFlow\Laravel\Data\Workspace;
use LnkFlow\Laravel\Exceptions\AuthenticationException;
use LnkFlow\Laravel\Exceptions\AuthorizationException;
use LnkFlow\Laravel\Exceptions\ConflictException;
use LnkFlow\Laravel\Exceptions\ErrorCode;
use LnkFlow\Laravel\Exceptions\LnkFlowException;
use LnkFlow\Laravel\Exceptions\NotFoundException;
use LnkFlow\Laravel\Exceptions\RateLimitException;
use LnkFlow\Laravel\Exceptions\ServerException;
use LnkFlow\Laravel\Exceptions\ValidationException;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

/**
 * Every test in this file drives `ApiTransport` and `ResponseMapper` for real
 * over `Http::fake()`, using the bytes of the shared response corpus. That is
 * the point: the FakeTransport-based surface test never touches either class,
 * so nothing there would notice the API changing shape underneath the SDK.
 */
function contractFake(string $fixture): void
{
    Http::fake([Fixture::url($fixture).'*' => Fixture::response($fixture)]);
}

it('parses a modelled success response from the shared contract corpus', function (string $fixture, Closure $call, Closure $assert): void {
    contractFake($fixture);

    $assert($call(app(Client::class)));
})->with([
    'GET /me' => [
        'me/200',
        fn (Client $client) => $client->identity()->me(),
        function (Identity $identity): void {
            expect($identity->id)->toBe(1)
                ->and($identity->can('write'))->toBeTrue()
                ->and($identity->can('conversions'))->toBeTrue()
                ->and($identity->can('nonexistent'))->toBeFalse()
                ->and($identity->raw['link_domain'])->toBe('mylnk.click');
        },
    ],
    'GET /search' => [
        'search/200',
        fn (Client $client) => $client->search()->query('nova'),
        function (array $matches): void {
            expect($matches)->toHaveCount(3)
                ->and($matches[0])->toBeInstanceOf(SearchMatch::class)
                ->and($matches[0]->is('influencer'))->toBeTrue()
                ->and($matches[0]->id)->toBe(1)
                ->and($matches[0]->label)->toBe('Nova Rivers')
                ->and($matches[0]->metadata['primary_handle'])->toBe('novarivers');
        },
    ],
    'GET /browser-extension/bootstrap' => [
        'browser-extension-bootstrap/200',
        fn (Client $client) => $client->workspace()->bootstrap(),
        function (Workspace $workspace): void {
            expect($workspace->websites)->toHaveCount(1)
                ->and($workspace->websites[0]->name)->toBe('Storefront')
                ->and($workspace->domains[0]->usable)->toBeTrue()
                ->and($workspace->teams)->toHaveCount(1);
        },
    ],
    'GET /campaigns' => [
        'campaigns-index/200',
        fn (Client $client) => $client->campaigns()->list(),
        function (Page $page): void {
            expect($page)->toHaveCount(1)
                ->and($page->data[0])->toBeInstanceOf(Campaign::class)
                ->and($page->data[0]->name)->toBe('Spring Launch')
                ->and($page->total())->toBe(1)
                ->and($page->hasMorePages())->toBeFalse();
        },
    ],
    'GET /campaigns/{id}' => [
        'campaigns-show/200',
        fn (Client $client) => $client->campaigns()->get(1),
        function (Campaign $campaign): void {
            expect($campaign->id)->toBe(1)
                ->and($campaign->websiteId)->toBe(1)
                ->and($campaign->influencerName)->toBe('Nova Rivers')
                ->and($campaign->primaryLink?->shortUrl)->toBe('https://lnk.storefront.example/nova-youtube')
                ->and($campaign->utmParameters['utm_source'])->toBe('youtube');
        },
    ],
    'POST /campaigns' => [
        'campaigns-store/201',
        fn (Client $client) => $client->campaigns()->create(new CreateCampaign('Summer Launch'), 'campaign:summer'),
        function (Campaign $campaign): void {
            expect($campaign->id)->toBe(3)
                ->and($campaign->slug)->toBe('summer-launch')
                ->and($campaign->active)->toBeTrue()
                ->and($campaign->replayed())->toBeFalse()
                ->and($campaign->requestId())->toBe('00000000-0000-4000-8000-000000000000');
        },
    ],
    'GET /campaigns/{id}/links' => [
        'campaign-links-index/200',
        fn (Client $client) => $client->links()->forCampaign(1),
        function (Page $page): void {
            expect($page->data[0])->toBeInstanceOf(Link::class)
                ->and($page->data[0]->campaignId)->toBe(1)
                ->and($page->currentPage())->toBe(1);
        },
    ],
    'POST /campaigns/{id}/links' => [
        'campaign-links-store/201',
        fn (Client $client) => $client->links()->create(1, new CreateLink('https://storefront.example/spring'), 'link:1'),
        function (Link $link): void {
            expect($link->id)->toBe(3)
                ->and($link->slug)->toBe('nova-newsletter')
                ->and($link->shortUrl)->toBe('https://lnk.storefront.example/nova-newsletter')
                ->and($link->edgeStatus)->toBe('publishing')
                ->and($link->published())->toBeFalse();
        },
    ],
    'GET /links/{id}' => [
        'links-show/200',
        fn (Client $client) => $client->links()->get(1),
        function (Link $link): void {
            expect($link->id)->toBe(1)
                ->and($link->customDomain)->toBe('lnk.storefront.example')
                ->and($link->campaignName)->toBe('Spring Launch');
        },
    ],
    'PATCH /links/{id}' => [
        'links-update/200',
        fn (Client $client) => $client->links()->update(1, new UpdateLink(['is_active' => false])),
        function (Link $link): void {
            expect($link->active)->toBeFalse()
                ->and($link->edgeStatus)->toBe('inactive')
                ->and($link->conversionTrackingEnabled)->toBeTrue()
                ->and($link->autoPromoCode)->toBe('NOVA10');
        },
    ],
    'POST /links/preview' => [
        'links-preview/200',
        fn (Client $client) => $client->links()->preview(new CreateLink('https://storefront.example/preview')),
        function (Resource $preview): void {
            expect($preview->raw['slug'])->toBe('nova-preview')
                ->and($preview->raw['selected_domain']['type'])->toBe('custom');
        },
    ],
    'GET /websites' => [
        'websites-index/200',
        fn (Client $client) => $client->websites()->list(),
        function (Page $page): void {
            expect($page->data[0])->toBeInstanceOf(Website::class)
                ->and($page->data[0]->name)->toBe('Storefront');
        },
    ],
    'GET /websites/{id}' => [
        'websites-show/200',
        fn (Client $client) => $client->websites()->get(1),
        function (Website $website): void {
            expect($website->id)->toBe(1)
                ->and($website->domain)->toBe('storefront.example')
                ->and($website->defaultCustomDomain)->toBe('lnk.storefront.example');
        },
    ],
    'POST /websites' => [
        'websites-store/201',
        fn (Client $client) => $client->websites()->create(new CreateWebsite('Docs Site', 'docs.example')),
        function (Website $website): void {
            expect($website->id)->toBe(3)
                ->and($website->active)->toBeTrue();
        },
    ],
    'GET /influencers' => [
        'influencers-index/200',
        fn (Client $client) => $client->influencers()->list(),
        function (Page $page): void {
            expect($page->data[0])->toBeInstanceOf(Influencer::class)
                ->and($page->data[0]->primaryHandle)->toBe('novarivers');
        },
    ],
    'GET /influencers/{id}' => [
        'influencers-show/200',
        fn (Client $client) => $client->influencers()->get(1),
        function (Influencer $influencer): void {
            expect($influencer->name)->toBe('Nova Rivers')
                ->and($influencer->socialLinks['youtube'])->toBe('https://youtube.example/novarivers')
                ->and($influencer->metadata['niche'])->toBe('Creator economy')
                ->and($influencer->salesCount)->toBe(0);
        },
    ],
    'POST /influencers' => [
        'influencers-store/201',
        fn (Client $client) => $client->influencers()->create(new CreateInfluencer('Rowan Vale')),
        function (Influencer $influencer): void {
            expect($influencer->id)->toBe(3)
                ->and($influencer->primaryPlatform)->toBe('tiktok')
                // Absent aggregates stay null rather than being invented as 0.
                ->and($influencer->salesCount)->toBeNull();
        },
    ],
    'GET /influencers/{id}/commissions' => [
        'influencer-commissions/200',
        fn (Client $client) => $client->influencers()->commissions(1),
        function (Page $page): void {
            expect($page)->toHaveCount(2)
                ->and($page->data[0])->toBeInstanceOf(Commission::class)
                // A refund row is a negative ledger entry, never a payout.
                ->and($page->data[0]->commissionAmountCents)->toBe(-750)
                ->and($page->data[0]->relatedCommissionId)->toBe(1)
                ->and($page->total())->toBe(2);
        },
    ],
    'GET /domains' => [
        'domains-index/200',
        fn (Client $client) => $client->domains()->list(usable: true),
        function (array $domains): void {
            expect($domains)->toHaveCount(1)
                ->and($domains[0])->toBeInstanceOf(Domain::class)
                ->and($domains[0]->usable)->toBeTrue()
                ->and($domains[0]->sslStatus)->toBe('issued');
        },
    ],
    'GET /stats/summary' => [
        'stats-summary/200',
        fn (Client $client) => $client->stats()->summary(),
        function (Resource $stats): void {
            expect($stats->raw['total_clicks'])->toBe(1)
                ->and($stats->raw['series'])->toHaveCount(15);
        },
    ],
    'GET /stats/conversions' => [
        'stats-conversions/200',
        fn (Client $client) => $client->stats()->conversions(),
        function (ConversionStats $stats): void {
            expect($stats->hasConversionData)->toBeTrue()
                ->and($stats->clicks)->toBe(1)
                ->and($stats->leads)->toBe(1)
                ->and($stats->sales)->toBe(1)
                ->and($stats->revenueCents)->toBe(0)
                ->and($stats->linkAttributed)->toBe(2)
                ->and($stats->codeAttributed)->toBe(0)
                ->and($stats->clickToSaleRate)->toBe(100.0)
                ->and($stats->meta['timezone'])->toBe('UTC');
        },
    ],
    'POST /track/lead' => [
        'track-lead/201',
        fn (Client $client) => $client->conversions()->lead(new Lead('cus_contract_fixture', 'signup')),
        function (ConversionEvent $event): void {
            expect($event->id)->toBe(1)
                ->and($event->type)->toBe('lead')
                ->and($event->attributionSource)->toBe('link')
                ->and($event->influencerId)->toBe(1)
                ->and($event->test)->toBeFalse();
        },
    ],
    'POST /track/sale' => [
        'track-sale/201',
        fn (Client $client) => $client->conversions()->sale(new Sale('invoice_1', 4999, 'usd')),
        function (ConversionEvent $event): void {
            expect($event->type)->toBe('sale')
                ->and($event->amountCents)->toBe(4999)
                ->and($event->currency)->toBe('usd')
                ->and($event->fraudFlags)->toBe([]);
        },
    ],
    'POST /track/refund' => [
        'track-refund/201',
        fn (Client $client) => $client->conversions()->refund(new Refund('invoice_1', 'refund_1')),
        function (ConversionEvent $event): void {
            expect($event->type)->toBe('refund')
                ->and($event->amountCents)->toBe(4999);
        },
    ],
    'GET /track/events' => [
        'track-events/200',
        fn (Client $client) => $client->conversions()->events(),
        function (array $events): void {
            expect($events)->toHaveCount(3)
                ->and($events[0])->toBeInstanceOf(ConversionEvent::class)
                ->and(array_map(fn (ConversionEvent $event): string => $event->type, $events))
                ->toBe(['refund', 'sale', 'lead']);
        },
    ],
    'POST /journeys/touchpoints' => [
        'journeys-touchpoints/201',
        fn (Client $client) => $client->journeys()->capture(new Touchpoint(
            '20000001-0000-4000-8000-000000000001',
            '30000001-0000-4000-8000-000000000001',
            new Consent(ConsentState::Granted),
        )),
        function (Resource $resource): void {
            expect($resource->raw['resolution_status'])->toBe('resolved')
                ->and($resource->raw['duplicate'])->toBeFalse();
        },
    ],
]);

it('maps the error envelope of every status the SDK understands', function (string $fixture, string $exception, Closure $call): void {
    contractFake($fixture);

    try {
        $call(app(Client::class));
        test()->fail("Expected {$exception} for fixture [{$fixture}].");
    } catch (LnkFlowException $thrown) {
        // The code is the branchable part of the contract, so it is asserted
        // from the recorded bytes rather than hard-coded here: a server that
        // stops sending one fails this instead of quietly degrading every
        // consumer to message-matching.
        expect($thrown)->toBeInstanceOf($exception)
            ->and($thrown->status)->toBe(Fixture::status($fixture))
            ->and($thrown->getMessage())->toBe(Fixture::body($fixture)['message'])
            ->and($thrown->errorCode)->toBe(Fixture::body($fixture)['code'])
            ->and($thrown->code())->toBeInstanceOf(ErrorCode::class)
            ->and($thrown->requestId)->toBe('00000000-0000-4000-8000-000000000000');
    }
})->with([
    '401 on a read' => ['me/401', AuthenticationException::class, fn (Client $c) => $c->identity()->me()],
    '401 on a list' => ['campaigns-index/401', AuthenticationException::class, fn (Client $c) => $c->campaigns()->list()],
    '401 on a conversion write' => ['track-sale/401', AuthenticationException::class, fn (Client $c) => $c->conversions()->sale(new Sale('i', 1, 'usd'))],
    '403 cross-team read' => ['me/403', AuthorizationException::class, fn (Client $c) => $c->identity()->me()],
    '403 read-only token on a write' => ['websites-store/403', AuthorizationException::class, fn (Client $c) => $c->websites()->create(new CreateWebsite('Docs'))],
    '403 on a link create' => ['campaign-links-store/403', AuthorizationException::class, fn (Client $c) => $c->links()->create(1, new CreateLink('https://example.test'), 'k')],
    '403 on a conversion write' => ['track-lead/403', AuthorizationException::class, fn (Client $c) => $c->conversions()->lead(new Lead('cus'))],
    '404 missing campaign' => ['campaigns-show/404', NotFoundException::class, fn (Client $c) => $c->campaigns()->get(2)],
    '404 missing link' => ['links-show/404', NotFoundException::class, fn (Client $c) => $c->links()->get(2)],
    '404 missing influencer' => ['influencers-show/404', NotFoundException::class, fn (Client $c) => $c->influencers()->get(2)],
    '409 idempotency in progress' => ['campaigns-store/409', ConflictException::class, fn (Client $c) => $c->campaigns()->create(new CreateCampaign('X'), 'k')],
    '422 validation' => ['campaigns-store/422', ValidationException::class, fn (Client $c) => $c->campaigns()->create(new CreateCampaign('X'), 'k')],
    '422 idempotency key reused' => ['campaigns-store/422-idempotency-key-reused', ValidationException::class, fn (Client $c) => $c->campaigns()->create(new CreateCampaign('X'), 'k')],
    '422 missing query parameter' => ['search/422', ValidationException::class, fn (Client $c) => $c->search()->query('')],
    '422 refund without a sale' => ['track-refund/422', ValidationException::class, fn (Client $c) => $c->conversions()->refund(new Refund('i', 'r'))],
    '429 rate limit' => ['campaigns-store/429', RateLimitException::class, fn (Client $c) => $c->campaigns()->create(new CreateCampaign('X'), 'k')],
]);

it('exposes validation errors field by field', function (): void {
    contractFake('campaigns-store/422');

    try {
        app(Client::class)->campaigns()->create(new CreateCampaign('Broken'), 'campaign:broken');
        test()->fail('Expected a validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors)->toBe([
            'slug' => ['The slug field format is invalid.'],
            'destination_url' => ['The destination url field must be a valid URL.'],
        ])->and($exception->code())->toBe(ErrorCode::ValidationFailed);
    }
});

/**
 * Two 403s that need different fixes: one wants a new token, the other wants a
 * role change. They were indistinguishable while `code` was absent, which is
 * exactly why an integration had to match on English prose.
 */
it('tells a read-only token apart from an unreachable team', function (): void {
    contractFake('websites-store/403');

    try {
        app(Client::class)->websites()->create(new CreateWebsite('Docs'));
        test()->fail('Expected an authorization exception.');
    } catch (AuthorizationException $exception) {
        expect($exception->code())->toBe(ErrorCode::TokenMissingAbility)
            ->and($exception->is(ErrorCode::TokenMissingAbility))->toBeTrue()
            ->and($exception->is(ErrorCode::TeamInaccessible))->toBeFalse()
            ->and($exception->code()?->requiresUserAction())->toBeTrue();
    }

    contractFake('me/403');

    try {
        app(Client::class)->identity()->me();
        test()->fail('Expected an authorization exception.');
    } catch (AuthorizationException $exception) {
        expect($exception->code())->toBe(ErrorCode::TeamInaccessible);
    }
});

/**
 * The server's code set grows additively. A release of this package that
 * predates a new code must keep working: the raw string stays reachable and
 * the exception type — derived from the status — still classifies it.
 */
it('tolerates an error code this release does not know', function (): void {
    Http::fake(['*' => Http::response(
        ['message' => 'Something new happened.', 'code' => 'SOME_FUTURE_CODE'],
        403,
        ['X-LnkFlow-Request-Id' => 'req_future'],
    )]);

    try {
        app(Client::class)->identity()->me();
        test()->fail('Expected an authorization exception.');
    } catch (AuthorizationException $exception) {
        expect($exception->errorCode)->toBe('SOME_FUTURE_CODE')
            ->and($exception->code())->toBeNull()
            ->and($exception->is(ErrorCode::Forbidden))->toBeFalse();
    }
});

/**
 * A cross-team resource has to be byte-identical to a missing one, or the 404
 * body becomes a way to enumerate other teams' ids. It also must not name the
 * model class, which it used to.
 */
it('cannot distinguish a foreign resource from a missing one', function (): void {
    $body = Fixture::body('campaigns-show/404');

    expect($body['message'])->toBe('Resource not found.')
        ->and($body['code'])->toBe(ErrorCode::NotFound->value)
        ->and(json_encode($body))->not->toContain('App\\Models\\');
});

it('surfaces the server error code on a rate limit alongside Retry-After', function (): void {
    contractFake('campaigns-store/429');

    try {
        app(Client::class)->campaigns()->create(new CreateCampaign('Busy'), 'campaign:busy');
        test()->fail('Expected a rate limit exception.');
    } catch (RateLimitException $exception) {
        expect($exception->retryAfter)->toBe(60)
            ->and($exception->status)->toBe(429);
    }
});

it('maps a 5xx to a server exception and keeps the request id', function (): void {
    // The shared corpus has no 5xx sample — a synthetic server failure in the
    // same envelope shape stands in for one.
    Http::fake(['*' => Http::response(
        ['message' => 'Server Error', 'code' => 'INTERNAL'],
        503,
        ['X-LnkFlow-Request-Id' => 'req_5xx'],
    )]);

    try {
        app(Client::class)->identity()->me();
        test()->fail('Expected a server exception.');
    } catch (ServerException $exception) {
        expect($exception->status)->toBe(503)
            ->and($exception->errorCode)->toBe('INTERNAL')
            ->and($exception->requestId)->toBe('req_5xx');
    }
});

it('maps an unmodelled 4xx to the base exception rather than guessing', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Payload too large'], 413)]);

    expect(fn () => app(Client::class)->identity()->me())
        ->toThrow(LnkFlowException::class, 'Payload too large');
});

it('returns the raw body for the CSV commission export', function (): void {
    // A CSV response, which the JSON corpus cannot express.
    $csv = "id,status,commission_amount_cents\n1,refunded,750\n";
    Http::fake(['*' => Http::response($csv, 200, ['Content-Type' => 'text/csv'])]);

    expect(app(Client::class)->influencers()->commissionsCsv(1))->toBe($csv);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'export=csv'));
});
