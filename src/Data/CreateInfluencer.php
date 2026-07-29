<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

/**
 * A new influencer (creator/affiliate).
 *
 * `$socialLinks` maps a platform key to that creator's profile URL, e.g.
 * `['instagram' => 'https://instagram.com/handle']`. `$websiteUrl` is reserved
 * for a separate creator-owned website — do not put a social profile there.
 */
final readonly class CreateInfluencer implements Payload
{
    /**
     * @param  array<string, string>  $socialLinks
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public ?string $slug = null,
        public SocialPlatform|string|null $primaryPlatform = null,
        public ?string $primaryHandle = null,
        public ?string $contactEmail = null,
        public ?string $websiteUrl = null,
        public array $socialLinks = [],
        public array $metadata = [],
        public ?string $notes = null,
        public ?bool $active = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'slug' => $this->slug,
            'primary_platform' => $this->primaryPlatform instanceof SocialPlatform
                ? $this->primaryPlatform->value
                : $this->primaryPlatform,
            'primary_handle' => $this->primaryHandle,
            'contact_email' => $this->contactEmail,
            'website_url' => $this->websiteUrl,
            'social_links' => $this->socialLinks === [] ? null : $this->socialLinks,
            'metadata' => $this->metadata === [] ? null : $this->metadata,
            'notes' => $this->notes,
            'is_active' => $this->active,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
