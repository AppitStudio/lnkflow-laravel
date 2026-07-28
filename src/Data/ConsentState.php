<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

enum ConsentState: string
{
    case Granted = 'granted';
    case Denied = 'denied';
    case Unknown = 'unknown';
}
