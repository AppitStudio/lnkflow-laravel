<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeImmutable;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class IdentityChange implements Payload
{
    public function __construct(
        public string $visitorId,
        public string $customerExternalId,
        public ?int $websiteId = null,
        public ?DateTimeImmutable $boundAt = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'website_id' => $this->websiteId,
            'visitor_id' => $this->visitorId,
            'customer_external_id' => $this->customerExternalId,
            'bound_at' => $this->boundAt?->format(DATE_ATOM),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
