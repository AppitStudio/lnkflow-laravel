<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Http\ApiResponse;

/**
 * A campaign link — the shareable, tracked redirect unit.
 *
 * `$shortUrl` is the canonical URL to share. `$edgeStatus` is asynchronous
 * publication state: a successful write does not mean the edge has the link
 * yet, so do not treat anything other than the API's own success as failure.
 */
final readonly class Link extends ApiObject
{
    public int $id;

    public int $campaignId;

    public ?string $campaignName;

    public string $name;

    public string $slug;

    public string $shortUrl;

    public ?string $defaultShortUrl;

    public ?string $customDomainUrl;

    public string $edgeStatus;

    public ?string $edgePublishedAt;

    public ?string $edgePublishFailedAt;

    public ?int $customDomainId;

    public ?string $customDomain;

    public ?string $socialPlatform;

    public ?string $destinationUrl;

    public ?string $destinationUrlWithUtm;

    /** @var array<string, mixed> */
    public array $utmParameters;

    public ?int $influencerId;

    public ?string $influencerName;

    public bool $active;

    public bool $conversionTrackingEnabled;

    public ?string $autoPromoCode;

    public int $totalClicks;

    public ?string $createdAt;

    public ?string $updatedAt;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw, ?ApiResponse $response = null)
    {
        parent::__construct($raw, $response);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->campaignId = self::int($raw['campaign_id'] ?? null) ?? 0;
        $campaign = self::map($raw['campaign'] ?? null);
        $this->campaignName = self::string($campaign['name'] ?? null);
        $this->name = self::string($raw['name'] ?? null) ?? '';
        $this->slug = self::string($raw['slug'] ?? null) ?? '';
        $this->shortUrl = self::string($raw['short_url'] ?? null) ?? '';
        $this->defaultShortUrl = self::string($raw['default_short_url'] ?? null);
        $this->customDomainUrl = self::string($raw['custom_domain_url'] ?? null);
        $edgeStatus = $raw['edge_status'] ?? null;
        $this->edgeStatus = is_string($edgeStatus) ? $edgeStatus : 'unknown';
        $this->edgePublishedAt = self::string($raw['edge_published_at'] ?? null);
        $this->edgePublishFailedAt = self::string($raw['edge_publish_failed_at'] ?? null);
        $domain = self::map($raw['custom_domain'] ?? null);
        $this->customDomainId = self::int($domain['id'] ?? null);
        $this->customDomain = self::string($domain['domain'] ?? null);
        $this->socialPlatform = self::string($raw['social_platform'] ?? null);
        $this->destinationUrl = self::string($raw['destination_url'] ?? null);
        $this->destinationUrlWithUtm = self::string($raw['destination_url_with_utm'] ?? null);
        $this->utmParameters = self::map($raw['utm_parameters'] ?? null);
        $influencer = self::map($raw['influencer'] ?? null);
        $this->influencerId = self::int($influencer['id'] ?? null);
        $this->influencerName = self::string($influencer['name'] ?? null);
        $this->active = (bool) ($raw['is_active'] ?? false);
        $this->conversionTrackingEnabled = (bool) ($raw['conversion_tracking_enabled'] ?? false);
        $this->autoPromoCode = self::string($raw['auto_promo_code'] ?? null);
        $this->totalClicks = self::int($raw['total_clicks'] ?? null) ?? 0;
        $this->createdAt = self::string($raw['created_at'] ?? null);
        $this->updatedAt = self::string($raw['updated_at'] ?? null);
    }

    /** Whether the link has been published to the redirect edge. */
    public function published(): bool
    {
        return $this->edgeStatus === 'published';
    }
}
