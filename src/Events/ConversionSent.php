<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Events;

final readonly class ConversionSent
{
    public function __construct(
        public string $type,
        public string $businessId,
        public int|string|null $remoteId,
    ) {}
}
