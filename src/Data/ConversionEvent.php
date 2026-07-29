<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Http\ApiResponse;

/**
 * A recorded conversion event (lead, sale, or refund).
 *
 * `$attributionSource` records which signal won. LnkFlow's rule is that a promo
 * code beats a click: when both attribute the same sale, the source is `code`
 * and the code's influencer is credited.
 */
final readonly class ConversionEvent extends ApiObject
{
    public int $id;

    /** lead, sale, or refund. */
    public string $type;

    public ?string $eventName;

    public int $amountCents;

    public ?string $currency;

    /** link, code, or manual. */
    public ?string $attributionSource;

    public bool $test;

    public bool $suspectedBot;

    /** @var list<string> */
    public array $fraudFlags;

    public ?string $occurredAt;

    public ?int $linkId;

    public ?int $campaignId;

    public ?int $influencerId;

    /** @var array<string, mixed> */
    public array $journey;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw, ?ApiResponse $response = null)
    {
        parent::__construct($raw, $response);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->type = self::string($raw['type'] ?? null) ?? '';
        $this->eventName = self::string($raw['event_name'] ?? null);
        $this->amountCents = self::int($raw['amount_cents'] ?? null) ?? 0;
        $this->currency = self::string($raw['currency'] ?? null);
        $this->attributionSource = self::string($raw['attribution_source'] ?? null);
        $this->test = (bool) ($raw['is_test'] ?? false);
        $this->suspectedBot = (bool) ($raw['is_suspected_bot'] ?? false);
        $this->fraudFlags = array_values(array_filter(
            is_array($raw['fraud_flags'] ?? null) ? $raw['fraud_flags'] : [],
            is_string(...),
        ));
        $this->occurredAt = self::string($raw['occurred_at'] ?? null);
        $link = self::map($raw['link'] ?? null);
        $this->linkId = self::int($link['id'] ?? null);
        $campaign = self::map($raw['campaign'] ?? null);
        $this->campaignId = self::int($campaign['id'] ?? null);
        $influencer = self::map($raw['influencer'] ?? null);
        $this->influencerId = self::int($influencer['id'] ?? null);
        $this->journey = self::map($raw['journey'] ?? null);
    }
}
