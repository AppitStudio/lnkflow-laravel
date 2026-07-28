<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Contracts\Payload;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Events\ConversionQueued;
use LnkFlow\Laravel\Jobs\SendConversionJob;

final readonly class ConversionDispatcher
{
    public function __construct(private JourneyContext $context) {}

    public function event(NamedEvent $event): void
    {
        $enriched = new NamedEvent(
            $event->name,
            $event->customerExternalId,
            $this->context->enrich($event->context),
        );
        $this->dispatch('event', $enriched, $event->customerExternalId.':'.$event->name);
    }

    public function lead(Lead $lead): void
    {
        $enriched = new Lead(
            $lead->customerExternalId,
            $lead->eventName,
            $this->context->enrich($lead->context),
        );
        $this->dispatch('lead', $enriched, $lead->customerExternalId.':'.$lead->eventName);
    }

    public function sale(Sale $sale): void
    {
        $enriched = new Sale(
            $sale->invoiceId,
            $sale->amount,
            $sale->currency,
            $sale->customerExternalId,
            $this->context->enrich($sale->context),
        );
        $this->dispatch('sale', $enriched, $sale->invoiceId);
    }

    public function refund(Refund $refund): void
    {
        $enriched = new Refund(
            $refund->invoiceId,
            $refund->refundId,
            $refund->amount,
            $refund->currency,
            $this->context->enrich($refund->context),
        );
        $this->dispatch('refund', $enriched, $refund->refundId);
    }

    private function dispatch(string $type, Payload $payload, string $businessId): void
    {
        event(new ConversionQueued($type, $businessId));
        SendConversionJob::dispatch($type, $payload, $businessId)
            ->onQueue(config('lnkflow.conversions.queue'))
            ->afterCommit();
    }
}
