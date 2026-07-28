<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Services;

use LnkFlow\Laravel\Contracts\Transport;

abstract class AbstractClient
{
    public function __construct(protected readonly Transport $transport) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function data(array $payload): array
    {
        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    protected function collection(array $payload): array
    {
        $data = $payload['data'] ?? [];

        return is_array($data)
            ? array_values(array_filter($data, is_array(...)))
            : [];
    }
}
