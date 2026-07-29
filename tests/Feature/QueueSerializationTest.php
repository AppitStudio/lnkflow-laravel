<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use LnkFlow\Laravel\Data\ConsentState;
use LnkFlow\Laravel\Data\EnrichedPayload;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Events\ConversionFailed;
use LnkFlow\Laravel\Exceptions\RateLimitException;
use LnkFlow\Laravel\Exceptions\ServerException;
use LnkFlow\Laravel\Exceptions\ValidationException;
use LnkFlow\Laravel\Jobs\CaptureTouchpointJob;
use LnkFlow\Laravel\Jobs\DisableLinkableContentJob;
use LnkFlow\Laravel\Jobs\IdentifyVisitorJob;
use LnkFlow\Laravel\Jobs\RevokeVisitorJob;
use LnkFlow\Laravel\Jobs\SendConversionJob;
use LnkFlow\Laravel\Jobs\SyncLinkableContentJob;
use LnkFlow\Laravel\Jobs\UnidentifyVisitorJob;
use LnkFlow\Laravel\Services\Client;

/*
 * Tokens are resolved from configuration inside `handle()`, never carried on a
 * job. A queued payload outlives the request that created it: it is written to
 * a database or Redis, shown in Horizon, and kept forever in a failed-job row.
 * A token on a job is therefore a token in four more places than it belongs.
 */

/** The exact payload the queue stores, from the real database driver. */
function queuedPayload(object $job): string
{
    DB::table('jobs')->delete();
    Queue::connection('database')->push($job);

    return (string) DB::table('jobs')->value('payload');
}

/**
 * Everything a job's constructor accepts, by name. An extra key here means a
 * job started carrying state it has no business carrying.
 *
 * @return array<string, mixed>
 */
function jobState(object $job): array
{
    $state = [];

    foreach ((new ReflectionObject($job))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $state[$parameter->getName()] = $job->{$parameter->getName()};
    }

    return $state;
}

/** @return array<string, array{object}> */
function everyQueuedJob(): array
{
    $visitor = '10000000-0000-4000-8000-000000000001';
    $click = '20000000-0000-4000-8000-000000000002';

    return [
        'SendConversionJob' => [new SendConversionJob(
            'sale',
            new EnrichedPayload(new Sale('invoice_42', 2500, 'usd'), ['visitor_id' => $visitor]),
            'invoice_42',
        )],
        'SyncLinkableContentJob' => [new SyncLinkableContentJob('App\\Models\\Post', '42')],
        'DisableLinkableContentJob' => [new DisableLinkableContentJob('App\\Models\\Post', '42')],
        'CaptureTouchpointJob' => [new CaptureTouchpointJob($visitor, $click, 10, ['storage' => 'granted'])],
        'IdentifyVisitorJob' => [new IdentifyVisitorJob($visitor, 'customer_opaque_7', 10)],
        'UnidentifyVisitorJob' => [new UnidentifyVisitorJob($visitor, 10)],
        'RevokeVisitorJob' => [new RevokeVisitorJob($visitor, 10)],
    ];
}

beforeEach(function (): void {
    config()->set('queue.connections.database', [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
    ]);
});

it('never serializes an API token into a queued job', function (object $job): void {
    $payload = queuedPayload($job);

    expect($payload)->not->toContain('api-test-token')
        ->and($payload)->not->toContain('link-test-token')
        ->and($payload)->not->toContain('conversion-test-token')
        ->and($payload)->not->toContain('Bearer ')
        ->and($payload)->not->toContain('Authorization')
        // Nor the transport or client that hold one.
        ->and($payload)->not->toContain('ApiTransport')
        ->and($payload)->not->toContain('Services\\\\Client');
})->with(everyQueuedJob());

it('round-trips through the real queue payload', function (object $job): void {
    $payload = json_decode(queuedPayload($job), true);
    $restored = unserialize($payload['data']['command']);

    expect($payload['data']['commandName'])->toBe($job::class)
        ->and($restored)->toBeInstanceOf($job::class)
        ->and(jobState($restored))->toEqual(jobState($job));
})->with(everyQueuedJob());

it('carries only the opaque identifiers a journey operation documents', function (): void {
    $visitor = '10000000-0000-4000-8000-000000000001';
    $click = '20000000-0000-4000-8000-000000000002';

    expect(jobState(new CaptureTouchpointJob($visitor, $click, 10, ['storage' => ConsentState::Granted->value])))
        ->toBe([
            'visitorId' => $visitor,
            'clickId' => $click,
            'websiteId' => 10,
            'consent' => ['storage' => 'granted'],
        ])
        ->and(jobState(new IdentifyVisitorJob($visitor, 'customer_opaque_7', 10)))
        ->toBe([
            'visitorId' => $visitor,
            'customerExternalId' => 'customer_opaque_7',
            'websiteId' => 10,
        ])
        ->and(jobState(new UnidentifyVisitorJob($visitor, 10)))
        ->toBe(['visitorId' => $visitor, 'websiteId' => 10])
        ->and(jobState(new RevokeVisitorJob($visitor, 10)))
        ->toBe(['visitorId' => $visitor, 'websiteId' => 10]);
});

