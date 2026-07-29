<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class UpdateInfluencer implements Payload
{
    /** @var list<string> */
    private const ALLOWED = [
        'name', 'slug', 'primary_platform', 'primary_handle', 'contact_email',
        'website_url', 'social_links', 'metadata', 'notes', 'is_active',
    ];

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        $unsupported = array_values(array_diff(array_keys($changes), self::ALLOWED));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Unsupported influencer update field(s) ['.implode(', ', $unsupported).'].',
            );
        }
    }

    public function toArray(): array
    {
        $changes = $this->changes;

        if (($changes['primary_platform'] ?? null) instanceof SocialPlatform) {
            $changes['primary_platform'] = $changes['primary_platform']->value;
        }

        return $changes;
    }
}
