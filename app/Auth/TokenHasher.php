<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

final class TokenHasher
{
    public function __construct(private string $secret, private string $environment)
    {
    }

    public function hash(string $token): string
    {
        if ($this->secret === '' && $this->environment === 'production') {
            throw new RuntimeException('API_TOKEN_HASH_SECRET wajib dikonfigurasi pada environment produksi.');
        }

        return $this->secret === ''
            ? hash('sha256', $token)
            : hash_hmac('sha256', $token, $this->secret);
    }
}
