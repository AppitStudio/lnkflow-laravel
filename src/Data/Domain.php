<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * A custom (branded) domain.
 *
 * Only a domain where {@see $usable} is true can be attached to a link:
 * `is_active` alone does not mean the certificate has issued.
 */
final readonly class Domain extends ApiObject
{
    public int $id;

    public string $domain;

    public ?string $url;

    public bool $active;

    public bool $verified;

    public bool $usable;

    public ?string $sslStatus;

    public ?string $status;

    public ?string $createdAt;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->domain = self::string($raw['domain'] ?? null) ?? '';
        $this->url = self::string($raw['url'] ?? null);
        $this->active = (bool) ($raw['is_active'] ?? false);
        $this->verified = (bool) ($raw['is_verified'] ?? false);
        $this->usable = (bool) ($raw['is_usable'] ?? false);
        $this->sslStatus = self::string($raw['ssl_status'] ?? null);
        $this->status = self::string($raw['status'] ?? null);
        $this->createdAt = self::string($raw['created_at'] ?? null);
    }
}
