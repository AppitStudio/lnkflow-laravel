<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use LnkFlow\Laravel\Data\Campaign;
use LnkFlow\Laravel\Data\Commission;
use LnkFlow\Laravel\Data\Page;
use LnkFlow\Laravel\Services\Client;
use LnkFlow\Laravel\Tests\Fixture;

/**
 * The corpus only ever records a single page, so these build page 2 from the
 * real page-1 bytes: same envelope, same meta keys, different page numbers.
 *
 * @return array<string, mixed>
 */
function pageOf(string $fixture, int $current, int $last, int $id): array
{
    $body = Fixture::body($fixture);
    $rows = is_array($body['data'] ?? null) ? $body['data'] : [];
    $row = is_array($rows[0] ?? null) ? $rows[0] : [];
    $body['data'] = [[...$row, 'id' => $id]];
    $body['meta'] = [
        ...(is_array($body['meta'] ?? null) ? $body['meta'] : []),
        'current_page' => $current,
        'last_page' => $last,
        'per_page' => 1,
        'total' => $last,
    ];
    $body['links'] = [
        'first' => 'https://app.lnkflow.test/api/v1/campaigns?page=1',
        'last' => 'https://app.lnkflow.test/api/v1/campaigns?page='.$last,
        'prev' => $current > 1 ? 'https://app.lnkflow.test/api/v1/campaigns?page='.($current - 1) : null,
        'next' => $current < $last ? 'https://app.lnkflow.test/api/v1/campaigns?page='.($current + 1) : null,
    ];

    return $body;
}

it('reads the pagination envelope the API guarantees', function (): void {
    Http::fake(['*' => Http::response(pageOf('campaigns-index/200', 1, 3, 11))]);

    $page = app(Client::class)->campaigns()->list();

    expect($page->currentPage())->toBe(1)
        ->and($page->lastPage())->toBe(3)
        ->and($page->total())->toBe(3)
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page)->toHaveCount(1)
        ->and($page->data[0])->toBeInstanceOf(Campaign::class);
});

it('reports no further pages on the last page', function (): void {
    Http::fake(['*' => Http::response(pageOf('campaigns-index/200', 3, 3, 13))]);

    $page = app(Client::class)->campaigns()->list();

    expect($page->hasMorePages())->toBeFalse()
        ->and($page->next())->toBeNull();
});

it('fetches the next page by number while preserving the original filters', function (): void {
    Http::fakeSequence()
        ->push(pageOf('campaigns-index/200', 1, 2, 11))
        ->push(pageOf('campaigns-index/200', 2, 2, 12));

    $next = app(Client::class)->campaigns()->list(['website_id' => 7])->next();

    expect($next?->currentPage())->toBe(2)
        ->and($next?->data[0]->id)->toBe(12);

    $urls = [];
    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    expect($urls[0])->toBe('https://app.lnkflow.test/api/v1/campaigns?website_id=7')
        ->and($urls[1])->toBe('https://app.lnkflow.test/api/v1/campaigns?website_id=7&page=2');
});

it('lazily walks every page and stops at the end', function (): void {
    Http::fakeSequence()
        ->push(pageOf('campaigns-index/200', 1, 3, 11))
        ->push(pageOf('campaigns-index/200', 2, 3, 12))
        ->push(pageOf('campaigns-index/200', 3, 3, 13));

    $ids = [];

    foreach (app(Client::class)->campaigns()->list()->each() as $campaign) {
        $ids[] = $campaign->id;
    }

    expect($ids)->toBe([11, 12, 13]);
    Http::assertSentCount(3);
});

it('does not fetch a page the caller never asked for', function (): void {
    Http::fake(['*' => Http::response(pageOf('campaigns-index/200', 1, 5, 11))]);

    $generator = app(Client::class)->campaigns()->list()->each();
    $generator->current();

    // One page in hand, four more available, and no second request yet.
    Http::assertSentCount(1);
});

it('walks a commission ledger across pages', function (): void {
    Http::fakeSequence()
        ->push(pageOf('influencer-commissions/200', 1, 2, 21))
        ->push(pageOf('influencer-commissions/200', 2, 2, 22));

    $rows = iterator_to_array(app(Client::class)->influencers()->commissions(1)->each());

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBeInstanceOf(Commission::class)
        ->and(array_map(fn (Commission $row): int => $row->id, $rows))->toBe([21, 22]);
});

it('treats a page with no resolver as terminal', function (): void {
    // A Page built by hand — as a host application's own fake would — must not
    // pretend it can fetch more.
    $page = new Page([1, 2], ['current_page' => 1, 'last_page' => 4]);

    expect($page->hasMorePages())->toBeTrue()
        ->and($page->next())->toBeNull()
        ->and(iterator_to_array($page->each()))->toBe([1, 2])
        ->and(iterator_to_array($page))->toBe([1, 2]);
});

it('keeps unknown pagination metadata reachable', function (): void {
    $body = pageOf('campaigns-index/200', 1, 1, 11);
    $body['meta']['cursor_after'] = 'opaque-cursor';
    $body['links']['seek'] = 'https://app.lnkflow.test/api/v1/campaigns?seek=1';
    Http::fake(['*' => Http::response($body)]);

    $page = app(Client::class)->campaigns()->list();

    expect($page->meta['cursor_after'])->toBe('opaque-cursor')
        ->and($page->links['seek'])->toBe('https://app.lnkflow.test/api/v1/campaigns?seek=1');
});
