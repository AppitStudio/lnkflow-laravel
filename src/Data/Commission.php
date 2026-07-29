<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * One row of the influencer commission ledger.
 *
 * The ledger is reporting-only. LnkFlow never moves money: these rows record
 * what a commission rule computed, nothing more.
 */
final readonly class Commission extends ApiObject
{
    public int $id;

    public string $status;

    public int $commissionAmountCents;

    public int $saleAmountCents;

    public ?string $currency;

    public ?string $reason;

    public ?string $eventName;

    public ?string $occurredAt;

    public ?int $relatedCommissionId;

    public ?string $approvedAt;

    public ?string $createdAt;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->status = self::string($raw['status'] ?? null) ?? '';
        $this->commissionAmountCents = self::int($raw['commission_amount_cents'] ?? null) ?? 0;
        $this->saleAmountCents = self::int($raw['sale_amount_cents'] ?? null) ?? 0;
        $this->currency = self::string($raw['currency'] ?? null);
        $this->reason = self::string($raw['reason'] ?? null);
        $this->eventName = self::string($raw['event_name'] ?? null);
        $this->occurredAt = self::string($raw['occurred_at'] ?? null);
        $this->relatedCommissionId = self::int($raw['related_commission_id'] ?? null);
        $this->approvedAt = self::string($raw['approved_at'] ?? null);
        $this->createdAt = self::string($raw['created_at'] ?? null);
    }
}
