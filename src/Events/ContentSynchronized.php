<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Events;

final readonly class ContentSynchronized
{
    public function __construct(
        public int $mappingId,
        public int $remoteLinkId,
    ) {}
}
