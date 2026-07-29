<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\LinkDefinition;
use LnkFlow\Laravel\Data\UpdateCampaign;
use LnkFlow\Laravel\Models\CampaignMapping;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Services\ContentSynchronizer;
use LnkFlow\Laravel\Testing\FakeTransport;
use LnkFlow\Laravel\Tests\Fixture;

/*
 * One focused regression per defect the review found. Each of these was a real
 * data-corrupting bug, so each gets a test that fails the moment it returns.
 */

final class DriftingContent extends Model
{
    protected $table = 'test_contents';

    protected $guarded = [];
}

/** An adapter whose campaign name and link payload both track the model. */
final class DriftingContentAdapter implements LinkableContent
{
    public function lnkFlowSourceKey(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function lnkFlowLinks(Model $model): iterable
    {
        yield new LinkDefinition(
            placement: 'primary',
            campaignKey: 'drifting',
            campaignName: (string) $model->getAttribute('title'),
            destinationUrl: (string) $model->getAttribute('destination_url'),
            name: (string) $model->getAttribute('title'),
            slug: 'drifting',
        );
    }
}

it('D1: reads the campaign slug from campaign_slug and the link slug from slug', function (): void {
    // `slug` on a campaign resource is the *primary link's* slug, kept for the
    // legacy single-link payload. Reading it as the campaign slug produced
    // links that pointed at the wrong resource.
    Http::fake(['*' => Http::response(Fixture::bodyWithData('campaigns-show/200', [
        'campaign_slug' => 'spring-launch',
        'slug' => 'nova-youtube',
    ]))]);

    $campaign = app(Client::class)->campaigns()->get(1);

    expect($campaign->slug)->toBe('spring-launch')
        ->and($campaign->primaryLinkSlug)->toBe('nova-youtube');
});

it('D1: leaves the primary link slug null when the campaign has no links yet', function (): void {
    Http::fake(['*' => Http::response(Fixture::bodyWithData('campaigns-store/201', [
        'campaign_slug' => 'summer-launch',
        'slug' => null,
    ]))]);

    $campaign = app(Client::class)->campaigns()->create(
        new CreateCampaign('Summer Launch'),
        'campaign:summer',
    );

    expect($campaign->slug)->toBe('summer-launch')
        ->and($campaign->primaryLinkSlug)->toBeNull();
});

it('D2: refuses to update a campaign slug because it rewrites the live short URL', function (): void {
    expect(fn () => new UpdateCampaign(['slug' => 'renamed']))
        ->toThrow(
            InvalidArgumentException::class,
            'Updating a campaign slug also rewrites the primary link slug',
        );
});

it('D2: still allows the campaign fields the API really accepts', function (): void {
    expect((new UpdateCampaign(['name' => 'Renamed', 'website_id' => 3, 'is_active' => false]))->toArray())
        ->toBe(['name' => 'Renamed', 'website_id' => 3, 'is_active' => false])
        ->and(fn () => new UpdateCampaign(['campaign_slug' => 'renamed']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported campaign update field(s)');
});

it('D3: reconciles a drifted campaign with a PATCH that never touches is_active', function (): void {
    config()->set('lnkflow.content.models', [DriftingContent::class => DriftingContentAdapter::class]);
    config()->set('lnkflow.content.preview_before_write', false);
    $content = DriftingContent::query()->create([
        'title' => 'Original name',
        'destination_url' => 'https://example.com/docs',
    ]);
    $fake = new FakeTransport;
    $synchronizer = new ContentSynchronizer(new Client($fake));

    $synchronizer->sync(DriftingContent::class, (string) $content->id);
    $afterCreate = count($fake->requests());

    $content->update(['title' => 'Renamed campaign']);
    $synchronizer->sync(DriftingContent::class, (string) $content->id);

    $patches = array_values(array_filter(
        array_slice($fake->requests(), $afterCreate),
        fn (array $request): bool => $request['method'] === 'PATCH' && $request['path'] === 'campaigns/1',
    ));

    // Before the fix a renamed campaign was hashed, discarded, and re-hashed
    // forever: the remote campaign could never catch up with the source.
    expect($patches)->toHaveCount(1)
        ->and($patches[0]['json'])->toBe(['name' => 'Renamed campaign'])
        // `is_active` is forwarded to the primary link by the API, so sending
        // it here would un-pause a campaign paused by hand in the dashboard.
        ->and($patches[0]['json'])->not->toHaveKey('is_active')
        ->and(CampaignMapping::query()->value('state'))->toBe('synced');
});

it('D3: does not re-PATCH a campaign whose payload has not drifted', function (): void {
    config()->set('lnkflow.content.models', [DriftingContent::class => DriftingContentAdapter::class]);
    config()->set('lnkflow.content.preview_before_write', false);
    $content = DriftingContent::query()->create([
        'title' => 'Stable name',
        'destination_url' => 'https://example.com/docs',
    ]);
    $fake = new FakeTransport;
    $synchronizer = new ContentSynchronizer(new Client($fake));

    $synchronizer->sync(DriftingContent::class, (string) $content->id);
    $synchronizer->sync(DriftingContent::class, (string) $content->id);

    expect(array_filter(
        $fake->requests(),
        fn (array $request): bool => $request['method'] === 'PATCH',
    ))->toBe([]);
});

it('D4: omits is_active and conversion_tracking_enabled unless the host owns them', function (): void {
    config()->set('lnkflow.content.models', [DriftingContent::class => DriftingContentAdapter::class]);
    config()->set('lnkflow.content.preview_before_write', false);
    $content = DriftingContent::query()->create([
        'title' => 'Docs',
        'destination_url' => 'https://example.com/docs',
    ]);
    $fake = new FakeTransport;
    $synchronizer = new ContentSynchronizer(new Client($fake));

    $synchronizer->sync(DriftingContent::class, (string) $content->id);
    $content->update(['destination_url' => 'https://example.com/new-docs']);
    $synchronizer->sync(DriftingContent::class, (string) $content->id);

    $update = array_values(array_filter(
        $fake->requests(),
        fn (array $request): bool => $request['method'] === 'PATCH' && $request['path'] === 'links/1',
    ))[0];

    // Sending these on every content change un-paused paused links and reset
    // conversion tracking that had been switched on in the dashboard.
    expect($update['json'])->not->toHaveKey('is_active')
        ->and($update['json'])->not->toHaveKey('conversion_tracking_enabled')
        ->and($update['json']['destination_url'])->toBe('https://example.com/new-docs');
});

it('D4: sends both flags when the host explicitly sets them', function (): void {
    $payload = (new CreateLink(
        'https://example.com/docs',
        active: false,
        conversionTrackingEnabled: true,
    ))->toArray();

    expect($payload['is_active'])->toBeFalse()
        ->and($payload['conversion_tracking_enabled'])->toBeTrue();
});

it('D4: omits both flags from a bare create payload', function (): void {
    expect((new CreateLink('https://example.com/docs'))->toArray())
        ->toBe(['destination_url' => 'https://example.com/docs']);
});

it('D5: rejects an unsupported UTM key on CreateLink at construction', function (): void {
    expect(fn () => new CreateLink('https://example.com', utm: ['utm_id' => 'abc']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported UTM parameter(s) [utm_id]');
});

it('D5: rejects an unsupported UTM key on LinkDefinition at construction', function (): void {
    // Without this the bad key survived until a queued job ran, so the failure
    // surfaced far from the code that caused it.
    expect(fn () => new LinkDefinition(
        placement: 'primary',
        campaignKey: 'docs',
        campaignName: 'Docs',
        destinationUrl: 'https://example.com',
        utm: ['utm_source' => 'app', 'gclid' => 'x'],
    ))->toThrow(InvalidArgumentException::class, 'Unsupported UTM parameter(s) [gclid]');
});

it('D5: accepts every UTM key the API supports', function (): void {
    $utm = [
        'utm_source' => 'app',
        'utm_medium' => 'referral',
        'utm_campaign' => 'docs',
        'utm_term' => 'laravel',
        'utm_content' => 'sidebar',
    ];

    expect((new CreateLink('https://example.com', utm: $utm))->toArray())
        ->toBe(['destination_url' => 'https://example.com', ...$utm]);
});
