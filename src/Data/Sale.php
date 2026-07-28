<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class Sale implements Payload
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $invoiceId,
        public int $amount,
        public string $currency,
        public ?string $customerExternalId = null,
        public array $context = [],
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Sale amount must be integer minor units greater than or equal to zero.');
        }
    }

    public function toArray(): array
    {
        return array_filter([
            ...$this->context,
            'invoice_id' => $this->invoiceId,
            'amount' => $this->amount,
            'currency' => mb_strtolower($this->currency),
            'customer_external_id' => $this->customerExternalId,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
