<?php

declare(strict_types=1);

namespace App\Psb;

use RuntimeException;

/**
 * Galat validasi/penyimpanan PSB admin dengan pesan per isian (redesain Tahap 3).
 */
final class PsbException extends RuntimeException
{
    /**
     * @param array<string, string> $fields isian => pesan
     */
    public function __construct(string $message, private array $fields = [], int $code = 422)
    {
        parent::__construct($message, $code);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->fields;
    }
}
