<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Http;

use Illuminate\Http\Client\Response;
use LnkFlow\Laravel\Exceptions\AuthenticationException;
use LnkFlow\Laravel\Exceptions\AuthorizationException;
use LnkFlow\Laravel\Exceptions\ConflictException;
use LnkFlow\Laravel\Exceptions\LnkFlowException;
use LnkFlow\Laravel\Exceptions\NotFoundException;
use LnkFlow\Laravel\Exceptions\RateLimitException;
use LnkFlow\Laravel\Exceptions\ServerException;
use LnkFlow\Laravel\Exceptions\ValidationException;
use LnkFlow\Laravel\Support\Shape;

final class ResponseMapper
{
    /**
     * Headers retained on a successful response. Everything else is discarded
     * so that no incidental server detail reaches logs, snapshots, or fixtures.
     *
     * @var list<string>
     */
    private const RETAINED_HEADERS = [
        'x-lnkflow-request-id',
        'idempotent-replayed',
        'retry-after',
    ];

    public function map(Response $response): ApiResponse
    {
        if ($response->successful()) {
            return new ApiResponse(
                $response->status(),
                Shape::map($response->json()),
                $this->headers($response),
                $response->body(),
            );
        }

        $payload = Shape::map($response->json());
        $message = is_string($payload['message'] ?? null)
            ? $payload['message']
            : 'The LnkFlow API request failed.';
        $code = is_string($payload['code'] ?? null) ? $payload['code'] : null;
        $requestId = $response->header('X-LnkFlow-Request-Id');
        $errors = $this->errors($payload['errors'] ?? []);
        $status = $response->status();

        throw match ($status) {
            401 => new AuthenticationException($message, $status, $requestId, $code, $errors),
            403 => new AuthorizationException($message, $status, $requestId, $code, $errors),
            404 => new NotFoundException($message, $status, $requestId, $code, $errors),
            409 => new ConflictException($message, $status, $requestId, $code, $errors),
            422 => new ValidationException($message, $status, $requestId, $code, $errors),
            429 => new RateLimitException(
                $message,
                $status,
                $requestId,
                $code,
                $errors,
                $this->retryAfter($response->header('Retry-After')),
            ),
            default => $status >= 500
                ? new ServerException($message, $status, $requestId, $code, $errors)
                : new LnkFlowException($message, $status, $requestId, $code, $errors),
        };
    }

    /** @return array<string, string> */
    private function headers(Response $response): array
    {
        $retained = [];

        foreach (self::RETAINED_HEADERS as $name) {
            $value = $response->header($name);

            if ($value !== '') {
                $retained[$name] = $value;
            }
        }

        return $retained;
    }

    /** @return array<string, list<string>> */
    private function errors(mixed $errors): array
    {
        if (! is_array($errors)) {
            return [];
        }

        $normalized = [];

        foreach ($errors as $field => $messages) {
            if (! is_string($field)) {
                continue;
            }

            $normalized[$field] = array_values(array_filter(
                is_array($messages) ? $messages : [$messages],
                is_string(...),
            ));
        }

        return $normalized;
    }

    private function retryAfter(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }
}
