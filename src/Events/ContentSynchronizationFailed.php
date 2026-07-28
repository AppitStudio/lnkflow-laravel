<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Events;

final readonly class ContentSynchronizationFailed
{
    public function __construct(
        public int $mappingId,
        public string $errorClass,
    ) {}
}
