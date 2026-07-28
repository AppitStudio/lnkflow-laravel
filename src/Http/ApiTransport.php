<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Http;

use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use LnkFlow\Laravel\Contracts\Transport;
use LnkFlow\Laravel\Exceptions\ConnectionException;
use Throwable;

final class ApiTransport implements Transport
{
    public const VERSION = '0.1.0-dev';

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly Factory $http,
        private readonly array $config,
        private readonly ?string $connection = null,
        private readonly int|string|null $team = null,
        private readonly string $purpose = 'api',
        private readonly ?ResponseMapper $mapper = null,
    ) {}

    public function forConnection(string $connection): Transport
    {
        return new self($this->http, $this->config, $connection, $this->team, $this->purpose, $this->mapper);
    }

    public function forTeam(int|string|null $team): Transport
    {
        return new self($this->http, $this->config, $this->connection, $team, $this->purpose, $this->mapper);
    }

    public function forPurpose(string $purpose): Transport
    {
        return new self($this->http, $this->config, $this->connection, $this->team, $purpose, $this->mapper);
    }

    public function send(
        string $method,
        string $path,
        array $query = [],
        array $json = [],
        array $headers = [],
        ?string $stableBusinessKey = null,
    ): array {
        $settings = $this->settings();
        $token = $this->token($settings);

        if ($token === null) {
            throw new ConnectionException('No LnkFlow API token is configured.');
        }

        $method = mb_strtoupper($method);
        $attempts = max(1, (int) ($settings['attempts'] ?? 3));
        $requestId = (string) Str::uuid();
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->request($settings, $token, $requestId, $headers)
                    ->send($method, $this->url($settings, $path), [
                        'query' => $query,
                        'json' => $json,
                    ]);
            } catch (LaravelConnectionException $exception) {
                if (! $this->mayRetry($method, null, $headers, $stableBusinessKey, $attempt, $attempts)) {
                    throw new ConnectionException(
                        'Unable to connect to the LnkFlow API.',
                        requestId: $requestId,
                    );
                }

                $this->pause($settings, $attempt, null);

                continue;
            }

            if (! $this->mayRetry($method, $response, $headers, $stableBusinessKey, $attempt, $attempts)) {
                break;
            }

            $this->pause($settings, $attempt, $response);
        }

        if (! $response instanceof Response) {
            throw new ConnectionException('Unable to connect to the LnkFlow API.', requestId: $requestId);
        }

        return ($this->mapper ?? new ResponseMapper)->map($response);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, string>  $headers
     */
    private function request(array $settings, string $token, string $requestId, array $headers): PendingRequest
    {
        $team = $this->team ?? ($settings['team'] ?? null);
        $baseHeaders = [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'User-Agent' => 'lnkflow-laravel/'.self::VERSION.' PHP/'.PHP_VERSION,
            'X-LnkFlow-SDK-Version' => self::VERSION,
            'X-LnkFlow-Request-Id' => $requestId,
        ];

        if (is_int($team) || (is_string($team) && $team !== '')) {
            $baseHeaders['X-LnkFlow-Team'] = (string) $team;
        }

        return $this->http
            ->withHeaders([...$baseHeaders, ...$headers])
            ->connectTimeout((int) ($settings['connect_timeout'] ?? 3))
            ->timeout((int) ($settings['timeout'] ?? 10));
    }

    /** @param array<string, mixed> $settings */
    private function token(array $settings): ?string
    {
        $preferred = match ($this->purpose) {
            'links' => $settings['link_token'] ?? null,
            'conversions', 'journeys' => $settings['conversion_token'] ?? null,
            default => null,
        };
        $token = is_string($preferred) && $preferred !== ''
            ? $preferred
            : ($settings['api_token'] ?? null);

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $connections = $this->config['connections'] ?? [];
        $name = $this->connection ?? ($this->config['default'] ?? 'default');
        $settings = is_array($connections) && is_array($connections[$name] ?? null)
            ? $connections[$name]
            : null;

        if (! is_array($settings)) {
            throw new ConnectionException("The LnkFlow connection [{$name}] is not configured.");
        }

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function url(array $settings, string $path): string
    {
        return mb_rtrim((string) ($settings['url'] ?? ''), '/').'/'.mb_ltrim($path, '/');
    }

    /** @param array<string, string> $headers */
    private function mayRetry(
        string $method,
        ?Response $response,
        array $headers,
        ?string $stableBusinessKey,
        int $attempt,
        int $attempts,
    ): bool {
        if ($attempt >= $attempts) {
            return false;
        }

        if ($method === 'POST'
            && ! array_key_exists('Idempotency-Key', $headers)
            && ($stableBusinessKey === null || $stableBusinessKey === '')) {
            return false;
        }

        if ($response === null) {
            return true;
        }

        return $response->status() === 408
            || $response->status() === 429
            || $response->serverError();
    }

    /** @param array<string, mixed> $settings */
    private function pause(array $settings, int $attempt, ?Response $response): void
    {
        $retryAfter = $response?->header('Retry-After');
        $milliseconds = $this->retryAfterMilliseconds($retryAfter)
            ?? min(5000, (int) ($settings['retry_base_milliseconds'] ?? 150) * (2 ** ($attempt - 1)) + random_int(0, 100));

        if ($milliseconds > 0) {
            usleep((int) ($milliseconds * 1000));
        }
    }

    private function retryAfterMilliseconds(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min(30_000, (int) $value * 1000);
        }

        try {
            $timestamp = strtotime($value);

            return $timestamp === false ? null : min(30_000, max(0, $timestamp - time()) * 1000);
        } catch (Throwable) {
            return null;
        }
    }
}
