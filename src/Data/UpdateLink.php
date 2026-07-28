<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class UpdateLink implements Payload
{
    private const ALLOWED = [
        'destination_url', 'name', 'title', 'slug', 'custom_domain_id',
        'influencer_id', 'social_platform', 'is_active',
        'conversion_tracking_enabled', 'auto_promo_code',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        if (array_diff(array_keys($changes), self::ALLOWED) !== []) {
            throw new InvalidArgumentException('Unsupported link update field.');
        }
    }

    public function toArray(): array
    {
        return $this->changes;
    }
}
