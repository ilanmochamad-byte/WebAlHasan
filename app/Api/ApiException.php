<?php

declare(strict_types=1);

namespace App\Api;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private int $status,
        private array $details = []
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function details(): array
    {
        return $this->details;
    }
}
