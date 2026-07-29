<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Contracts\Payload;

final readonly class CreateWebsite implements Payload
{
    public function __construct(
        public string $name,
        public ?string $domain = null,
        public ?string $description = null,
        public ?bool $active = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'domain' => $this->domain,
            'description' => $this->description,
            'is_active' => $this->active,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
