<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Contracts;

use Illuminate\Database\Eloquent\Model;
use LnkFlow\Laravel\Data\LinkDefinition;

interface LinkableContent
{
    public function lnkFlowSourceKey(Model $model): string;

    /** @return iterable<LinkDefinition> */
    public function lnkFlowLinks(Model $model): iterable;
}
