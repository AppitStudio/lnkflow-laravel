<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use DateTimeInterface;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A recorded arrival from a tracked click.
 *
 * Capture requires granted storage consent. Denied or unknown consent means
 * nothing is stored locally and nothing is sent.
 */
final readonly class Touchpoint implements Payload
{
    public function __construct(
        public string $visitorId,
        public string $clickId,
        public Consent $consent,
        public ?int $websiteId = null,
        public ?DateTimeInterface $capturedAt = null,
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
            'consent' => $this->consent->toArray(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
