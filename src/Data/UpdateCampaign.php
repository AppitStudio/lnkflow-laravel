<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

/**
 * A partial campaign update.
 *
 * `slug` is deliberately not updatable here. `PATCH /campaigns/{id}` forwards a
 * slug into the campaign's primary link as well, which rewrites the live short
 * URL — every already-shared link would start 404ing. Rename the link
 * explicitly through `links()->update()` if that is really what you want.
 *
 * `is_active` is forwarded to the primary link too, so deactivating a campaign
 * also pauses its primary link. That one is intended.
 */
final readonly class UpdateCampaign implements Payload
{
    /** @var list<string> */
    private const ALLOWED = [
        'name', 'title', 'description', 'default_destination_url',
        'default_custom_domain_id', 'starts_at', 'ends_at', 'website_id', 'is_active',
    ];

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        if (array_key_exists('slug', $changes)) {
            throw new InvalidArgumentException(
                'Updating a campaign slug also rewrites the primary link slug and breaks every '
                .'short URL already shared. Update the link explicitly with '
                .'links()->update($linkId, new UpdateLink([\'slug\' => ...])) if that is intended.',
            );
        }

        $unsupported = array_values(array_diff(array_keys($changes), self::ALLOWED));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Unsupported campaign update field(s) ['.implode(', ', $unsupported).'].',
            );
        }
    }

    public function toArray(): array
    {
        return $this->changes;
    }
}
