<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Http;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException as LaravelConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LnkFlow\Laravel\Contracts\Transport;
use LnkFlow\Laravel\Exceptions\ConnectionException;
use LnkFlow\Laravel\Exceptions\ErrorCode;
use LnkFlow\Laravel\Support\Shape;
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
        private readonly ?CacheFactory $cache = null,
    ) {}

    public function forConnection(string $connection): Transport
    {
        return new self($this->http, $this->config, $connection, $this->team, $this->purpose, $this->mapper, $this->cache);
    }

    public function forTeam(int|string|null $team): Transport
    {
        return new self($this->http, $this->config, $this->connection, $team, $this->purpose, $this->mapper, $this->cache);
    }

    public function forPurpose(string $purpose): Transport
    {
        return new self($this->http, $this->config, $this->connection, $this->team, $purpose, $this->mapper, $this->cache);
    }

    public function send(
        string $method,
        string $path,
        array $query = [],
        array $json = [],
        array $headers = [],
        ?string $stableBusinessKey = null,
    ): ApiResponse {
        $settings = $this->settings();
        $token = $this->token($settings);

        if ($token === null) {
            throw new ConnectionException('No LnkFlow API token is configured.');
        }

        $method = mb_strtoupper($method);
        $attempts = max(1, $this->integer($settings, 'attempts', 3));
        $requestId = (string) Str::uuid();
        $response = null;
        $startedAt = microtime(true);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->throttle($settings, $token, $method, $path);

            try {
                $response = $this->request($settings, $token, $requestId, $headers)
                    ->send($method, $this->url($settings, $path), [
                        'query' => $query,
                        'json' => $json,
                    ]);
            } catch (LaravelConnectionException) {
                if (! $this->mayRetry($method, null, $headers, $stableBusinessKey, $attempt, $attempts)) {
                    $this->log($settings, $method, $path, null, $attempt, $startedAt, $requestId, 'connection_failed');

                    throw new ConnectionException(
                        'Unable to connect to the LnkFlow API.',
                        requestId: $requestId,
                    );
                }

                $this->wait($this->backoffMilliseconds($settings, $attempt));

                continue;
            }

            if (! $this->mayRetry($method, $response, $headers, $stableBusinessKey, $attempt, $attempts)) {
                break;
            }

            $delay = $this->retryDelayMilliseconds($settings, $attempt, $response);

            // A `Retry-After` longer than the synchronous budget must not be
            // slept off in-process: stop retrying and let the typed exception
            // surface so a queued caller can release the job for that long.
            if ($delay === null) {
                break;
            }

            $this->wait($delay);
        }

        if (! $response instanceof Response) {
            throw new ConnectionException('Unable to connect to the LnkFlow API.', requestId: $requestId);
        }

        $this->log($settings, $method, $path, $response->status(), $attempt, $startedAt, $requestId, null);

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
            ->connectTimeout($this->integer($settings, 'connect_timeout', 3))
            ->timeout($this->integer($settings, 'timeout', 10));
    }

    /**
     * Least privilege first, then a documented fallback chain.
     *
     * A write purpose never falls back to a token that cannot perform it: a
     * conversion token has no `write` ability, so using it for a link create
     * would trade a clear configuration error for a 403 at runtime.
     *
     * The read-only purpose is the opposite case. `me`, `search`, `stats`, and
     * the workspace bundle are readable with any ability, so a correct
     * least-privilege setup — a link token and a conversion token, no general
     * token — must still reach them. Without this chain `lnkflow:doctor`
     * reports a healthy configuration and then fails its own connectivity
     * check.
     *
     * @param  array<string, mixed>  $settings
     */
    private function token(array $settings): ?string
    {
        $chain = match ($this->purpose) {
            'links' => ['link_token', 'api_token'],
            'conversions', 'journeys' => ['conversion_token', 'api_token'],
            default => ['api_token', 'link_token', 'conversion_token'],
        };

        foreach ($chain as $key) {
            $token = $settings[$key] ?? null;

            if (is_string($token) && $token !== '') {
                return $token;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $connections = $this->config['connections'] ?? null;
        $name = $this->connectionName();
        $settings = is_array($connections) ? ($connections[$name] ?? null) : null;

        if (! is_array($settings)) {
            throw new ConnectionException("The LnkFlow connection [{$name}] is not configured.");
        }

        return Shape::map($settings);
    }

    /**
     * A connection setting the SDK needs as a whole number.
     *
     * Published configuration is host-owned and arrives as `mixed`, so a value
     * that is not a number at all falls back to the documented default rather
     * than to whatever `(int)` would have made of it — a non-numeric `timeout`
     * casting to 0 means "wait forever", which is the opposite of the intent.
     *
     * @param  array<string, mixed>  $settings
     */
    private function integer(array $settings, string $key, int $default): int
    {
        $value = $settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    private function connectionName(): string
    {
        $default = $this->config['default'] ?? 'default';

        return $this->connection ?? (is_string($default) ? $default : 'default');
    }

    /** @param array<string, mixed> $settings */
    private function url(array $settings, string $path): string
    {
        $url = $settings['url'] ?? null;

        // Native trim, not mb_trim: the needle is a single ASCII byte, so there
        // is nothing multibyte to get wrong — and mb_ltrim/mb_rtrim are PHP 8.4
        // functions that this package, which supports 8.2, would be reaching for
        // through a polyfill it does not depend on directly.
        return rtrim(is_string($url) ? $url : '', '/').'/'.ltrim($path, '/');
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

        if ($response->status() === 409) {
            return $this->isIdempotencyInProgress($response);
        }

        return $response->status() === 408
            || $response->status() === 429
            || $response->serverError();
    }

    private function isIdempotencyInProgress(Response $response): bool
    {
        $json = $response->json();
        $code = is_array($json) ? ($json['code'] ?? null) : null;

        return is_string($code)
            && mb_strtoupper($code) === ErrorCode::IdempotencyInProgress->value;
    }

    /**
     * The delay before the next attempt, or null when the server asked for
     * longer than this process is allowed to block for.
     *
     * @param  array<string, mixed>  $settings
     */
    private function retryDelayMilliseconds(array $settings, int $attempt, Response $response): ?int
    {
        $cap = max(0, $this->integer($settings, 'retry_max_wait_milliseconds', 2000));
        $requested = $this->retryAfterMilliseconds($response->header('Retry-After'));

        if ($requested === null) {
            return min($cap, $this->backoffMilliseconds($settings, $attempt));
        }

        return $requested > $cap ? null : $requested;
    }

    /** @param array<string, mixed> $settings */
    private function backoffMilliseconds(array $settings, int $attempt): int
    {
        $cap = max(0, $this->integer($settings, 'retry_max_wait_milliseconds', 2000));
        $base = $this->integer($settings, 'retry_base_milliseconds', 150);

        return min($cap, $base * (2 ** ($attempt - 1)) + random_int(0, 100));
    }

    private function wait(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private function retryAfterMilliseconds(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value * 1000;
        }

        try {
            $timestamp = strtotime($value);

            return $timestamp === false ? null : max(0, $timestamp - time()) * 1000;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Stay inside the per-token request budget instead of firing straight into
     * server 429s. Deliberately fails open: if the budget is exhausted for
     * longer than the client may wait, the request proceeds and the server's
     * own 429 plus `Retry-After` becomes the authority.
     *
     * @param  array<string, mixed>  $settings
     */
    private function throttle(array $settings, string $token, string $method, string $path): void
    {
        $throttle = Shape::map($settings['throttle'] ?? null);

        if (($throttle['enabled'] ?? false) !== true) {
            return;
        }

        $store = $this->store($throttle);

        if (! $store instanceof CacheRepository) {
            return;
        }

        [$budget, $max] = $this->budget($throttle, $method, $path);

        if ($max === null) {
            return;
        }

        $window = (int) floor(time() / 60);
        $key = 'lnkflow:throttle:'
            .hash('sha256', $this->connectionName().'|'.$token).':'.$budget.':'.$window;

        $store->add($key, 0, 120);
        $used = $store->increment($key);

        if (! is_numeric($used) || (int) $used <= $max) {
            return;
        }

        $remaining = (($window + 1) * 60 - time()) * 1000;
        $cap = max(0, $this->integer($throttle, 'max_wait_milliseconds', 2000));

        $this->wait(min($cap, max(0, $remaining)));
    }

    /**
     * Which server budget this request counts against.
     *
     * The API has no single global limit. Creates are governed by a much
     * tighter per-user link-creation limiter than reads are, and conversion and
     * journey writes have their own far larger per-team budgets. Counting all
     * of them in one bucket would either throttle conversion reporting ten
     * times harder than the server does, or let a CMS backfill sail straight
     * past the create limit.
     *
     * A null budget means the SDK imposes no client-side limit, which is the
     * correct answer for endpoints the server does not throttle.
     *
     * @param  array<string, mixed>  $throttle
     * @return array{string, int|null}
     */
    private function budget(array $throttle, string $method, string $path): array
    {
        $budgets = is_array($throttle['budgets'] ?? null) ? $throttle['budgets'] : [];

        $name = match (true) {
            $method === 'POST' && $this->isCreate($path) => 'link_creation',
            $this->purpose === 'conversions' => 'conversions',
            $this->purpose === 'journeys' => 'journeys',
            default => 'default',
        };

        if (! array_key_exists($name, $budgets)) {
            $fallback = ['link_creation' => 20, 'conversions' => 600, 'journeys' => 600, 'default' => null];

            return [$name, $fallback[$name]];
        }

        $max = $budgets[$name];

        return [$name, is_numeric($max) ? max(1, (int) $max) : null];
    }

    private function isCreate(string $path): bool
    {
        $path = trim($path, '/');

        return $path === 'campaigns' || preg_match('#^campaigns/\\d+/links$#', $path) === 1;
    }

    /**
     * @param  array<string, mixed>  $throttle
     */
    private function store(array $throttle): ?CacheRepository
    {
        if (! $this->cache instanceof CacheFactory) {
            return null;
        }

        $name = $throttle['store'] ?? null;

        try {
            return $this->cache->store(is_string($name) && $name !== '' ? $name : null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Safe diagnostics only. Never the token, the request body, customer
     * details, or visitor/click identifiers.
     *
     * @param  array<string, mixed>  $settings
     */
    private function log(
        array $settings,
        string $method,
        string $path,
        ?int $status,
        int $attempt,
        float $startedAt,
        string $requestId,
        ?string $failure,
    ): void {
        $logging = $this->config['logging'] ?? [];

        if (! is_array($logging) || ($logging['enabled'] ?? false) !== true) {
            return;
        }

        $channel = $logging['channel'] ?? null;
        $context = [
            'connection' => $this->connectionName(),
            'purpose' => $this->purpose,
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'attempt' => $attempt,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request_id' => $requestId,
            'team' => $this->team ?? ($settings['team'] ?? null),
        ];

        if ($failure !== null) {
            $context['failure'] = $failure;
        }

        Log::channel(is_string($channel) && $channel !== '' ? $channel : null)
            ->info('lnkflow.api', $context);
    }
}
