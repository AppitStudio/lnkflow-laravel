<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use LnkFlow\Laravel\Data\Consent;
use LnkFlow\Laravel\Data\Touchpoint;
use LnkFlow\Laravel\Jobs\Concerns\ReportsApiFailures;
use LnkFlow\Laravel\Services\Client;

/**
 * Records that a visitor arrived from a tracked click.
 *
 * Carries only the opaque visitor and click identifiers the operation needs —
 * no request, no user, no payload.
 */
final class CaptureTouchpointJob implements ShouldQueue
{
    use Queueable;
    use ReportsApiFailures;

    /** @param array<string, mixed> $consent */
    public function __construct(
        public readonly string $visitorId,
        public readonly string $clickId,
        public readonly ?int $websiteId,
        public readonly array $consent,
    ) {}

    public function handle(Client $client): void
    {
        $this->callApi(fn (): mixed => $client->journeys()->capture(new Touchpoint(
            visitorId: $this->visitorId,
            clickId: $this->clickId,
            consent: Consent::fromArray($this->consent),
            websiteId: $this->websiteId,
        )));
    }
}
