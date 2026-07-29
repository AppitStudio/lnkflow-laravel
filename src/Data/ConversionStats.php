<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * The conversion analytics bundle from `GET /stats/conversions`.
 *
 * Read {@see $hasConversionData} before rendering anything. It is the canonical
 * flag for "does this team have real conversion data"; when it is false every
 * number below is a structural zero, not a measured zero, and presenting it as
 * revenue would be wrong.
 */
final readonly class ConversionStats extends ApiObject
{
    public bool $hasConversionData;

    public int $clicks;

    public int $leads;

    public int $sales;

    /** Refund-adjusted revenue, in integer minor currency units. */
    public int $revenueCents;

    public float $clickToLeadRate;

    public float $leadToSaleRate;

    public float $clickToSaleRate;

    /** @var list<array<string, mixed>> */
    public array $series;

    public int $linkAttributed;

    public int $codeAttributed;

    public int $manualAttributed;

    public float $codeSharePercent;

    /** @var array<string, mixed> */
    public array $funnel;

    /** @var array<string, mixed> */
    public array $sourceSplit;

    /** @var array<string, mixed> */
    public array $journey;

    /** @var array<string, mixed> */
    public array $meta;

    /**
     * @param  array<string, mixed>  $raw  the `data` envelope
     * @param  array<string, mixed>  $meta  the sibling `meta` envelope
     */
    public function __construct(array $raw, array $meta = [])
    {
        parent::__construct($raw);
        $this->meta = $meta;
        $this->hasConversionData = (bool) ($raw['has_conversion_data'] ?? false);
        $this->funnel = self::map($raw['funnel'] ?? null);
        $this->sourceSplit = self::map($raw['source_split'] ?? null);
        $this->journey = self::map($raw['journey'] ?? null);
        $this->series = self::rows($raw['series'] ?? null);

        $this->clicks = self::int($this->funnel['clicks'] ?? null) ?? 0;
        $this->leads = self::int($this->funnel['leads'] ?? null) ?? 0;
        $this->sales = self::int($this->funnel['sales'] ?? null) ?? 0;
        $this->revenueCents = self::int($this->funnel['revenue_cents'] ?? null) ?? 0;

        $rates = self::map($this->funnel['rates'] ?? null);
        $this->clickToLeadRate = self::rate($rates['click_to_lead'] ?? null);
        $this->leadToSaleRate = self::rate($rates['lead_to_sale'] ?? null);
        $this->clickToSaleRate = self::rate($rates['click_to_sale'] ?? null);

        $this->linkAttributed = self::int($this->sourceSplit['link'] ?? null) ?? 0;
        $this->codeAttributed = self::int($this->sourceSplit['code'] ?? null) ?? 0;
        $this->manualAttributed = self::int($this->sourceSplit['manual'] ?? null) ?? 0;
        $this->codeSharePercent = self::rate($this->sourceSplit['code_share_percent'] ?? null);
    }

    private static function rate(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
