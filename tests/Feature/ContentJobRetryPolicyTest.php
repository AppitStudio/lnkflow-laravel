<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Contracts\LinkableContent;
use LnkFlow\Laravel\Data\LinkDefinition;
use LnkFlow\Laravel\Exceptions\AuthorizationException;
use LnkFlow\Laravel\Exceptions\NotFoundException;
use LnkFlow\Laravel\Exceptions\ServerException;
use LnkFlow\Laravel\Exceptions\ValidationException;
use LnkFlow\Laravel\Jobs\DisableLinkableContentJob;
use LnkFlow\Laravel\Jobs\SyncLinkableContentJob;
use LnkFlow\Laravel\Models\LinkMapping;
use LnkFlow\Laravel\Services\ContentSynchronizer;
use LnkFlow\Laravel\Tests\Fixture;

/*
 * The content jobs share the same retry policy as every other LnkFlow API job.
 * They used to declare their own `$tries`/`$backoff` and never route through
 * `callApi()`, so a 422 or a 403 — outcomes that cannot change on a retry —
 * burned five attempts across eight minutes before failing anyway.
 */

final class RetryContent extends Model
{
    protected $table = 'test_contents';

    protected $guarded = [];
}

final class RetryContentAdapter implements LinkableContent
{
    public function lnkFlowSourceKey(Model $model): string
    {
        return (string) $model->getKey();
    }

    public function lnkFlowLinks(Model $model): iterable
    {
        yield new LinkDefinition(
            placement: 'primary',
            campaignKey: 'retry',
            campaignName: 'Retry',
            destinationUrl: (string) $model->getAttribute('destination_url'),
            slug: 'retry',
        );
    }
}

beforeEach(function (): void {
    config()->set('lnkflow.content.models', [RetryContent::class => RetryContentAdapter::class]);
    config()->set('lnkflow.content.preview_before_write', false);
});

it('fails a content sync immediately when the API rejects the payload', function (): void {
    $content = RetryContent::query()->create([
        'title' => 'Docs',
        'destination_url' => 'https://example.com/docs',
    ]);
    Http::fake(['*' => Fixture::response('campaigns-store/422')]);
    $job = new SyncLinkableContentJob(RetryContent::class, (string) $content->id);
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(ValidationException::class));
    $queueJob->shouldNotReceive('release');
    $job->setJob($queueJob);

    $job->handle(app(ContentSynchronizer::class));
});

it('fails a content sync immediately when the token cannot write', function (): void {
    $content = RetryContent::query()->create([
        'title' => 'Docs',
        'destination_url' => 'https://example.com/docs',
    ]);
    Http::fake(['*' => Fixture::response('websites-store/403')]);
    $job = new SyncLinkableContentJob(RetryContent::class, (string) $content->id);
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(AuthorizationException::class));
    $job->setJob($queueJob);

    $job->handle(app(ContentSynchronizer::class));
});

it('releases a content sync for the delay the server asked for', function (): void {
    $content = RetryContent::query()->create([
        'title' => 'Docs',
        'destination_url' => 'https://example.com/docs',
    ]);
    Http::fake(['*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '30'])]);
    $job = new SyncLinkableContentJob(RetryContent::class, (string) $content->id);
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('release')->once()->with(30);
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);

    $job->handle(app(ContentSynchronizer::class));
});

it('lets a transient content sync failure bubble so the queue retries it', function (): void {
    $content = RetryContent::query()->create([
        'title' => 'Docs',
        'destination_url' => 'https://example.com/docs',
    ]);
    Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

    expect(fn () => (new SyncLinkableContentJob(RetryContent::class, (string) $content->id))
        ->handle(app(ContentSynchronizer::class)))
        ->toThrow(ServerException::class);
});

it('fails a disable job immediately on a permanent API failure', function (): void {
    LinkMapping::query()->create([
        'connection' => 'default',
        'remote_team_id' => 'team-test',
        'source_type' => RetryContent::class,
        'source_id' => '1',
        'placement' => 'primary',
        'remote_link_id' => 7,
        'idempotency_key' => '00000000-0000-4000-8000-000000000001',
        'state' => 'synced',
    ]);
    Http::fake(['*' => Fixture::response('links-update/404')]);
    $job = new DisableLinkableContentJob(RetryContent::class, '1');
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(NotFoundException::class));
    $queueJob->shouldNotReceive('release');
    $job->setJob($queueJob);

    $job->handle(app(ContentSynchronizer::class));
});

it('deactivates rather than deleting when content goes away', function (): void {
    LinkMapping::query()->create([
        'connection' => 'default',
        'remote_team_id' => 'team-test',
        'source_type' => RetryContent::class,
        'source_id' => '1',
        'placement' => 'primary',
        'remote_link_id' => 7,
        'idempotency_key' => '00000000-0000-4000-8000-000000000001',
        'state' => 'synced',
    ]);
    Http::fake(['*' => Fixture::response('links-update/200')]);

    (new DisableLinkableContentJob(RetryContent::class, '1'))->handle(app(ContentSynchronizer::class));

    // Local deletion must never call remote DELETE: the short URL stays
    // resolvable history, it just stops being active.
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://app.lnkflow.test/api/v1/links/7'
        && $request['is_active'] === false);
    expect(LinkMapping::query()->value('state'))->toBe('disabled');
});
