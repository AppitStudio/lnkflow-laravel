<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Contracts;

use Illuminate\Http\Request;
use LnkFlow\Laravel\Data\ConsentState;

interface ConsentResolver
{
    public function storage(Request $request): ConsentState;

    public function adUserData(Request $request): ConsentState;

    public function adPersonalization(Request $request): ConsentState;
}
