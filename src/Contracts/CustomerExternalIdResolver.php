<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Contracts;

interface CustomerExternalIdResolver
{
    public function resolve(object $user): string;
}
