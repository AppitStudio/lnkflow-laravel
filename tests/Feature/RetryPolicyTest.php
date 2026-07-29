<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\UpdateLink;
use LnkFlow\Laravel\Exceptions\AuthenticationException;
use LnkFlow\Laravel\Exceptions\AuthorizationException;
use LnkFlow\Laravel\Exceptions\ConflictException;
use LnkFlow\Laravel\Exceptions\ConnectionException;
use LnkFlow\Laravel\Exceptions\NotFoundException;
use LnkFlow\Laravel\Exceptions\RateLimitException;
use LnkFlow\Laravel\Exceptions\ServerException;
use LnkFlow\Laravel\Exceptions\ValidationException;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

/*
 * The test connection allows two attempts with a zero-millisecond backoff base
 * and the default 2000 ms `retry_max_wait_milliseconds` cap, so anything these
 * tests measure in seconds came from a `Retry-After` the SDK chose to honour.
 */

it('stops retrying when Retry-After exceeds the synchronous wait budget', function (): void {
    Http::fake([
        '*' => Http::response(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '60']),
    ]);
    $startedAt = microtime(true);

    try {
        app(Client::class)->campaigns()->create(new CreateCampaign('Busy'), 'campaign:busy');
        test()->fail('Expected a rate limit exception.');
    } catch (RateLimitException $exception) {
        // The delay belongs to the queue, not to this process: a 60 second
        // Retry-After must surface as a typed exception carrying the delay,
        // never as a 60 second `sleep` pinning a worker.
        expect($exception->retryAfter)->toBe(60);
    }

    expect(microtime(true) - $startedAt)->toBeLessThan(2.0);
    Http::assertSentCount(1);
});

it('understands an HTTP-date Retry-After and still refuses to sleep it off', function (): void {
    Http::fake([
        '*' => Http::response(['message' => 'Too Many Attempts.'], 429, [
            'Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 120),
        ]),
    ]);
    $startedAt = microtime(true);

    try {
        app(Client::class)->campaigns()->create(new CreateCampaign('Busy'), 'campaign:busy');
        test()->fail('Expected a rate limit exception.');
    } catch (RateLimitException $exception) {
        expect($exception->retryAfter)->toBeGreaterThanOrEqual(118)
            ->and($exception->retryAfter)->toBeLessThanOrEqual(120);
    }

    expect(microtime(true) - $startedAt)->toBeLessThan(2.0);
    Http::assertSentCount(1);
});

it('honours a Retry-After that fits inside the wait budget', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'Too Many Attempts.'], 429, ['Retry-After' => '1'])
        ->push(Fixture::body('campaigns-store/201'), 201);
    $startedAt = microtime(true);

    $campaign = app(Client::class)->campaigns()->create(new CreateCampaign('Summer Launch'), 'campaign:summer');
    $elapsed = microtime(true) - $startedAt;

    expect($campaign->id)->toBe(3)
        ->and($elapsed)->toBeGreaterThanOrEqual(0.9)
        ->and($elapsed)->toBeLessThan(3.0);
    Http::assertSentCount(2);
});

it('caps its own exponential backoff at the wait budget', function (): void {
    config()->set('lnkflow.connections.default.attempts', 3);
    config()->set('lnkflow.connections.default.retry_base_milliseconds', 100_000);
    config()->set('lnkflow.connections.default.retry_max_wait_milliseconds', 50);
    Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);
    $startedAt = microtime(true);

    expect(fn () => app(Client::class)->campaigns()->create(new CreateCampaign('X'), 'k'))
        ->toThrow(ServerException::class);

    // Two waits of at most 50 ms each, not two of 100 seconds.
    expect(microtime(true) - $startedAt)->toBeLessThan(2.0);
    Http::assertSentCount(3);
});

it('maps a connection failure to a typed exception and retries an idempotent read', function (): void {
    Http::fake(['*' => Http::failedConnection('Connection timed out')]);

    try {
        app(Client::class)->campaigns()->get(1);
        test()->fail('Expected a connection exception.');
    } catch (ConnectionException $exception) {
        expect($exception->getMessage())->toBe('Unable to connect to the LnkFlow API.')
            ->and($exception->requestId)->toBeString();
    }

    Http::assertSentCount(2);
});

it('never replays a POST that carries no idempotency guarantee', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => app(Client::class)->websites()->create(new CreateWebsite('No retry')))
        ->toThrow(ConnectionException::class);

    // A create with no key could have been applied server-side before the
    // connection dropped. Repeating it would risk a duplicate resource.
    Http::assertSentCount(1);
});

