<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class Campaign extends ApiObject
{
    public int $id;

    public string $name;

    public string $slug;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = (int) ($raw['id'] ?? 0);
        $this->name = (string) ($raw['name'] ?? '');
        $this->slug = (string) ($raw['slug'] ?? '');
    }
}
