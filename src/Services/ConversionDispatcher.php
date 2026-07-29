<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Contracts\Payload;
use LnkFlow\Laravel\Data\EnrichedPayload;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Events\ConversionQueued;
use LnkFlow\Laravel\Jobs\SendConversionJob;

/**
 * Queues conversions after the host transaction commits.
 *
 * Nothing here performs a network call: a failed report must never take a
 * checkout down with it.
 */
final readonly class ConversionDispatcher
{
    public function __construct(private JourneyContext $context) {}

    public function event(NamedEvent $event): void
    {
        $this->dispatch('event', $this->enrich($event), $event->customerExternalId.':'.$event->name);
    }

    public function lead(Lead $lead): void
    {
        $this->dispatch('lead', $this->enrich($lead), $lead->customerExternalId.':'.$lead->eventName);
    }

    public function sale(Sale $sale): void
    {
        $this->dispatch('sale', $this->enrich($sale), $sale->invoiceId);
    }

    /**
     * Refunds carry no journey context: they attribute through the original
     * sale, so there is no reason to move visitor or click identifiers around.
     */
    public function refund(Refund $refund): void
    {
        $this->dispatch('refund', $refund, $refund->businessId());
    }

    private function enrich(Payload $payload): Payload
    {
        $context = $this->context->enrich([]);

        return $context === [] ? $payload : new EnrichedPayload($payload, $context);
    }

    private function dispatch(string $type, Payload $payload, string $businessId): void
    {
        $queue = config('lnkflow.conversions.queue');

        event(new ConversionQueued($type, $businessId));
        SendConversionJob::dispatch($type, $payload, $businessId)
            ->onQueue(is_string($queue) ? $queue : null)
            ->afterCommit();
    }
}
