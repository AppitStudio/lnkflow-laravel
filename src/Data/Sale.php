<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeInterface;
use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A sale.
 *
 * `$amount` is integer minor currency units — cents, not dollars. Floating
 * point money is rejected by the type, which is the point.
 *
 * `$invoiceId` is the idempotency anchor: reporting the same invoice twice
 * records one sale, so retries are safe.
 *
 * `$metaEventId` is the Meta CAPI deduplication id. It must be the same id the
 * browser Pixel sent for this purchase, otherwise Meta counts the conversion
 * twice. Leave it null unless you are genuinely mirroring a Pixel event.
 */
final readonly class Sale implements Payload
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $invoiceId,
        public int $amount,
        public string $currency,
        public ?string $customerExternalId = null,
        public ?string $eventName = null,
        public ?string $clickId = null,
        public ?string $visitorId = null,
        public ?string $firstClickId = null,
        public ?string $lastClickId = null,
        public ?int $websiteId = null,
        public ?string $paymentProcessor = null,
        public ?string $promoCode = null,
        public ?Consent $consent = null,
        public ?array $metadata = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?bool $test = null,
        public ?string $metaEventId = null,
        public array $context = [],
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Sale amount must be integer minor units greater than or equal to zero.');
        }
    }

    public function toArray(): array
    {
        $typed = array_filter([
            'invoice_id' => $this->invoiceId,
            'amount' => $this->amount,
            'currency' => mb_strtolower($this->currency),
            'customer_external_id' => $this->customerExternalId,
            'event_name' => $this->eventName,
            'click_id' => $this->clickId,
            'visitor_id' => $this->visitorId,
            'first_click_id' => $this->firstClickId,
            'last_click_id' => $this->lastClickId,
            'website_id' => $this->websiteId,
            'payment_processor' => $this->paymentProcessor,
            'promo_code' => $this->promoCode,
            'consent' => $this->consent?->toArray(),
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt?->format(DATE_ATOM),
            'test' => $this->test,
            'provider_event_ids' => $this->metaEventId === null ? null : ['meta' => $this->metaEventId],
        ], static fn (mixed $value): bool => $value !== null);

        return [...$this->context, ...$typed];
    }
}
