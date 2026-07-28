<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class Identity extends ApiObject
{
    public int $id;

    /** @var array<string, bool> */
    public array $capabilities;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $this->id = (int) ($raw['id'] ?? 0);
        $capabilities = $raw['capabilities'] ?? [];
        $this->capabilities = is_array($capabilities)
            ? array_map(static fn (mixed $value): bool => (bool) $value, $capabilities)
            : [];
    }

    public function can(string $ability): bool
    {
        return $this->capabilities[$ability] ?? false;
    }
}
