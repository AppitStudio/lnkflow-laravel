<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class LinkDefinition
{
    /** @param array<string, string|null> $utm */
    public function __construct(
        public string $placement,
        public string $campaignKey,
        public string $campaignName,
        public string $destinationUrl,
        public ?string $name = null,
        public ?string $slug = null,
        public array $utm = [],
        public ?int $websiteId = null,
        public ?int $customDomainId = null,
        public ?int $influencerId = null,
        public ?string $socialPlatform = null,
        public bool $active = true,
        public bool $conversionTrackingEnabled = false,
        public ?string $autoPromoCode = null,
    ) {}

    public function createLink(): CreateLink
    {
        return new CreateLink(
            destinationUrl: $this->destinationUrl,
            name: $this->name,
            slug: $this->slug,
            utm: $this->utm,
            customDomainId: $this->customDomainId,
            influencerId: $this->influencerId,
            socialPlatform: $this->socialPlatform,
            active: $this->active,
            conversionTrackingEnabled: $this->conversionTrackingEnabled,
            autoPromoCode: $this->autoPromoCode,
        );
    }
}
