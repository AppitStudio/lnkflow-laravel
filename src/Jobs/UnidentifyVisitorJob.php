<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Data\Visitor;
use LnkFlow\Laravel\Services\Client;

final class UnidentifyVisitorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $visitorId,
        public readonly ?int $websiteId = null,
    ) {}

    public function handle(Client $client): void
    {
        $client->journeys()->unidentify(new Visitor($this->visitorId, $this->websiteId));
    }
}