it('carries only a model reference on a content job, never the model itself', function (): void {
    expect(jobState(new SyncLinkableContentJob('App\\Models\\Post', '42', true)))
        ->toBe(['modelClass' => 'App\\Models\\Post', 'modelKey' => '42', 'force' => true])
        ->and(jobState(new DisableLinkableContentJob('App\\Models\\Post', '42')))
        ->toBe(['modelClass' => 'App\\Models\\Post', 'sourceKey' => '42']);
});

it('resolves the model fresh in the job rather than serializing its attributes', function (): void {
    $payload = queuedPayload(new SyncLinkableContentJob('App\\Models\\Post', '42'));

    expect($payload)->toContain('App\\\\Models\\\\Post')
        ->and($payload)->not->toContain('destination_url')
        ->and($payload)->not->toContain('utm_source');
});

it('never queues a customer email or name by default', function (): void {
    $payload = queuedPayload(new SendConversionJob(
        'sale',
        new Sale('invoice_42', 2500, 'usd', customerExternalId: 'customer_opaque_7'),
        'invoice_42',
    ));

    expect($payload)->toContain('customer_opaque_7')
        ->and($payload)->not->toContain('customer_email')
        ->and($payload)->not->toContain('customer_name');
});

it('coalesces overlapping content syncs for the same source record', function (): void {
    $first = new SyncLinkableContentJob('App\\Models\\Post', '42');
    $second = new SyncLinkableContentJob('App\\Models\\Post', '42');
    $other = new SyncLinkableContentJob('App\\Models\\Post', '43');

    expect($first->uniqueId())->toBe($second->uniqueId())
        ->and($first->uniqueId())->not->toBe($other->uniqueId())
        ->and($first->middleware()[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($first->uniqueId())->not->toContain('App\\Models\\Post');
});

it('releases a job for exactly as long as the server asked on a rate limit', function (): void {
    $job = new IdentifyVisitorJob('visitor-1', 'customer-1');
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('release')->once()->with(75);
    $queueJob->shouldNotReceive('fail');
    $job->setJob($queueJob);
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('journeys')->andThrow(new RateLimitException('Too Many Attempts.', retryAfter: 75));

    // Blocking a worker on `sleep` for the server's back-off is what the
    // release exists to avoid.
    $job->handle($client);
});

it('releases for a sane default when a rate limit carries no Retry-After', function (): void {
    $job = new RevokeVisitorJob('visitor-1');
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('release')->once()->with(60);
    $job->setJob($queueJob);
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('journeys')->andThrow(new RateLimitException('Too Many Attempts.'));

    $job->handle($client);
});

it('fails a job immediately on a permanent API failure instead of burning five attempts', function (): void {
    $job = new IdentifyVisitorJob('visitor-1', 'customer-1');
    $queueJob = Mockery::mock(QueueJobContract::class);
    $queueJob->shouldReceive('fail')->once()->with(Mockery::type(ValidationException::class));
    $queueJob->shouldNotReceive('release');
    $job->setJob($queueJob);
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('journeys')->andThrow(new ValidationException('The visitor id field is required.', 422));

    $job->handle($client);

    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 120, 300]);
});

it('shares the same retry policy across every LnkFlow API job', function (object $job): void {
    // The trait's promise is that this is the policy every API job shares. A
    // job that quietly declares its own would burn five attempts and eight
    // minutes on a 422 that can never succeed.
    expect($job->tries)->toBe(5)
        ->and($job->backoff)->toBe([10, 30, 120, 300]);
})->with(everyQueuedJob());

it('lets a transient failure bubble so the queue can retry it with backoff', function (): void {
    $job = new IdentifyVisitorJob('visitor-1', 'customer-1');
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('journeys')->andThrow(new ServerException('Server Error', 500));

    expect(fn () => $job->handle($client))->toThrow(ServerException::class);
});

it('reports a permanently failed conversion without leaking its payload', function (): void {
    Event::fake();
    $job = new SendConversionJob('sale', new Sale('invoice_42', 2500, 'usd'), 'invoice_42');

    $job->failed(new ValidationException('The amount field is required.', 422));

    Event::assertDispatched(
        ConversionFailed::class,
        fn (ConversionFailed $event): bool => $event->type === 'sale'
            && $event->businessId === 'invoice_42'
            // The class name only: never the message, the payload, or the
            // customer behind it.
            && $event->errorClass === ValidationException::class,
    );
});
