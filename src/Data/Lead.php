<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeInterface;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A lead — any named, non-monetary conversion.
 *
 * Attribution identifiers are normally filled in for you from the journey
 * session; pass them explicitly only when you are reporting from a context that
 * has no session (a webhook, a console command, a background import).
 *
 * `$customerEmail` and `$customerName` are deliberately opt-in. LnkFlow does
 * not need them to attribute a conversion, and sending them makes the payload
 * personal data — do not set them without a lawful basis.
 */
final readonly class Lead implements Payload
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $context  escape hatch for fields this SDK version does not type yet
     */
    public function __construct(
        public string $customerExternalId,
        public string $eventName = 'lead',
        public ?string $clickId = null,
        public ?string $visitorId = null,
        public ?string $firstClickId = null,
        public ?string $lastClickId = null,
        public ?int $websiteId = null,
        public ?Consent $consent = null,
        public ?array $metadata = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?bool $test = null,
        public ?string $customerEmail = null,
        public ?string $customerName = null,
        public ?string $metaEventId = null,
        public array $context = [],
    ) {}

    public function toArray(): array
    {
        $typed = array_filter([
            'customer_external_id' => $this->customerExternalId,
            'event_name' => $this->eventName,
            'click_id' => $this->clickId,
            'visitor_id' => $this->visitorId,
            'first_click_id' => $this->firstClickId,
            'last_click_id' => $this->lastClickId,
            'website_id' => $this->websiteId,
            'consent' => $this->consent?->toArray(),
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt?->format(DATE_ATOM),
            'test' => $this->test,
            'customer_email' => $this->customerEmail,
            'customer_name' => $this->customerName,
            'provider_event_ids' => $this->metaEventId === null ? null : ['meta' => $this->metaEventId],
        ], static fn (mixed $value): bool => $value !== null);

        return [...$this->context, ...$typed];
    }
}
