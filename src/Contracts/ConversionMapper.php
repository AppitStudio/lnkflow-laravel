<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Contracts;

use LnkFlow\Laravel\Data\Lead;
use LnkFlow\Laravel\Data\Refund;
use LnkFlow\Laravel\Data\Sale;
use LnkFlow\Laravel\Services\JourneyContext;

interface ConversionMapper
{
    public function supports(object $event): bool;

    public function map(object $event, JourneyContext $context): Lead|Sale|Refund|null;
}
