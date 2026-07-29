<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A partial campaign-link update.
 *
 * Changing `slug` changes the live short URL: anything already shared stops
 * resolving. Prefer creating a new link over renaming a published one.
 */
final readonly class UpdateLink implements Payload
{
    /** @var list<string> */
    private const ALLOWED = [
        'destination_url', 'name', 'title', 'slug', 'custom_domain_id',
        'influencer_id', 'social_platform', 'is_active',
        'conversion_tracking_enabled', 'auto_promo_code',
        ...Utm::KEYS,
    ];

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        $unsupported = array_values(array_diff(array_keys($changes), self::ALLOWED));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Unsupported link update field(s) ['.implode(', ', $unsupported).'].',
            );
        }
    }

    public function toArray(): array
    {
        return $this->changes;
    }
}
