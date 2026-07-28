<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

final readonly class Resource extends ApiObject
{
    public int|string|null $id;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw)
    {
        parent::__construct($raw);
        $id = $raw['id'] ?? null;
        $this->id = is_int($id) || is_string($id) ? $id : null;
    }
}
