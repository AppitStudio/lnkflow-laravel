<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeInterface;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A named non-monetary conversion. Reported through the lead endpoint with an
 * explicit `event_name`, which is how LnkFlow models custom events.
 */
final readonly class NamedEvent implements Payload
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $name,
        public string $customerExternalId,
        public ?string $clickId = null,
        public ?string $visitorId = null,
        public ?string $firstClickId = null,
        public ?string $lastClickId = null,
        public ?int $websiteId = null,
        public ?Consent $consent = null,
        public ?array $metadata = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?bool $test = null,
        public array $context = [],
    ) {}

    public function toArray(): array
    {
        return (new Lead(
            customerExternalId: $this->customerExternalId,
            eventName: $this->name,
            clickId: $this->clickId,
            visitorId: $this->visitorId,
            firstClickId: $this->firstClickId,
            lastClickId: $this->lastClickId,
            websiteId: $this->websiteId,
            consent: $this->consent,
            metadata: $this->metadata,
            occurredAt: $this->occurredAt,
            test: $this->test,
            context: $this->context,
        ))->toArray();
    }
}
