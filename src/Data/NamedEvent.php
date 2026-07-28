<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

final readonly class NamedEvent implements Payload
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public string $name,
        public string $customerExternalId,
        public array $context = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            ...$this->context,
            'event_name' => $this->name,
            'customer_external_id' => $this->customerExternalId,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
