<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Contracts;

use LnkFlow\Laravel\Http\ApiResponse;

interface Transport
{
    public function forConnection(string $connection): self;

    public function forTeam(int|string|null $team): self;

    public function forPurpose(string $purpose): self;

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     */
    public function send(
        string $method,
        string $path,
        array $query = [],
        array $json = [],
        array $headers = [],
        ?string $stableBusinessKey = null,
    ): ApiResponse;
}
