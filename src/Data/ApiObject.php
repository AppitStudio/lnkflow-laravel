<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

abstract readonly class ApiObject
{
    /** @param array<string, mixed> $raw */
    public function __construct(public array $raw) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }
}
