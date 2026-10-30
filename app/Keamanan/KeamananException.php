<?php

declare(strict_types=1);

namespace App\Keamanan;

use RuntimeException;

final class KeamananException extends RuntimeException
{
    public function __construct(string $message, private int $status = 403)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
