<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use InvalidArgumentException;
use LnkFlow\Laravel\Contracts\Payload;

final readonly class UpdateWebsite implements Payload
{
    /** @var list<string> */
    private const ALLOWED = ['name', 'domain', 'description', 'is_active'];

    /** @param array<string, mixed> $changes */
    public function __construct(public array $changes)
    {
        $unsupported = array_values(array_diff(array_keys($changes), self::ALLOWED));

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                'Unsupported website update field(s) ['.implode(', ', $unsupported).'].',
            );
        }
    }

    public function toArray(): array
    {
        return $this->changes;
    }
}
