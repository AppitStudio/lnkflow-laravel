<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Data;

use LnkFlow\Laravel\Http\ApiResponse;
use LnkFlow\Laravel\Support\Shape;

/**
 * Base class for every API read model.
 *
 * Typed properties cover what the API guarantees today; `$raw` always holds the
 * complete decoded payload so additive server fields stay reachable without an
 * SDK upgrade. `$response` is present on objects returned by a write, which is
 * how {@see replayed()} can tell a fresh create from an idempotent replay.
 */
abstract readonly class ApiObject
{
    public ?ApiResponse $response;

    /** @param array<string, mixed> $raw */
    public function __construct(public array $raw, ?ApiResponse $response = null)
    {
        $this->response = $response;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }

    /**
     * True when the server replayed a stored response for the request's
     * `Idempotency-Key` instead of writing again — i.e. this resource already
     * existed and nothing new was created.
     */
    public function replayed(): bool
    {
        return $this->response?->replayed() ?? false;
    }

    public function requestId(): ?string
    {
        return $this->response?->requestId();
    }

    protected static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * A nested object from the payload, empty when the server sent something
     * else — including the null an unloaded relation arrives as.
     *
     * @return array<string, mixed>
     */
    protected static function map(mixed $value): array
    {
        return Shape::map($value);
    }

    /**
     * A nested collection from the payload, with non-object rows dropped.
     *
     * @return list<array<string, mixed>>
     */
    protected static function rows(mixed $value): array
    {
        return Shape::rows($value);
    }
}
