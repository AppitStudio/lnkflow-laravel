<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Testing;

use Closure;
use LnkFlow\Laravel\Contracts\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{method: string, path: string, query: array<string, scalar|null>, json: array<string, mixed>, headers: array<string, string>, stable_key: string|null, connection: string|null, team: int|string|null, purpose: string}> */
    private array $requests = [];

    /** @var array<string, array<string, mixed>|Closure> */
    private array $responses = [];

    public function __construct(
        private ?string $connection = null,
        private int|string|null $team = null,
        private string $purpose = 'api',
        private ?self $root = null,
    ) {
        $this->root ??= $this;
    }

    public function forConnection(string $connection): Transport
    {
        return new self($connection, $this->team, $this->purpose, $this->root);
    }

    public function forTeam(int|string|null $team): Transport
    {
        return new self($this->connection, $team, $this->purpose, $this->root);
    }

    public function forPurpose(string $purpose): Transport
    {
        return new self($this->connection, $this->team, $purpose, $this->root);
    }

    public function send(
        string $method,
        string $path,
        array $query = [],
        array $json = [],
        array $headers = [],
        ?string $stableBusinessKey = null,
    ): array {
        $root = $this->root ?? $this;
        $root->requests[] = [
            'method' => mb_strtoupper($method),
            'path' => $path,
            'query' => $query,
            'json' => $json,
            'headers' => $headers,
            'stable_key' => $stableBusinessKey,
            'connection' => $this->connection,
            'team' => $this->team,
            'purpose' => $this->purpose,
        ];

        $key = mb_strtoupper($method).' '.$path;
        $response = $root->responses[$key] ?? $this->defaultResponse($method, $path, $json);

        if ($response instanceof Closure) {
            $response = $response(end($root->requests));
        }

        return is_array($response) ? $response : [];
    }

    /** @param array<string, mixed>|Closure $response */
    public function respond(string $method, string $path, array|Closure $response): self
    {
        $root = $this->root ?? $this;
        $root->responses[mb_strtoupper($method).' '.$path] = $response;

        return $this;
    }

    /** @return list<array<string, mixed>> */
    public function requests(): array
    {
        return ($this->root ?? $this)->requests;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function defaultResponse(string $method, string $path, array $json): array
    {
        if ($method === 'GET' && in_array($path, ['links', 'campaigns', 'websites', 'influencers', 'track/events'], true)) {
            return ['data' => [], 'meta' => [], 'links' => []];
        }

        if (str_contains($path, '/links') || $path === 'links/preview' || str_starts_with($path, 'links/')) {
            return ['data' => [
                'id' => 1,
                'campaign_id' => 1,
                'slug' => (string) ($json['slug'] ?? 'fake-link'),
                'short_url' => 'https://fake.mylnk.click/fake-link',
                'edge_status' => 'publishing',
                'conversion_tracking_enabled' => (bool) ($json['conversion_tracking_enabled'] ?? false),
                'auto_promo_code' => $json['auto_promo_code'] ?? null,
                ...$json,
            ]];
        }

        if ($path === 'campaigns' || str_starts_with($path, 'campaigns/')) {
            return ['data' => [
                'id' => 1,
                'name' => (string) ($json['name'] ?? 'Fake campaign'),
                'slug' => (string) ($json['campaign_slug'] ?? 'fake-campaign'),
                ...$json,
            ]];
        }

        return ['data' => ['id' => 1, ...$json]];
    }
}