it('replays a POST that carries an Idempotency-Key', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => app(Client::class)->campaigns()->create(new CreateCampaign('Release'), 'campaign:release'))
        ->toThrow(ConnectionException::class);

    Http::assertSentCount(2);
});

it('replays a POST that carries a stable business identifier', function (): void {
    Http::fake(['*' => Http::failedConnection()]);

    // Conversion endpoints have no Idempotency-Key header; the invoice id is
    // the server-side idempotency anchor, so a retry is still safe.
    expect(fn () => app(Client::class)->conversions()->sale(new Sale('invoice_42', 2500, 'usd')))
        ->toThrow(ConnectionException::class);

    Http::assertSentCount(2);
});

it('retries a 409 only when the server says the idempotent create is still running', function (): void {
    Http::fakeSequence()
        ->push(Fixture::body('campaigns-store/409'), 409)
        ->push(Fixture::body('campaigns-store/201'), 201);

    $campaign = app(Client::class)->campaigns()->create(new CreateCampaign('Summer Launch'), 'campaign:summer');

    expect($campaign->id)->toBe(3);
    Http::assertSentCount(2);
});

it('does not retry any other 409', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'That slug is taken.', 'code' => 'SLUG_TAKEN'], 409)
        ->push(Fixture::body('campaigns-store/201'), 201);

    expect(fn () => app(Client::class)->campaigns()->create(new CreateCampaign('Summer Launch'), 'campaign:summer'))
        ->toThrow(ConflictException::class, 'That slug is taken.');

    Http::assertSentCount(1);
});

it('does not retry a 409 with no error code at all', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'Conflict.'], 409)
        ->push(Fixture::body('campaigns-store/201'), 201);

    expect(fn () => app(Client::class)->campaigns()->create(new CreateCampaign('X'), 'k'))
        ->toThrow(ConflictException::class);

    Http::assertSentCount(1);
});

it('never retries a permanent failure', function (string $fixture, string $exception): void {
    Http::fakeSequence()
        ->push(Fixture::body($fixture), Fixture::status($fixture))
        ->push(Fixture::body('campaigns-store/201'), 201);

    expect(fn () => app(Client::class)->campaigns()->create(new CreateCampaign('X'), 'k'))
        ->toThrow($exception);

    Http::assertSentCount(1);
})->with([
    '401' => ['campaigns-store/401', AuthenticationException::class],
    '403' => ['campaigns-store/403', AuthorizationException::class],
    '422' => ['campaigns-store/422', ValidationException::class],
]);

it('never retries a 404 on a read', function (): void {
    Http::fakeSequence()
        ->push(Fixture::body('campaigns-show/404'), 404)
        ->push(Fixture::body('campaigns-show/200'), 200);

    expect(fn () => app(Client::class)->campaigns()->get(2))->toThrow(NotFoundException::class);

    Http::assertSentCount(1);
});

it('retries a 5xx on a read until the attempt budget is spent', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'Server Error'], 500)
        ->push(Fixture::body('campaigns-show/200'), 200);

    expect(app(Client::class)->campaigns()->get(1)->id)->toBe(1);
    Http::assertSentCount(2);
});

it('surfaces an idempotent replay so a caller can tell a create from a no-op', function (): void {
    Http::fake(['*' => Fixture::response('campaigns-store/201-idempotent-replay')]);

    $campaign = app(Client::class)->campaigns()->create(new CreateCampaign('Autumn Launch'), 'campaign:autumn');

    expect($campaign->replayed())->toBeTrue()
        ->and($campaign->id)->toBe(4);
});

it('reports a fresh create as not replayed', function (): void {
    Http::fake(['*' => Fixture::response('campaigns-store/201-idempotent-first')]);

    expect(app(Client::class)->campaigns()->create(new CreateCampaign('Autumn Launch'), 'campaign:autumn')->replayed())
        ->toBeFalse();
});

it('surfaces an idempotent replay on a link create too', function (): void {
    Http::fake(['*' => Fixture::response('campaign-links-store/201', headers: ['Idempotent-Replayed' => 'true'])]);

    $link = app(Client::class)->links()->create(1, new CreateLink('https://storefront.example/spring'), 'link:1');

    expect($link->replayed())->toBeTrue();
});

it('reports a plain read as not replayed', function (): void {
    Http::fake(['*' => Fixture::response('links-update/200')]);

    $link = app(Client::class)->links()->update(1, new UpdateLink(['name' => 'Nova YouTube']));

    expect($link->replayed())->toBeFalse()
        ->and($link->requestId())->toBe('00000000-0000-4000-8000-000000000000');
});
