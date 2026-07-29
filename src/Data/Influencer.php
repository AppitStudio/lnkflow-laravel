<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class Influencer extends ApiObject
{
    public int $id;

    public string $name;

    public ?string $slug;

    public ?string $primaryPlatform;

    public ?string $primaryHandle;

    public ?string $displayHandle;

    public ?string $contactEmail;

    public ?string $websiteUrl;

    /** @var array<string, string> */
    public array $socialLinks;

    /** @var array<string, mixed> */
    public array $metadata;

    public ?string $notes;

    public bool $active;

    public int $campaignsCount;

    public int $linksCount;

    public int $activeLinksCount;

    public int $totalClicks;

    /** Present only when the endpoint eager-loaded conversion aggregates. */
    public ?int $salesCount;

    /** Present only when the endpoint eager-loaded conversion aggregates. */
    public ?int $totalRevenueCents;

    public ?string $createdAt;

    public ?string $updatedAt;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->name = self::string($raw['name'] ?? null) ?? '';
        $this->slug = self::string($raw['slug'] ?? null);
        $this->primaryPlatform = self::string($raw['primary_platform'] ?? null);
        $this->primaryHandle = self::string($raw['primary_handle'] ?? null);
        $this->displayHandle = self::string($raw['display_handle'] ?? null);
        $this->contactEmail = self::string($raw['contact_email'] ?? null);
        $this->websiteUrl = self::string($raw['website_url'] ?? null);
        $socialLinks = [];

        foreach (self::map($raw['social_links'] ?? null) as $platform => $url) {
            if (is_string($url)) {
                $socialLinks[$platform] = $url;
            }
        }

        $this->socialLinks = $socialLinks;
        $this->metadata = self::map($raw['metadata'] ?? null);
        $this->notes = self::string($raw['notes'] ?? null);
        $this->active = (bool) ($raw['is_active'] ?? false);
        $this->campaignsCount = self::int($raw['campaigns_count'] ?? null) ?? 0;
        $this->linksCount = self::int($raw['links_count'] ?? null) ?? 0;
        $this->activeLinksCount = self::int($raw['active_links_count'] ?? null) ?? 0;
        $this->totalClicks = self::int($raw['total_clicks'] ?? null) ?? 0;
        $this->salesCount = self::int($raw['sales_count'] ?? null);
        $this->totalRevenueCents = self::int($raw['total_revenue_cents'] ?? null);
        $this->createdAt = self::string($raw['created_at'] ?? null);
        $this->updatedAt = self::string($raw['updated_at'] ?? null);
    }
}
