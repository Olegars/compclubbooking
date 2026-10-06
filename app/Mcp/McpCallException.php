<?php

namespace App\Mcp;

use RuntimeException;

class McpCallException extends RuntimeException
{
    public function __construct(string $message, int $code = -32602)
    {
        parent::__construct($message, $code);
    }
}
