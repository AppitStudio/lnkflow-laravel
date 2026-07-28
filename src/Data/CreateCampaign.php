<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

final readonly class CreateCampaign implements Payload
{
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public ?string $description = null,
        public ?int $websiteId = null,
        public bool $active = true,
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
