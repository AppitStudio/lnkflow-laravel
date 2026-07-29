<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\SocialPlatform;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

/*
 * Additive server changes ship inside v1. A field this SDK version has never
 * heard of, or an enum value added after it was released, must never break
 * parsing — and must stay reachable so a host can use it before upgrading.
 */

it('keeps an unknown response field reachable through raw', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('links-show/200', [
        'quantum_status' => 'entangled',
        'nested_future' => ['deep' => ['value' => 7]],
    ]))]);

    $link = app(Client::class)->links()->get(1);

    expect($link->id)->toBe(1)
        ->and($link->raw['quantum_status'])->toBe('entangled')
        ->and($link->get('nested_future'))->toBe(['deep' => ['value' => 7]])
        ->and($link->get('absent', 'fallback'))->toBe('fallback');
});

it('keeps an unknown edge status usable instead of rejecting it', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('links-show/200', [
        'edge_status' => 'quarantined',
    ]))]);

    $link = app(Client::class)->links()->get(1);

    expect($link->edgeStatus)->toBe('quarantined')
        // Only the API's own success is authoritative; an unrecognised edge
        // state is simply "not published yet", never a failure.
        ->and($link->published())->toBeFalse();
});

it('keeps an unknown social platform usable instead of rejecting it', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('campaigns-show/200', [
        'social_platform' => 'bereal',
    ]))]);

    expect(app(Client::class)->campaigns()->get(1)->socialPlatform)->toBe('bereal');
});

it('accepts a platform string the SocialPlatform enum does not know', function (): void {
    Http::fake(['*' => Fixture::response('influencers-store/201')]);

    app(Client::class)->influencers()->create(new CreateInfluencer('Rowan', primaryPlatform: 'bereal'));

    Http::assertSent(fn ($request): bool => $request['primary_platform'] === 'bereal');
});

it('serializes the SocialPlatform enum to its wire value', function (): void {
    Http::fake(['*' => Fixture::response('influencers-store/201')]);

    app(Client::class)->influencers()->create(new CreateInfluencer('Rowan', primaryPlatform: SocialPlatform::TikTok));

    Http::assertSent(fn ($request): bool => $request['primary_platform'] === 'tiktok');
});

it('keeps an unknown attribution source usable instead of rejecting it', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('track-sale/201', [
        'attribution_source' => 'affiliate_network',
    ]))]);

    $event = app(Client::class)->conversions()->sale(new Sale('i', 1, 'usd'));

    expect($event->attributionSource)->toBe('affiliate_network')
        ->and($event->amountCents)->toBe(4999);
});

it('survives a response that drops fields this SDK version reads', function (): void {
    // The mirror image of an additive change: a field the SDK reads is absent.
    // Typed properties must degrade to sane defaults rather than fatal.
    Http::fake(['*' => Http::response(['data' => ['id' => 9]])]);

    $link = app(Client::class)->links()->get(9);

    expect($link->id)->toBe(9)
        ->and($link->slug)->toBe('')
        ->and($link->shortUrl)->toBe('')
        ->and($link->edgeStatus)->toBe('unknown')
        ->and($link->active)->toBeFalse()
        ->and($link->utmParameters)->toBe([])
        ->and($link->totalClicks)->toBe(0);
});

it('tolerates a null where the API used to send an object', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('campaigns-show/200', [
        'website' => null,
        'influencer' => null,
        'primary_link' => null,
        'links' => null,
        'utm_parameters' => null,
    ]))]);

    $campaign = app(Client::class)->campaigns()->get(1);

    expect($campaign->websiteId)->toBeNull()
        ->and($campaign->influencerId)->toBeNull()
        ->and($campaign->primaryLink)->toBeNull()
        ->and($campaign->links)->toBe([])
        ->and($campaign->utmParameters)->toBe([]);
});

it('reads integer ids that arrive as numeric strings', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('links-show/200', [
        'id' => '77',
        'total_clicks' => '1234',
    ]))]);

    $link = app(Client::class)->links()->get(77);

    expect($link->id)->toBe(77)
        ->and($link->totalClicks)->toBe(1234);
});

it('keeps unknown conversion-stats structure reachable without breaking the funnel', function (): void {
    $body = Fixture::body('stats-conversions/200');
    $body['data']['future_segment'] = ['organic' => 12];
    $body['meta']['experiment'] = 'v2';
    Http::fake(['*' => Http::response($body)]);

    $stats = app(Client::class)->stats()->conversions();

    expect($stats->hasConversionData)->toBeTrue()
        ->and($stats->sales)->toBe(1)
        ->and($stats->raw['future_segment'])->toBe(['organic' => 12])
        ->and($stats->meta['experiment'])->toBe('v2');
});

it('treats a missing conversion-data flag as no data rather than measured zero', function (): void {
    Http::fake(['*' => Http::response(['data' => ['funnel' => ['sales' => 0]]])]);

    $stats = app(Client::class)->stats()->conversions();

    expect($stats->hasConversionData)->toBeFalse()
        ->and($stats->sales)->toBe(0)
        ->and($stats->revenueCents)->toBe(0);
});
