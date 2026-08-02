<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

/**
 * The consent signals attached to a journey touchpoint or a conversion.
 *
 * Everything defaults to `unknown`, which the platform treats as "no consent":
 * nothing is persisted and nothing is captured. A host must resolve real values
 * through its own CMP — see the ConsentResolver contract.
 */
final readonly class Consent implements Payload
{
    public function __construct(
        public ConsentState $storage = ConsentState::Unknown,
        public ConsentState $adUserData = ConsentState::Unknown,
        public ConsentState $adPersonalization = ConsentState::Unknown,
        public ?int $revision = null,
        public ?string $evidenceId = null,
        public ?PermissionBasis $permissionBasis = null,
        public ?int $policyRevision = null,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            self::state($raw['storage'] ?? null),
            self::state($raw['ad_user_data'] ?? null),
            self::state($raw['ad_personalization'] ?? null),
            is_numeric($raw['revision'] ?? null) ? (int) $raw['revision'] : null,
            is_string($raw['evidence_id'] ?? null) ? $raw['evidence_id'] : null,
            is_string($raw['permission_basis'] ?? null)
                ? PermissionBasis::tryFrom($raw['permission_basis'])
                : null,
            is_numeric($raw['policy_revision'] ?? null) ? (int) $raw['policy_revision'] : null,
        );
    }

    public function granted(): bool
    {
        return $this->storage === ConsentState::Granted
            && $this->permissionBasis !== PermissionBasis::Denied;
    }

    public function toArray(): array
    {
        return array_filter([
            'storage' => $this->storage->value,
            'ad_user_data' => $this->adUserData->value,
            'ad_personalization' => $this->adPersonalization->value,
            'revision' => $this->revision,
            'evidence_id' => $this->evidenceId,
            'permission_basis' => $this->permissionBasis?->value,
            'policy_revision' => $this->policyRevision,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function state(mixed $value): ConsentState
    {
        return is_string($value)
            ? (ConsentState::tryFrom($value) ?? ConsentState::Unknown)
            : ConsentState::Unknown;
    }
}
