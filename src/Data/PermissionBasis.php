<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * Why processing is permitted for a journey or conversion.
 *
 * A regional default can permit processing without falsely claiming that the
 * visitor gave explicit consent.
 */
enum PermissionBasis: string
{
    case ExplicitConsent = 'explicit_consent';
    case RegionalDefault = 'regional_default';
    case Denied = 'denied';
}
