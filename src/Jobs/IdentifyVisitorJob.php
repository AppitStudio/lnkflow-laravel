<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Data\IdentityChange;
use LnkFlow\Laravel\Services\Client;

final class IdentifyVisitorJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $visitorId,
        public readonly string $customerExternalId,
        public readonly ?int $websiteId = null,
    ) {}

    public function handle(Client $client): void
    {
        $client->journeys()->identify(new IdentityChange(
            $this->visitorId,
            $this->customerExternalId,
            $this->websiteId,
        ));
    }
}
