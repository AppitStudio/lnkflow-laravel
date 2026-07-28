<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeImmutable;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class Visitor implements Payload
{
    public function __construct(
        public string $visitorId,
        public ?int $websiteId = null,
        public ?DateTimeImmutable $occurredAt = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'website_id' => $this->websiteId,
            'visitor_id' => $this->visitorId,
            'occurred_at' => $this->occurredAt?->format(DATE_ATOM),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
