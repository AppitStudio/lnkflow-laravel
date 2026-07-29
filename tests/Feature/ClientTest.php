<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\CreateCampaign;
use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\CreateWebsite;
use LnkFlow\Laravel\Exceptions\ServerException;
use LnkFlow\Laravel\Services\Client;

it('uses the least privileged token, team header, request id, and idempotency key', function (): void {
    Http::fake([
        'app.lnkflow.test/api/v1/campaigns/12/links' => Http::response([
            'data' => [
                'id' => 44,
                'campaign_id' => 12,
                'slug' => 'docs',
                'short_url' => 'https://acme.mylnk.click/docs',
                'edge_status' => 'published',
                'future_field' => 'kept',
            ],
        ], 201),
    ]);

    $link = app(Client::class)->forTeam('team-44')->links()->create(
        12,
        new CreateLink('https://example.com/docs', slug: 'docs'),
        'link:content:44',
    );

    expect($link->id)->toBe(44)
        ->and($link->raw['future_field'])->toBe('kept');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://app.lnkflow.test/api/v1/campaigns/12/links'
            && $request->hasHeader('Authorization', 'Bearer link-test-token')
            && $request->hasHeader('X-LnkFlow-Team', 'team-44')
            && $request->hasHeader('Idempotency-Key', 'link:content:44')
            && $request->hasHeader('X-LnkFlow-Request-Id')
            && $request->hasHeader('X-LnkFlow-SDK-Version');
    });
});

it('retries a POST when it has an idempotency guarantee', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'temporary'], 500)
        ->push(['data' => ['id' => 1, 'name' => 'Release', 'slug' => 'release']], 201);

    $campaign = app(Client::class)->campaigns()->create(
        new CreateCampaign('Release'),
        'campaign:release',
    );

    expect($campaign->id)->toBe(1);
    Http::assertSentCount(2);
});

it('does not retry a POST without an idempotency guarantee', function (): void {
    Http::fakeSequence()
        ->push(['message' => 'temporary'], 500)
        ->push(['data' => ['id' => 2]], 201);

    expect(fn () => app(Client::class)->websites()->create(new CreateWebsite('No retry')))
        ->toThrow(ServerException::class);
    Http::assertSentCount(1);
});

it('maps safe API errors and keeps the server request id', function (): void {
    Http::fake([
        '*' => Http::response(
            ['message' => 'Remote unavailable', 'code' => 'edge_pending'],
            503,
            ['X-LnkFlow-Request-Id' => 'req_server_123'],
        ),
    ]);

    try {
        app(Client::class)->campaigns()->get(99);
        test()->fail('Expected a server exception.');
    } catch (ServerException $exception) {
        expect($exception->getMessage())->toBe('Remote unavailable')
            ->and($exception->requestId)->toBe('req_server_123')
            ->and($exception->errorCode)->toBe('edge_pending');
    }
});
