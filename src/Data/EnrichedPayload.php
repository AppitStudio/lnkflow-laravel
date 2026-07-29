<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

/**
 * A payload with journey context applied underneath it.
 *
 * Precedence runs journey context < the payload's own `context` escape hatch <
 * the payload's typed properties. That ordering matters: an explicitly passed
 * `click_id` must never be replaced by whatever happens to be in the session.
 */
final readonly class EnrichedPayload implements Payload
{
    /** @param array<string, mixed> $context */
    public function __construct(
        private Payload $payload,
        private array $context = [],
    ) {}

    public function inner(): Payload
    {
        return $this->payload;
    }

    public function toArray(): array
    {
        return [...$this->context, ...$this->payload->toArray()];
    }
}
