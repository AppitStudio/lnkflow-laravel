<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Exceptions;

use LnkFlow\Laravel\Http\ApiTransport;

/**
 * The machine-readable `code` LnkFlow returns on every API failure.
 *
 * Mirrors `App\Exceptions\Api\ApiErrorCode` in the SaaS application and the
 * `Error.code` enum in `docs/openapi/lnkflow-v1.yaml`. Branch on this, never on
 * `LnkFlowException::getMessage()` — the message is English prose written for a
 * human and it is reworded freely.
 *
 * The server's set grows additively, so a code this package does not know about
 * is not an error: {@see LnkFlowException::$errorCode} always keeps the raw
 * string, and {@see LnkFlowException::code()} simply returns null for it. Fall
 * back to the exception type, which is derived from the HTTP status.
 */
enum ErrorCode: string
{
    case Unauthenticated = 'UNAUTHENTICATED';
    case TokenMissingAbility = 'TOKEN_MISSING_ABILITY';
    case TeamRoleReadOnly = 'TEAM_ROLE_READ_ONLY';
    case TeamInaccessible = 'TEAM_INACCESSIBLE';
    case Forbidden = 'FORBIDDEN';
    case BadRequest = 'BAD_REQUEST';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case Conflict = 'CONFLICT';
    case ValidationFailed = 'VALIDATION_FAILED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case RateLimited = 'RATE_LIMITED';
    case ServerError = 'SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case IdempotencyInProgress = 'IDEMPOTENCY_IN_PROGRESS';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case InvalidIdempotencyKey = 'INVALID_IDEMPOTENCY_KEY';

    /**
     * Whether reissuing the same request can plausibly succeed.
     *
     * `IdempotencyInProgress` is deliberately absent: the first request is
     * still running and holds the key, so an immediate retry only collides
     * again. {@see ApiTransport} handles it separately, with a wait.
     */
    public function transient(): bool
    {
        return match ($this) {
            self::RateLimited, self::ServerError, self::ServiceUnavailable => true,
            default => false,
        };
    }

    /**
     * Whether the failure is a credential or permission problem the end user
     * has to fix — a new token, a different team, or a role change. Nothing an
     * integration retries its way out of.
     */
    public function requiresUserAction(): bool
    {
        return match ($this) {
            self::Unauthenticated,
            self::TokenMissingAbility,
            self::TeamRoleReadOnly,
            self::TeamInaccessible,
            self::Forbidden => true,
            default => false,
        };
    }
}
