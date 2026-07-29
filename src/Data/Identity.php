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
        $this->id = self::int($raw['id'] ?? null) ?? 0;
        $capabilities = [];

        foreach (self::map($raw['capabilities'] ?? null) as $ability => $granted) {
            $capabilities[$ability] = (bool) $granted;
        }

        $this->capabilities = $capabilities;
    }

    public function can(string $ability): bool
    {
        return $this->capabilities[$ability] ?? false;
    }
}
