<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;

/**
 * The UTM parameters LnkFlow accepts on a campaign link.
 *
 * The API validates exactly these five keys on both create and update, so the
 * SDK validates them at authoring time. Without this, an unsupported key (say
 * `utm_id`) would be accepted by `CreateLink` and only rejected later, inside a
 * queued job, on the first content change.
 */
final readonly class Utm
{
    /** @var list<string> */
    public const KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * @param  array<string, string|null>  $utm
     * @return array<string, string|null>
     */
    public static function validate(array $utm): array
    {
        $unknown = array_values(array_diff(array_keys($utm), self::KEYS));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported UTM parameter(s) [%s]. LnkFlow accepts only [%s].',
                implode(', ', $unknown),
                implode(', ', self::KEYS),
            ));
        }

        return $utm;
    }
}
