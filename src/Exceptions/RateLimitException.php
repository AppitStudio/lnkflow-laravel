<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Exceptions;

final class RateLimitException extends LnkFlowException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        ?int $status = 429,
        ?string $requestId = null,
        ?string $errorCode = null,
        array $errors = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status, $requestId, $errorCode, $errors);
    }
}
