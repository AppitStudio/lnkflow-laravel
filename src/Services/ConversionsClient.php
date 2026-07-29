<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;
use LnkFlow\Laravel\Data\ConversionEvent;
use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\NamedEvent;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Resource;
use LnkFlow\Laravel\Data\Sale;

final class ConversionsClient extends AbstractClient
{
    /** A named non-monetary conversion. Reported through the lead endpoint. */
    public function event(NamedEvent $event): ConversionEvent
    {
        return $this->post('track/lead', $event->toArray(), $event->customerExternalId.':'.$event->name);
    }

    public function lead(Lead $lead): ConversionEvent
    {
        return $this->post('track/lead', $lead->toArray(), $lead->customerExternalId.':'.$lead->eventName);
    }

    public function sale(Sale $sale): ConversionEvent
    {
        return $this->post('track/sale', $sale->toArray(), $sale->invoiceId);
    }

    /**
     * A refund. The referenced sale must already exist, otherwise the API
     * rejects it with a 422 rather than recording an unattributed refund.
     */
    public function refund(Refund $refund): ConversionEvent
    {
        return $this->post('track/refund', $refund->toArray(), $refund->businessId());
    }

    /**
     * The recorded conversion feed. Readable with any valid token — this is the
     * verification loop for a new integration.
     *
     * @param  array<string, scalar|null>  $filters
     * @return list<ConversionEvent>
     */
    public function events(array $filters = []): array
    {
        return array_map(
            static fn (array $item): ConversionEvent => new ConversionEvent($item),
            $this->transport->send('GET', 'track/events', $filters)->collection(),
        );
    }

    /** The attribution journey behind one conversion event. */
    public function journey(int $eventId): Resource
    {
        return new Resource($this->transport->send('GET', "track/events/{$eventId}/journey")->data());
    }

    /**
     * Report an already-built payload of a known type.
     *
     * This is the queued path: the job holds a payload it must not rebuild
     * (rebuilding would drop the journey context applied at dispatch time), so
     * it passes the payload through as-is together with the stable business
     * identifier the retry contract depends on.
     */
    public function send(string $type, Payload $payload, string $businessId): ConversionEvent
    {
        return $this->post(match ($type) {
            'sale' => 'track/sale',
            'refund' => 'track/refund',
            'lead', 'event' => 'track/lead',
            default => throw new InvalidArgumentException("Unsupported LnkFlow conversion type [{$type}]."),
        }, $payload->toArray(), $businessId);
    }

    /** @param array<string, mixed> $json */
    private function post(string $path, array $json, string $stableBusinessKey): ConversionEvent
    {
        $response = $this->transport->send('POST', $path, json: $json, stableBusinessKey: $stableBusinessKey);

        return new ConversionEvent($response->data(), $response);
    }
}
