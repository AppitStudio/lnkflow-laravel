<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateInfluencer;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Data\Visitor;
use LnkFlow\Laravel\Exceptions\AuthorizationException;
use LnkFlow\Laravel\Exceptions\ConnectionException;
use LnkFlow\Laravel\Exceptions\NotFoundException;
use LnkFlow\Laravel\Http\ApiTransport;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

function sentAuthorization(): string
{
    $header = null;

    Http::assertSent(function (Request $request) use (&$header): bool {
        $header = $request->header('Authorization')[0] ?? '';

        return true;
    });

    return (string) $header;
}

it('sends the least privileged token each client is entitled to', function (string $token, Closure $call): void {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $call(app(Client::class));

    expect(sentAuthorization())->toBe('Bearer '.$token);
})->with([
    'campaigns use the link token' => ['link-test-token', fn (Client $c) => $c->campaigns()->list()],
    'campaign creates use the link token' => ['link-test-token', fn (Client $c) => $c->campaigns()->create(new CreateCampaign('X'), 'k')],
    'links use the link token' => ['link-test-token', fn (Client $c) => $c->links()->list()],
    'link creates use the link token' => ['link-test-token', fn (Client $c) => $c->links()->create(1, new CreateLink('https://example.test'), 'k')],
    'websites use the link token' => ['link-test-token', fn (Client $c) => $c->websites()->list()],
    'website creates use the link token' => ['link-test-token', fn (Client $c) => $c->websites()->create(new CreateWebsite('Docs'))],
    'domains use the link token' => ['link-test-token', fn (Client $c) => $c->domains()->list()],
    'influencers use the link token' => ['link-test-token', fn (Client $c) => $c->influencers()->list()],
    'influencer creates use the link token' => ['link-test-token', fn (Client $c) => $c->influencers()->create(new CreateInfluencer('Partner'))],
    'journeys use the conversion token' => ['conversion-test-token', fn (Client $c) => $c->journeys()->revoke(new Visitor('visitor-1'))],
    'conversion reads use the conversion token' => ['conversion-test-token', fn (Client $c) => $c->conversions()->events()],
    'conversion writes use the conversion token' => ['conversion-test-token', fn (Client $c) => $c->conversions()->sale(new Sale('i', 1, 'usd'))],
    'lead writes use the conversion token' => ['conversion-test-token', fn (Client $c) => $c->conversions()->lead(new Lead('cus'))],
    'identity uses the general token' => ['api-test-token', fn (Client $c) => $c->identity()->me()],
    'search uses the general token' => ['api-test-token', fn (Client $c) => $c->search()->query('nova')],
    'stats use the general token' => ['api-test-token', fn (Client $c) => $c->stats()->summary()],
]);

it('falls back to the general token when no purpose-specific token is configured', function (Closure $call): void {
    config()->set('lnkflow.connections.default.link_token', null);
    config()->set('lnkflow.connections.default.conversion_token', '');
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $call(app(Client::class));

    expect(sentAuthorization())->toBe('Bearer api-test-token');
})->with([
    'links' => [fn (Client $c) => $c->links()->list()],
    'conversions' => [fn (Client $c) => $c->conversions()->events()],
    'journeys' => [fn (Client $c) => $c->journeys()->revoke(new Visitor('visitor-1'))],
]);

it('still reaches read-only endpoints on a strict least-privilege setup', function (string $token, Closure $call): void {
    // The recommended production shape: a link token and a conversion token,
    // and no general token at all. `me`, `search`, `stats`, and the workspace
    // bundle are readable with any ability, so they must not dead-end here —
    // otherwise `lnkflow:doctor` passes its own checks and then fails its
    // connectivity probe.
    config()->set('lnkflow.connections.default.api_token', null);
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    $call(app(Client::class));

    expect(sentAuthorization())->toBe('Bearer '.$token);
})->with([
    'me prefers the link token' => ['link-test-token', fn (Client $c) => $c->identity()->me()],
    'search prefers the link token' => ['link-test-token', fn (Client $c) => $c->search()->query('nova')],
    'stats prefer the link token' => ['link-test-token', fn (Client $c) => $c->stats()->summary()],
    'the workspace bundle prefers the link token' => ['link-test-token', fn (Client $c) => $c->workspace()->bootstrap()],
]);

it('reaches read-only endpoints with only a conversion token', function (): void {
    config()->set('lnkflow.connections.default.api_token', null);
    config()->set('lnkflow.connections.default.link_token', null);
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    app(Client::class)->identity()->me();

    expect(sentAuthorization())->toBe('Bearer conversion-test-token');
});

it('never falls back to a token that cannot perform the write', function (Closure $call): void {
    // A conversion token has no `write` ability. Borrowing it for a link write
    // would swap a clear configuration error for a confusing runtime 403.
    config()->set('lnkflow.connections.default.api_token', null);
    config()->set('lnkflow.connections.default.link_token', null);
    Http::fake();

    expect(fn () => $call(app(Client::class)))
        ->toThrow(ConnectionException::class, 'No LnkFlow API token is configured.');

    Http::assertNothingSent();
})->with([
    'campaign create' => [fn (Client $c) => $c->campaigns()->create(new CreateCampaign('X'), 'k')],
    'link create' => [fn (Client $c) => $c->links()->create(1, new CreateLink('https://example.test'), 'k')],
    'website create' => [fn (Client $c) => $c->websites()->create(new CreateWebsite('Docs'))],
]);

