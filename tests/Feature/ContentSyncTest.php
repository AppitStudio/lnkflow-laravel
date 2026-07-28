<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Data\LinkDefinition;
use LnkFlow\Laravel\Models\CampaignMapping;
use LnkFlow\Laravel\Models\LinkMapping;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Services\ContentSynchronizer;
use LnkFlow\Laravel\Testing\FakeTransport;

final class TestContent extends Model
{
    protected $table = 'test_contents';

    protected $guarded = [];
}

final class TestContentAdapter implements LinkableContent
{
    public function lnkFlowSourceKey(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function lnkFlowLinks(Model $model): iterable
    {
        yield new LinkDefinition(
            placement: 'primary',
            campaignKey: 'documentation',
            campaignName: 'Documentation',
            destinationUrl: (string) $model->getAttribute('destination_url'),
            name: (string) $model->getAttribute('title'),
            slug: 'documentation',
            utm: ['utm_source' => 'app', 'utm_campaign' => 'documentation'],
            conversionTrackingEnabled: true,
            autoPromoCode: 'DOCS20',
        );
    }
}

it('converges content mappings and updates only when the payload changes', function (): void {
    config()->set('lnkflow.content.models', [TestContent::class => TestContentAdapter::class]);
    $content = TestContent::query()->create([
        'title' => 'Documentation',
        'destination_url' => 'https://example.com/docs',
    ]);
    $fake = new FakeTransport;
    $synchronizer = new ContentSynchronizer(new Client($fake));

    expect($synchronizer->sync(TestContent::class, (string) $content->id))->toBe(1)
        ->and(CampaignMapping::query()->count())->toBe(1)
        ->and(LinkMapping::query()->count())->toBe(1)
        ->and(LinkMapping::query()->value('state'))->toBe('synced')
        ->and($fake->requests())->toHaveCount(3);

    $synchronizer->sync(TestContent::class, (string) $content->id);
    expect($fake->requests())->toHaveCount(3);

    $content->update(['destination_url' => 'https://example.com/new-docs']);
    $synchronizer->sync(TestContent::class, (string) $content->id);

    expect($fake->requests())->toHaveCount(4)
        ->and($fake->requests()[3]['method'])->toBe('PATCH')
        ->and($fake->requests()[3]['path'])->toBe('links/1');
});
