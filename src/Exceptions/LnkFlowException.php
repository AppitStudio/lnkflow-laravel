<?php

declare(strict_types=1);

namespace LnkFlow\Laravel\Exceptions;

use RuntimeException;

class LnkFlowException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
        public readonly ?string $errorCode = null,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status ?? 0);
    }

    /**
     * The failure as a typed code, or null when the server sent one this
     * package release does not know yet. The raw string is always available on
     * {@see self::$errorCode}; the codes grow additively, so an unrecognised
     * one means "newer server", not "broken response".
     */
    public function code(): ?ErrorCode
    {
        return $this->errorCode === null ? null : ErrorCode::tryFrom($this->errorCode);
    }

    /**
     * Whether this failure is one of the given codes. Use it instead of
     * matching on {@see self::getMessage()}, which is prose and not a contract.
     */
    public function is(ErrorCode ...$codes): bool
    {
        $code = $this->code();

        return $code !== null && in_array($code, $codes, true);
    }
}
