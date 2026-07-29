<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class Website extends ApiObject
{
    public int $id;

    public string $name;

    public ?string $domain;

    public ?string $description;

    public ?int $defaultCustomDomainId;

    public ?string $defaultCustomDomain;

    public bool $active;

    public ?string $createdAt;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $this->name = self::string($raw['name'] ?? null) ?? '';
        $this->domain = self::string($raw['domain'] ?? null);
        $this->description = self::string($raw['description'] ?? null);
        $default = self::map($raw['default_custom_domain'] ?? null);
        $this->defaultCustomDomainId = self::int($default['id'] ?? null);
        $this->defaultCustomDomain = self::string($default['domain'] ?? null);
        $this->active = (bool) ($raw['is_active'] ?? false);
        $this->createdAt = self::string($raw['created_at'] ?? null);
    }
}