it('never borrows a link token for a conversion write', function (): void {
    // The mirror case: a link token may well lack the `conversions` ability.
    config()->set('lnkflow.connections.default.api_token', null);
    config()->set('lnkflow.connections.default.conversion_token', null);
    Http::fake();

    expect(fn () => app(Client::class)->conversions()->sale(new Sale('invoice_1', 100, 'usd')))
        ->toThrow(ConnectionException::class, 'No LnkFlow API token is configured.');

    Http::assertNothingSent();
});

it('refuses to send a request when no token is configured at all', function (): void {
    config()->set('lnkflow.connections.default.api_token', null);
    config()->set('lnkflow.connections.default.link_token', null);
    config()->set('lnkflow.connections.default.conversion_token', null);
    Http::fake();

    expect(fn () => app(Client::class)->identity()->me())
        ->toThrow(ConnectionException::class, 'No LnkFlow API token is configured.');

    Http::assertNothingSent();
});

it('sends the configured team, correlation id, and SDK identification on every request', function (): void {
    Http::fake(['*' => Fixture::response('me/200')]);

    app(Client::class)->identity()->me();

    Http::assertSent(function (Request $request): bool {
        expect($request->header('X-LnkFlow-Team')[0])->toBe('team-test')
            ->and($request->header('Accept')[0])->toBe('application/json')
            ->and($request->header('X-LnkFlow-Request-Id')[0])->toMatch('/^[0-9a-f-]{36}$/')
            ->and($request->header('X-LnkFlow-SDK-Version')[0])->toBe(ApiTransport::VERSION)
            ->and($request->header('User-Agent')[0])->toStartWith('lnkflow-laravel/');

        return true;
    });
});

it('lets an explicit team override the configured one without leaking across clients', function (): void {
    Http::fake(['*' => Fixture::response('me/200')]);
    $client = app(Client::class);

    $client->forTeam(99)->identity()->me();
    $client->identity()->me();

    $teams = [];
    Http::assertSent(function (Request $request) use (&$teams): bool {
        $teams[] = $request->header('X-LnkFlow-Team')[0] ?? null;

        return true;
    });

    expect($teams)->toBe(['99', 'team-test']);
});

it('omits the team header entirely when no team is configured', function (): void {
    config()->set('lnkflow.connections.default.team', null);
    Http::fake(['*' => Fixture::response('me/200')]);

    app(Client::class)->identity()->me();

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-LnkFlow-Team'));
});

it('reuses one correlation id across the retries of a single logical call', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'Server Error'], 500)
        ->push(Fixture::body('campaigns-show/200'), 200);

    app(Client::class)->campaigns()->get(1);

    $ids = [];
    Http::assertSent(function (Request $request) use (&$ids): bool {
        $ids[] = $request->header('X-LnkFlow-Request-Id')[0] ?? null;

        return true;
    });

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toBe($ids[1]);
});

it('surfaces a cross-team read as an authorization failure without softening it', function (): void {
    Http::fake([Fixture::url('me/403').'*' => Fixture::response('me/403')]);

    try {
        app(Client::class)->forTeam(4242)->identity()->me();
        test()->fail('Expected the tenant boundary to be enforced.');
    } catch (AuthorizationException $exception) {
        // The SDK must not translate a tenant refusal into an empty result, a
        // null, or a retry against another team: 403 is a security boundary.
        expect($exception->status)->toBe(403)
            ->and($exception->getMessage())->toBe('You do not have access to that team.');
    }

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-LnkFlow-Team', '4242'));
});

it('surfaces a cross-tenant resource as missing rather than inventing an empty one', function (): void {
    Http::fake(['*' => Fixture::response('links-show/404')]);

    expect(fn () => app(Client::class)->links()->get(2))->toThrow(NotFoundException::class);
});

it('keeps a per-connection client isolated from the default one', function (): void {
    config()->set('lnkflow.connections.reporting', [
        ...config('lnkflow.connections.default'),
        'url' => 'https://reporting.lnkflow.test/api/v1',
        'api_token' => 'reporting-token',
        'team' => 'team-reporting',
    ]);
    Http::fake(['*' => Fixture::response('me/200')]);

    app(Client::class)->connection('reporting')->identity()->me();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://reporting.lnkflow.test/api/v1/me'
        && $request->hasHeader('Authorization', 'Bearer reporting-token')
        && $request->hasHeader('X-LnkFlow-Team', 'team-reporting'));
});

it('fails loudly for an unconfigured connection instead of falling back', function (): void {
    Http::fake();

    expect(fn () => app(Client::class)->connection('missing')->identity()->me())
        ->toThrow(ConnectionException::class, 'The LnkFlow connection [missing] is not configured.');

    Http::assertNothingSent();
});
