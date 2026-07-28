<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use Illuminate\Http\Request;
use LnkFlow\Laravel\Contracts\ConsentResolver;
use LnkFlow\Laravel\Data\ConsentState;

final class DefaultConsentResolver implements ConsentResolver
{
    public function storage(Request $request): ConsentState
    {
        return ConsentState::Unknown;
    }

    public function adUserData(Request $request): ConsentState
    {
        return ConsentState::Unknown;
    }

    public function adPersonalization(Request $request): ConsentState
    {
        return ConsentState::Unknown;
    }
}
