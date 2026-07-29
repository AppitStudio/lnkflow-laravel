<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Http;

use LnkFlow\Laravel\Support\Shape;

/**
 * A successful LnkFlow API response.
 *
 * Failures never reach this object: {@see ResponseMapper} throws a typed
 * exception instead. Response headers are retained because several are
 * load-bearing for callers — `Idempotent-Replayed` distinguishes a fresh
 * create from a replayed one, and `X-LnkFlow-Request-Id` correlates support
 * requests. Header names are normalized to lowercase.
 */
final readonly class ApiResponse
{
    /**
     * @param  array<string, mixed>  $body  the decoded JSON body, empty for non-JSON responses
     * @param  array<string, string>  $headers
     * @param  string  $contents  the raw response body, for endpoints that return CSV rather than JSON
     */
    public function __construct(
        public int $status,
        public array $body = [],
        public array $headers = [],
        public string $contents = '',
    ) {}

    /**
     * The `data` envelope of a single-resource response.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return Shape::map($this->body['data'] ?? null);
    }

    /**
     * The `data` envelope of a collection response, with non-array rows dropped.
     *
     * @return list<array<string, mixed>>
     */
    public function collection(): array
    {
        return Shape::rows($this->body['data'] ?? null);
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return Shape::map($this->body['meta'] ?? null);
    }

    /** @return array<string, mixed> */
    public function links(): array
    {
        return Shape::map($this->body['links'] ?? null);
    }

    public function header(string $name): ?string
    {
        return $this->headers[mb_strtolower($name)] ?? null;
    }

    public function requestId(): ?string
    {
        return $this->header('X-LnkFlow-Request-Id');
    }

    /**
     * Whether the server replayed a previously stored response for this
     * `Idempotency-Key` instead of performing a new write.
     */
    public function replayed(): bool
    {
        $value = $this->header('Idempotent-Replayed');

        return $value !== null && in_array(mb_strtolower($value), ['1', 'true', 'yes'], true);
    }
}
