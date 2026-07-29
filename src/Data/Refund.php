<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeInterface;
use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A refund against a previously reported sale.
 *
 * Leave `$amount` null for a full refund: the API then reverses the original
 * sale's amount for you, so you never have to re-derive it. Set it only for a
 * partial refund.
 *
 * Leave `$refundId` null for that same full-refund case — the server derives a
 * single stable reference, so a retry is a duplicate rather than a second
 * clawback. Partial or repeated refunds against one sale each need their own
 * distinct `$refundId`.
 *
 * The refund endpoint takes no currency: the original sale's currency applies.
 */
final readonly class Refund implements Payload
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $invoiceId,
        public ?string $refundId = null,
        public ?int $amount = null,
        public ?string $eventName = null,
        public ?string $paymentProcessor = null,
        public ?array $metadata = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?bool $test = null,
        public array $context = [],
    ) {
        if ($amount !== null && $amount < 1) {
            throw new InvalidArgumentException(
                'Refund amount must be positive integer minor units, or null for a full refund.',
            );
        }

        if ($refundId !== null && $refundId === $invoiceId) {
            throw new InvalidArgumentException(
                'Refund id must differ from the original invoice id; conversions share one reference space.',
            );
        }
    }

    /** The identifier used for retry-safe dispatch of this refund. */
    public function businessId(): string
    {
        return $this->refundId ?? $this->invoiceId.':refund';
    }

    public function toArray(): array
    {
        $typed = array_filter([
            'original_invoice_id' => $this->invoiceId,
            'refund_id' => $this->refundId,
            'amount' => $this->amount,
            'event_name' => $this->eventName,
            'payment_processor' => $this->paymentProcessor,
            'metadata' => $this->metadata,
            'occurred_at' => $this->occurredAt?->format(DATE_ATOM),
            'test' => $this->test,
        ], static fn (mixed $value): bool => $value !== null);

        return [...$this->context, ...$typed];
    }
}
