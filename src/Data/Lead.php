<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

final readonly class Lead implements Payload
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $customerExternalId,
        public string $eventName = 'lead',
        public array $context = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            ...$this->context,
            'customer_external_id' => $this->customerExternalId,
            'event_name' => $this->eventName,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
