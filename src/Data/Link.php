<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class Link extends ApiObject
{
    public int $id;

    public int $campaignId;

    public string $slug;

    public string $shortUrl;

    public string $edgeStatus;

    public bool $conversionTrackingEnabled;

    public ?string $autoPromoCode;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = (int) ($raw['id'] ?? 0);
        $this->campaignId = (int) ($raw['campaign_id'] ?? 0);
        $this->slug = (string) ($raw['slug'] ?? '');
        $this->shortUrl = (string) ($raw['short_url'] ?? '');
        $this->edgeStatus = (string) ($raw['edge_status'] ?? 'unknown');
        $this->conversionTrackingEnabled = (bool) ($raw['conversion_tracking_enabled'] ?? false);
        $this->autoPromoCode = is_string($raw['auto_promo_code'] ?? null)
            ? $raw['auto_promo_code']
            : null;
    }
}
