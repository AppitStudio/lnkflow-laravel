<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeImmutable;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class Touchpoint implements Payload
{
    /** @param array<string, mixed> $consent */
    public function __construct(
        public string $visitorId,
        public string $clickId,
        public array $consent,
        public ?int $websiteId = null,
        public ?DateTimeImmutable $capturedAt = null,
        public ?string $captureMethod = 'backend',
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'website_id' => $this->websiteId,
            'visitor_id' => $this->visitorId,
            'click_id' => $this->clickId,
            'captured_at' => $this->capturedAt?->format(DATE_ATOM),
            'capture_method' => $this->captureMethod,
            'consent' => $this->consent,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
