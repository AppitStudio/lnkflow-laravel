<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Testing;

use Closure;
use LnkFlow\Laravel\Contracts\Transport;
use LnkFlow\Laravel\Http\ApiResponse;
use LnkFlow\Laravel\Support\Shape;

/**
 * A no-network transport for host application tests.
 *
 * Records every request and answers with a plausible response, so a host can
 * assert what its code would have sent without a LnkFlow account, a token, or
 * an internet connection.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{method: string, path: string, query: array<string, scalar|null>, json: array<string, mixed>, headers: array<string, string>, stable_key: string|null, connection: string|null, team: int|string|null, purpose: string}> */
    private array $requests = [];

    /** @var array<string, ApiResponse|array<string, mixed>|Closure> */
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
    ): ApiResponse {
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

        if ($response instanceof ApiResponse) {
            return $response;
        }

        return new ApiResponse(
            $method === 'POST' ? 201 : 200,
            Shape::map($response),
        );
    }

    /**
     * Stub a specific endpoint. Pass an ApiResponse to control status and
     * headers — that is how you exercise idempotent-replay handling.
     *
     * @param  ApiResponse|array<string, mixed>|Closure  $response
     */
    public function respond(string $method, string $path, ApiResponse|array|Closure $response): self
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
        if ($method === 'GET' && in_array($path, ['links', 'campaigns', 'websites', 'influencers', 'track/events', 'domains', 'search'], true)) {
            return ['data' => [], 'meta' => [], 'links' => []];
        }

        if ($method === 'GET' && str_ends_with($path, '/commissions')) {
            return ['data' => [], 'meta' => [], 'links' => []];
        }

        if ($path === 'browser-extension/bootstrap') {
            return ['data' => ['websites' => [], 'domains' => [], 'influencers' => [], 'teams' => []]];
        }

        if ($path === 'stats/conversions') {
            return ['data' => [
                'has_conversion_data' => false,
                'funnel' => ['clicks' => 0, 'leads' => 0, 'sales' => 0, 'revenue_cents' => 0, 'rates' => []],
                'series' => [],
                'source_split' => ['link' => 0, 'code' => 0, 'manual' => 0, 'code_share_percent' => 0.0],
            ], 'meta' => []];
        }

        if (str_contains($path, '/links') || $path === 'links/preview' || str_starts_with($path, 'links/')) {
            return ['data' => [
                'id' => 1,
                'campaign_id' => 1,
                'slug' => $this->text($json, 'slug', 'fake-link'),
                'short_url' => 'https://fake.mylnk.click/fake-link',
                'edge_status' => 'publishing',
                'is_active' => true,
                'conversion_tracking_enabled' => (bool) ($json['conversion_tracking_enabled'] ?? false),
                'auto_promo_code' => $json['auto_promo_code'] ?? null,
                ...$json,
            ]];
        }

        if ($path === 'campaigns' || str_starts_with($path, 'campaigns/')) {
            return ['data' => [
                'id' => 1,
                'name' => $this->text($json, 'name', 'Fake campaign'),
                'campaign_slug' => $this->text($json, 'campaign_slug', 'fake-campaign'),
                'slug' => 'fake-link',
                'is_active' => true,
                ...$json,
            ]];
        }

        return ['data' => ['id' => 1, ...$json]];
    }

    /**
     * A field echoed back from the request body, or the stub's own placeholder
     * when the host did not send one.
     *
     * @param  array<string, mixed>  $json
     */
    private function text(array $json, string $key, string $fallback): string
    {
        $value = $json[$key] ?? null;

        return is_scalar($value) ? (string) $value : $fallback;
    }
}
