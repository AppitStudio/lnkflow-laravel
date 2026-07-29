<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

/**
 * A new campaign — the container links are created under.
 *
 * `$slug` is the campaign's own slug, sent as `campaign_slug`. It is not the
 * short-link slug; that belongs to the link.
 */
final readonly class CreateCampaign implements Payload
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?int $websiteId = null,
        public ?bool $active = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'campaign_slug' => $this->slug,
            'description' => $this->description,
            'website_id' => $this->websiteId,
            'is_active' => $this->active,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
