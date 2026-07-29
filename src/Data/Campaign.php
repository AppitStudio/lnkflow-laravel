<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Http\ApiResponse;

/**
 * A LnkFlow campaign.
 *
 * Note the two slugs. The API's `slug` field is the *primary link's* slug (kept
 * for the legacy single-link payload shape); the campaign's own slug is
 * `campaign_slug`. `Campaign::$slug` therefore reads `campaign_slug`, and the
 * link slug is exposed separately as {@see $primaryLinkSlug}.
 */
final readonly class Campaign extends ApiObject
{
    public int $id;

    public string $name;

    /** The campaign's own slug (API field `campaign_slug`). */
    public string $slug;

    /** The primary link's slug (API field `slug`), null when the campaign has no links yet. */
    public ?string $primaryLinkSlug;

    public ?string $description;

    public ?string $defaultDestinationUrl;

    public ?int $defaultCustomDomainId;

    public ?string $shortUrl;

    public ?string $defaultShortUrl;

    public ?string $customDomainUrl;

    public ?string $edgeStatus;

    public ?string $edgePublishedAt;

    public ?string $destinationUrl;

    public ?string $destinationUrlWithUtm;

    /** @var array<string, mixed> */
    public array $utmParameters;

    public ?int $websiteId;

    public ?string $websiteName;

    public ?int $influencerId;

    public ?string $influencerName;

    public ?string $socialPlatform;

    public bool $active;

    public int $linksCount;

    public int $activeLinksCount;

    public int $totalClicks;

    public ?Link $primaryLink;

    /** @var list<Link> */
    public array $links;

    public ?string $startsAt;

    public ?string $endsAt;

    public ?string $createdAt;

    public ?string $updatedAt;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw, ?ApiResponse $response = null)
    {
        parent::__construct($raw, $response);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->name = self::string($raw['name'] ?? null) ?? '';
        $this->slug = self::string($raw['campaign_slug'] ?? null) ?? '';
        $this->primaryLinkSlug = self::string($raw['slug'] ?? null);
        $this->description = self::string($raw['description'] ?? null);
        $this->defaultDestinationUrl = self::string($raw['default_destination_url'] ?? null);
        $this->defaultCustomDomainId = self::int($raw['default_custom_domain_id'] ?? null);
        $this->shortUrl = self::string($raw['short_url'] ?? null);
        $this->defaultShortUrl = self::string($raw['default_short_url'] ?? null);
        $this->customDomainUrl = self::string($raw['custom_domain_url'] ?? null);
        $this->edgeStatus = self::string($raw['edge_status'] ?? null);
        $this->edgePublishedAt = self::string($raw['edge_published_at'] ?? null);
        $this->destinationUrl = self::string($raw['destination_url'] ?? null);
        $this->destinationUrlWithUtm = self::string($raw['destination_url_with_utm'] ?? null);
        $this->utmParameters = self::map($raw['utm_parameters'] ?? null);
        $website = self::map($raw['website'] ?? null);
        $this->websiteId = self::int($website['id'] ?? null);
        $this->websiteName = self::string($website['name'] ?? null);
        $influencer = self::map($raw['influencer'] ?? null);
        $this->influencerId = self::int($influencer['id'] ?? null);
        $this->influencerName = self::string($influencer['name'] ?? null);
        $this->socialPlatform = self::string($raw['social_platform'] ?? null);
        $this->active = (bool) ($raw['is_active'] ?? false);
        $this->linksCount = self::int($raw['links_count'] ?? null) ?? 0;
        $this->activeLinksCount = self::int($raw['active_links_count'] ?? null) ?? 0;
        $this->totalClicks = self::int($raw['total_clicks'] ?? null) ?? 0;
        $primaryLink = $raw['primary_link'] ?? null;
        $this->primaryLink = is_array($primaryLink) ? new Link(self::map($primaryLink)) : null;
        $this->links = array_map(
            static fn (array $link): Link => new Link($link),
            self::rows($raw['links'] ?? null),
        );
        $this->startsAt = self::string($raw['starts_at'] ?? null);
        $this->endsAt = self::string($raw['ends_at'] ?? null);
        $this->createdAt = self::string($raw['created_at'] ?? null);
        $this->updatedAt = self::string($raw['updated_at'] ?? null);
    }
}
