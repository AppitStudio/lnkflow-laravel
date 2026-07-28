<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Contracts;

interface Payload
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
