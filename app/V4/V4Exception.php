<?php
declare(strict_types=1);
namespace App\V4;
final class V4Exception extends \RuntimeException {
    public function __construct(string $message, public int $status=422) { parent::__construct($message); }
}
