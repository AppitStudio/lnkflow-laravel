<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

final readonly class CreateLink implements Payload
{
    /** @param array<string, string|null> $utm */
    public function __construct(
        public string $destinationUrl,
        public ?string $name = null,
        public ?string $slug = null,
        public array $utm = [],
        public ?int $customDomainId = null,
        public ?int $influencerId = null,
        public ?string $socialPlatform = null,
        public bool $active = true,
        public bool $conversionTrackingEnabled = false,
        public ?string $autoPromoCode = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'destination_url' => $this->destinationUrl,
            'name' => $this->name,
            'slug' => $this->slug,
            ...$this->utm,
            'custom_domain_id' => $this->customDomainId,
            'influencer_id' => $this->influencerId,
            'social_platform' => $this->socialPlatform,
            'is_active' => $this->active,
            'conversion_tracking_enabled' => $this->conversionTrackingEnabled,
            'auto_promo_code' => $this->autoPromoCode,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
