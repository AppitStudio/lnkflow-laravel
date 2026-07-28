<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class Refund implements Payload
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $invoiceId,
        public string $refundId,
        public int $amount,
        public string $currency,
        public array $context = [],
    ) {
        if ($amount < 1) {
            throw new InvalidArgumentException('Refund amount must be positive integer minor units.');
        }
    }

    public function toArray(): array
    {
        return [
            ...$this->context,
            'original_invoice_id' => $this->invoiceId,
            'refund_id' => $this->refundId,
            'amount' => $this->amount,
            'currency' => mb_strtolower($this->currency),
        ];
    }
}
