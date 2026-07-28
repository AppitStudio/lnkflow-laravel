<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Contracts\CustomerExternalIdResolver;

final class DefaultCustomerExternalIdResolver implements CustomerExternalIdResolver
{
    public function resolve(object $user): string
    {
        $key = method_exists($user, 'getKey') ? $user->getKey() : null;
        $namespace = str((string) config('lnkflow.journeys.app_namespace', 'app'))->slug()->value();

        return $namespace.':'.(is_scalar($key) ? (string) $key : spl_object_id($user));
    }
}
