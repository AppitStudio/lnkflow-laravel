<?php

declare(strict_types=1);

use LnkFlow\Laravel\Data\CreateLink;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Facades\LnkFlow;

it('provides a no-network fake with focused assertions', function (): void {
    LnkFlow::fake();

    $link = LnkFlow::client()->links()->create(
        8,
        new CreateLink('https://example.com', slug: 'launch'),
        'link:launch',
    );
    LnkFlow::trackSale(new Sale('invoice-100', 1299, 'USD'));

    expect($link->shortUrl)->toContain('fake.mylnk.click');

    LnkFlow::assertLinkCreated(
        fn (array $request): bool => $request['headers']['Idempotency-Key'] === 'link:launch',
    );
    LnkFlow::assertSaleTracked(
        fn (array $request): bool => $request['json']['amount'] === 1299
            && $request['json']['currency'] === 'usd',
    );
});
