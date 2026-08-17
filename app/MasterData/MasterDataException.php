<?php

declare(strict_types=1);

namespace App\MasterData;

use RuntimeException;

final class MasterDataException extends RuntimeException
{
    public function __construct(string $message, private array $errors = [])
    {
        parent::__construct($message);
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

