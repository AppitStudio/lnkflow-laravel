<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Resource;
use LnkFlow\Laravel\Data\Sale;

final class ConversionsClient extends AbstractClient
{
    public function event(NamedEvent $event): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'track/lead',
            json: $event->toArray(),
            stableBusinessKey: $event->customerExternalId.':'.$event->name,
        )));
    }

    public function lead(Lead $lead): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'track/lead',
            json: $lead->toArray(),
            stableBusinessKey: $lead->customerExternalId.':'.$lead->eventName,
        )));
    }

    public function sale(Sale $sale): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'track/sale',
            json: $sale->toArray(),
            stableBusinessKey: $sale->invoiceId,
        )));
    }

    public function refund(Refund $refund): Resource
    {
        return new Resource($this->data($this->transport->send(
            'POST',
            'track/refund',
            json: $refund->toArray(),
            stableBusinessKey: $refund->refundId,
        )));
    }

    /**
     * @param  array<string, scalar|null>  $filters
     * @return list<resource>
     */
    public function events(array $filters = []): array
    {
        return array_map(
            fn (array $item): Resource => new Resource($item),
            $this->collection($this->transport->send('GET', 'track/events', $filters)),
        );
    }

    public function journey(int $eventId): Resource
    {
        return new Resource($this->data($this->transport->send(
            'GET',
            "track/events/{$eventId}/journey",
        )));
    }
}
