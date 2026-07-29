<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Data\Visitor;
use LnkFlow\Laravel\Jobs\Concerns\ReportsApiFailures;
use LnkFlow\Laravel\Services\Client;

/** Withdraws consent. Separate from logout, and not undone by logging back in. */
final class RevokeVisitorJob implements ShouldQueue
{
    use Queueable;
    use ReportsApiFailures;

    public function __construct(
        public readonly string $visitorId,
        public readonly ?int $websiteId = null,
    ) {}

    public function handle(Client $client): void
    {
        $this->callApi(fn (): mixed => $client->journeys()->revoke(
            new Visitor($this->visitorId, $this->websiteId),
        ));
    }
}
