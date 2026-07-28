<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Services\Client;

final class CaptureTouchpointJob implements ShouldQueue
{
    use Queueable;

    /** @param array<string, mixed> $consent */
    public function __construct(
        public readonly string $visitorId,
        public readonly string $clickId,
        public readonly ?int $websiteId,
        public readonly array $consent,
    ) {}

    public function handle(Client $client): void
    {
        $client->journeys()->capture(new Touchpoint(
            visitorId: $this->visitorId,
            clickId: $this->clickId,
            consent: $this->consent,
            websiteId: $this->websiteId,
        ));
    }
}
