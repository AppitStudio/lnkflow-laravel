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
}
