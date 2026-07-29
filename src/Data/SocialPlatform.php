<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

/**
 * The social platforms LnkFlow knows about.
 *
 * Provided for convenience only. Every API surface that takes a platform also
 * accepts a plain string, so a platform added server-side keeps working without
 * an SDK upgrade.
 */
enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case TikTok = 'tiktok';
    case YouTube = 'youtube';
    case Facebook = 'facebook';
    case LinkedIn = 'linkedin';
    case X = 'x';
    case WhatsApp = 'whatsapp';
    case Email = 'email';
    case MetaAds = 'meta_ads';
    case GoogleAds = 'google_ads';
}
