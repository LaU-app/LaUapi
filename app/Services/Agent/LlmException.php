<?php

namespace App\Services\Agent;

use RuntimeException;

class LlmException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly ?int $httpStatus = null)
    {
        parent::__construct($message);
    }
}
