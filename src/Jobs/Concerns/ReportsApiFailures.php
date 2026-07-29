<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Jobs\Concerns;

use Closure;
use LnkFlow\Laravel\Exceptions\AuthenticationException;
use LnkFlow\Laravel\Exceptions\AuthorizationException;
use LnkFlow\Laravel\Exceptions\ConflictException;
use LnkFlow\Laravel\Exceptions\NotFoundException;
use LnkFlow\Laravel\Exceptions\RateLimitException;
use LnkFlow\Laravel\Exceptions\ValidationException;

/**
 * The retry policy every LnkFlow API job shares.
 *
 * Two rules do the work. A permanent failure — a bad payload, a token without
 * the right ability, a resource that is not there — fails immediately instead
 * of burning five attempts over eight minutes on an outcome that cannot change.
 * A rate limit releases the job for exactly as long as the server asked,
 * instead of blocking a worker on `sleep`.
 */
trait ReportsApiFailures
{
    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 300];

    /** @param Closure(): mixed $operation */
    protected function callApi(Closure $operation): void
    {
        try {
            $operation();
        } catch (RateLimitException $exception) {
            $this->release(max(1, $exception->retryAfter ?? 60));
        } catch (
            AuthenticationException|
            AuthorizationException|
            ConflictException|
            NotFoundException|
            ValidationException $exception
        ) {
            $this->fail($exception);
        }
    }
}
