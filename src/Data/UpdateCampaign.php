<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class UpdateCampaign implements Payload
{
    private const ALLOWED = [
        'name', 'title', 'slug', 'description', 'default_destination_url',
        'default_custom_domain_id', 'starts_at', 'ends_at', 'website_id', 'is_active',
    ];

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        if (array_diff(array_keys($changes), self::ALLOWED) !== []) {
            throw new InvalidArgumentException('Unsupported campaign update field.');
        }
    }

    public function toArray(): array
    {
        return $this->changes;
    }
}
