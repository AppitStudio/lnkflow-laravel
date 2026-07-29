<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Http\ApiResponse;

/**
 * A generic API object for endpoints the SDK does not model in more detail yet.
 * Everything the server returned is available through `$raw`.
 */
final readonly class Resource extends ApiObject
{
    public int|string|null $id;

    /** @param array<string, mixed> $raw */
    public function __construct(array $raw, ?ApiResponse $response = null)
    {
        parent::__construct($raw, $response);
        $id = $raw['id'] ?? null;
        $this->id = is_int($id) || is_string($id) ? $id : null;
    }
}
